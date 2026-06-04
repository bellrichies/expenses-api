<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    // ── Observer correctness ────────────────────────────────────────────────

    public function test_creating_expense_records_audit_log_with_null_old(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/expenses', [
            'title' => 'Coffee', 'amount' => 4.50, 'category' => 'Food',
        ])->assertCreated();

        $log = AuditLog::where('action', 'create')->latest()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->changes['old']);
        $this->assertEquals('Coffee', $log->changes['new']['title']);
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals($user->company_id, $log->company_id);
    }

    public function test_updating_expense_records_only_changed_attributes(): void
    {
        $admin   = User::factory()->create(['role' => UserRole::Admin]);
        $expense = Expense::factory()->create([
            'company_id' => $admin->company_id,
            'user_id'    => $admin->id,
            'title'      => 'Original title',
            'amount'     => 200,
            'category'   => 'Office',
        ]);
        Sanctum::actingAs($admin);

        // Update only the amount.
        $this->putJson("/api/expenses/{$expense->id}", ['amount' => 300])->assertOk();

        $log = AuditLog::where('model_id', $expense->id)
            ->where('action', 'update')
            ->latest()
            ->first();

        $this->assertNotNull($log);
        // Only 'amount' should appear in the diff, not untouched fields.
        $this->assertArrayHasKey('amount', $log->changes['old']);
        $this->assertArrayHasKey('amount', $log->changes['new']);
        $this->assertArrayNotHasKey('title', $log->changes['old']);
        $this->assertEquals(200, (float) $log->changes['old']['amount']);
        $this->assertEquals(300, (float) $log->changes['new']['amount']);
    }

    public function test_deleting_expense_records_audit_log_with_null_new(): void
    {
        $admin   = User::factory()->create(['role' => UserRole::Admin]);
        $expense = Expense::factory()->create([
            'company_id' => $admin->company_id,
            'user_id'    => $admin->id,
            'title'      => 'To be deleted',
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/expenses/{$expense->id}")->assertOk();

        $log = AuditLog::where('model_id', $expense->id)
            ->where('action', 'delete')
            ->first();

        $this->assertNotNull($log);
        $this->assertNull($log->changes['new']);
        $this->assertEquals('To be deleted', $log->changes['old']['title']);
    }

    public function test_observer_fires_even_when_expense_modified_via_tinker_context(): void
    {
        // Simulates update without an HTTP request (no Auth::id() available).
        $user    = User::factory()->create();
        $expense = Expense::factory()->create([
            'company_id' => $user->company_id,
            'user_id'    => $user->id,
        ]);

        $beforeCount = AuditLog::count();

        // Direct Eloquent update — no Sanctum, no HTTP request.
        $expense->update(['amount' => 999.99]);

        $this->assertEquals($beforeCount + 1, AuditLog::count());

        $log = AuditLog::where('model_id', $expense->id)->where('action', 'update')->first();
        $this->assertNotNull($log);
        // Falls back to expense.user_id when Auth::id() is null.
        $this->assertEquals($expense->user_id, $log->user_id);
    }

    // ── API access control ───────────────────────────────────────────────────

    public function test_audit_log_index_requires_admin_role(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        Sanctum::actingAs($employee);

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_manager_cannot_access_audit_logs(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        Sanctum::actingAs($manager);

        $this->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_admin_can_list_audit_logs_for_own_company(): void
    {
        $admin   = User::factory()->create(['role' => UserRole::Admin]);
        $expense = Expense::factory()->create([
            'company_id' => $admin->company_id,
            'user_id'    => $admin->id,
        ]);
        // Force an update log entry.
        Sanctum::actingAs($admin);
        $this->putJson("/api/expenses/{$expense->id}", ['amount' => 50])->assertOk();

        $response = $this->getJson('/api/audit-logs')->assertOk();
        $this->assertGreaterThan(0, $response->json('meta.total'));

        foreach ($response->json('data') as $log) {
            $this->assertEquals($admin->company_id, AuditLog::find($log['id'])->company_id);
        }
    }

    public function test_audit_log_list_can_be_filtered_by_action(): void
    {
        $admin   = User::factory()->create(['role' => UserRole::Admin]);
        $expense = Expense::factory()->create([
            'company_id' => $admin->company_id,
            'user_id'    => $admin->id,
        ]);
        Sanctum::actingAs($admin);
        $this->putJson("/api/expenses/{$expense->id}", ['amount' => 60]);

        $response = $this->getJson('/api/audit-logs?action=update')->assertOk();
        foreach ($response->json('data') as $log) {
            $this->assertEquals('update', $log['action']);
        }
    }

    public function test_cross_company_audit_log_id_returns_404(): void
    {
        // Create an audit log belonging to another company.
        $otherUser = User::factory()->create();
        $expense   = Expense::factory()->create([
            'company_id' => $otherUser->company_id,
            'user_id'    => $otherUser->id,
        ]);
        $otherLog = AuditLog::where('model_id', $expense->id)->first();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/audit-logs/{$otherLog->id}")->assertNotFound();
    }

    // ── Audit log is append-only (no PUT/DELETE routes) ──────────────────────

    public function test_audit_log_has_no_write_routes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $log = AuditLog::factory()->create([
            'user_id'    => $admin->id,
            'company_id' => $admin->company_id,
        ]);

        // Audit-log routes only support GET — write methods return 405 Method Not Allowed.
        $this->putJson("/api/audit-logs/{$log->id}", ['action' => 'tampered'])->assertStatus(405);
        $this->deleteJson("/api/audit-logs/{$log->id}")->assertStatus(405);
    }
}
