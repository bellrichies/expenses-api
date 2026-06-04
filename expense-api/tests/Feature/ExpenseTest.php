<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    // ── Create ─────────────────────────────────────────────────────────────

    public function test_employee_can_create_expense_scoped_to_their_company(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        Sanctum::actingAs($employee);

        $this->postJson('/api/expenses', [
            'title'    => 'Taxi',
            'amount'   => 20,
            'category' => 'Travel',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Taxi');

        $this->assertDatabaseHas('expenses', [
            'title'      => 'Taxi',
            'company_id' => $employee->company_id,
            'user_id'    => $employee->id,
        ]);
    }

    public function test_expense_creation_fails_without_required_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/expenses', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['title', 'amount', 'category']]);
    }

    public function test_unauthenticated_user_cannot_create_expense(): void
    {
        $this->postJson('/api/expenses', [
            'title' => 'Test', 'amount' => 10, 'category' => 'Office',
        ])->assertUnauthorized();
    }

    // ── Update (role-gated: Manager|Admin) ──────────────────────────────────

    public function test_employee_cannot_update_expense(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        $expense  = Expense::factory()->create([
            'company_id' => $employee->company_id,
            'user_id'    => $employee->id,
        ]);
        Sanctum::actingAs($employee);

        $this->putJson("/api/expenses/{$expense->id}", ['amount' => 99])
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_manager_can_update_expense_in_same_company(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $expense = Expense::factory()->create([
            'company_id' => $manager->company_id,
            'user_id'    => $manager->id,
            'amount'     => 50,
        ]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/expenses/{$expense->id}", ['amount' => 75])
            ->assertOk()
            ->assertJsonPath('data.amount', 75); // JSON integer, not float literal
    }

    public function test_admin_can_update_any_expense_in_same_company(): void
    {
        $admin   = User::factory()->create(['role' => UserRole::Admin]);
        $expense = Expense::factory()->create([
            'company_id' => $admin->company_id,
            'user_id'    => $admin->id,
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/expenses/{$expense->id}", ['title' => 'Updated Title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated Title');
    }

    // ── Delete (Admin only) ─────────────────────────────────────────────────

    public function test_manager_cannot_delete_expense(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $expense = Expense::factory()->create([
            'company_id' => $manager->company_id,
            'user_id'    => $manager->id,
        ]);
        Sanctum::actingAs($manager);

        $this->deleteJson("/api/expenses/{$expense->id}")
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_delete_expense(): void
    {
        $admin   = User::factory()->create(['role' => UserRole::Admin]);
        $expense = Expense::factory()->create([
            'company_id' => $admin->company_id,
            'user_id'    => $admin->id,
        ]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    // ── Multi-tenant isolation ──────────────────────────────────────────────

    public function test_cross_company_expense_access_returns_404(): void
    {
        $userA    = User::factory()->create();
        $otherCo  = Company::factory()->create();
        $foreign  = Expense::factory()->create([
            'company_id' => $otherCo->id,
            'user_id'    => User::factory()->create(['company_id' => $otherCo->id])->id,
        ]);
        Sanctum::actingAs($userA);

        $this->getJson("/api/expenses/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/expenses/{$foreign->id}", ['amount' => 1])->assertNotFound();
    }

    public function test_expense_list_is_scoped_to_authenticated_company(): void
    {
        $userA = User::factory()->create();
        Expense::factory()->count(3)->create([
            'company_id' => $userA->company_id,
            'user_id'    => $userA->id,
        ]);

        // Expenses belonging to a different company.
        $otherUser = User::factory()->create();
        Expense::factory()->count(2)->create([
            'company_id' => $otherUser->company_id,
            'user_id'    => $otherUser->id,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/expenses')->assertOk();
        $this->assertEquals(3, $response->json('meta.total'));
    }

    // ── Audit logging ───────────────────────────────────────────────────────

    public function test_updating_expense_writes_audit_log_with_old_and_new_values(): void
    {
        $admin   = User::factory()->create(['role' => UserRole::Admin]);
        $expense = Expense::factory()->create([
            'company_id' => $admin->company_id,
            'user_id'    => $admin->id,
            'amount'     => 100,
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/expenses/{$expense->id}", ['amount' => 150])->assertOk();

        $log = AuditLog::where('model_id', $expense->id)
            ->where('action', 'update')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(100, (float) $log->changes['old']['amount']);
        $this->assertEquals(150, (float) $log->changes['new']['amount']);
        $this->assertEquals($admin->id, $log->user_id);
        $this->assertEquals($admin->company_id, $log->company_id);
    }

    public function test_creating_expense_writes_audit_log_with_null_old_values(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        Sanctum::actingAs($employee);

        $this->postJson('/api/expenses', [
            'title' => 'Bus ticket', 'amount' => 5, 'category' => 'Travel',
        ])->assertCreated();

        $log = AuditLog::where('action', 'create')->latest()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->changes['old']);
        $this->assertEquals('Bus ticket', $log->changes['new']['title']);
    }

    // ── N+1 prevention ─────────────────────────────────────────────────────

    public function test_expense_list_executes_constant_queries_regardless_of_row_count(): void
    {
        $user = User::factory()->create();
        Expense::factory()->count(25)->create([
            'company_id' => $user->company_id,
            'user_id'    => $user->id,
        ]);
        Sanctum::actingAs($user);

        DB::enableQueryLog();
        $this->getJson('/api/expenses')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // expenses + count + eager(users) + eager(companies) = 4 queries;
        // well under the 8 ceiling even with any framework overhead.
        $this->assertLessThan(8, $queryCount, "Expected <8 queries, got {$queryCount}");
    }

    // ── Filtering & pagination ──────────────────────────────────────────────

    public function test_expense_list_can_be_filtered_by_category(): void
    {
        $user = User::factory()->create();
        Expense::factory()->count(2)->create([
            'company_id' => $user->company_id, 'user_id' => $user->id, 'category' => 'Travel',
        ]);
        Expense::factory()->count(3)->create([
            'company_id' => $user->company_id, 'user_id' => $user->id, 'category' => 'Office',
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/expenses?category=Travel')->assertOk();
        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_expense_list_respects_per_page_parameter(): void
    {
        $user = User::factory()->create();
        Expense::factory()->count(10)->create([
            'company_id' => $user->company_id, 'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/expenses?per_page=3')->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(10, $response->json('meta.total'));
    }
}
