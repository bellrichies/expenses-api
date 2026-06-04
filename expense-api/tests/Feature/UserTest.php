<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendWelcomeEmail;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    // ── Access control: Admin-only ──────────────────────────────────────────

    public function test_employee_cannot_list_users(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        Sanctum::actingAs($employee);

        $this->getJson('/api/users')->assertForbidden()->assertJsonPath('success', false);
    }

    public function test_manager_cannot_list_users(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        Sanctum::actingAs($manager);

        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_non_admin_cannot_create_user(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        Sanctum::actingAs($employee);

        $this->postJson('/api/users', [
            'name' => 'New', 'email' => 'new@test.com', 'role' => 'Employee',
        ])->assertForbidden();
    }

    public function test_non_admin_cannot_update_user(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $target  = User::factory()->create(['company_id' => $manager->company_id]);
        Sanctum::actingAs($manager);

        $this->putJson("/api/users/{$target->id}", ['name' => 'New Name'])->assertForbidden();
    }

    public function test_non_admin_cannot_delete_user(): void
    {
        $employee = User::factory()->create(['role' => UserRole::Employee]);
        $target   = User::factory()->create(['company_id' => $employee->company_id]);
        Sanctum::actingAs($employee);

        $this->deleteJson("/api/users/{$target->id}")->assertForbidden();
    }

    // ── Admin happy paths ────────────────────────────────────────────────────

    public function test_admin_can_list_users_in_own_company(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->count(2)->create(['company_id' => $admin->company_id]);
        // user from another company — should NOT appear
        User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users')->assertOk();
        $this->assertEquals(3, $response->json('meta.total'));  // admin + 2 created
    }

    public function test_admin_creates_user_and_welcome_email_job_is_queued(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/users', [
            'name'  => 'Jane',
            'email' => 'jane@example.com',
            'role'  => 'Employee',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Jane');

        $this->assertDatabaseHas('users', [
            'email'      => 'jane@example.com',
            'company_id' => $admin->company_id,
        ]);

        Queue::assertPushed(SendWelcomeEmail::class);
    }

    public function test_admin_can_update_user_role(): void
    {
        $admin  = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create([
            'company_id' => $admin->company_id,
            'role'       => UserRole::Employee,
        ]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$target->id}", ['role' => 'Manager'])
            ->assertOk()
            ->assertJsonPath('data.role', 'Manager');

        $this->assertEquals(UserRole::Manager, $target->fresh()->role);
    }

    public function test_admin_can_delete_another_user(): void
    {
        $admin  = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create(['company_id' => $admin->company_id]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$target->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot delete your own account');
    }

    // ── Email uniqueness (per-company) ──────────────────────────────────────

    public function test_duplicate_email_in_same_company_returns_422(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create([
            'company_id' => $admin->company_id,
            'email'      => 'used@example.com',
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/users', [
            'name'  => 'Dup',
            'email' => 'used@example.com',
            'role'  => 'Employee',
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_same_email_in_different_company_is_allowed(): void
    {
        Queue::fake();

        // 'used@example.com' already exists in another company.
        User::factory()->create(['email' => 'used@example.com']);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/users', [
            'name'  => 'Cross',
            'email' => 'used@example.com',
            'role'  => 'Employee',
        ])->assertCreated();
    }

    // ── Role validation ──────────────────────────────────────────────────────

    public function test_creating_user_with_invalid_role_returns_422(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/users', [
            'name'  => 'Bad',
            'email' => 'bad@example.com',
            'role'  => 'Owner',
        ])->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['role']]);
    }

    public function test_updating_user_to_invalid_role_returns_422(): void
    {
        $admin  = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create(['company_id' => $admin->company_id]);
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$target->id}", ['role' => 'SuperUser'])
            ->assertUnprocessable();
    }

    // ── Multi-tenant isolation ──────────────────────────────────────────────

    public function test_admin_cannot_see_users_from_other_companies(): void
    {
        $admin     = User::factory()->create(['role' => UserRole::Admin]);
        $otherUser = User::factory()->create(); // different company
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users')->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($otherUser->id, $ids);
    }

    public function test_cross_company_user_id_returns_404(): void
    {
        $admin     = User::factory()->create(['role' => UserRole::Admin]);
        $otherUser = User::factory()->create(); // different company
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$otherUser->id}", ['name' => 'Hijack'])->assertNotFound();
        $this->deleteJson("/api/users/{$otherUser->id}")->assertNotFound();
    }

    // ── Role filter ──────────────────────────────────────────────────────────

    public function test_user_list_can_be_filtered_by_role(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['company_id' => $admin->company_id, 'role' => UserRole::Manager]);
        User::factory()->count(2)->create(['company_id' => $admin->company_id, 'role' => UserRole::Employee]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users?role=Manager')->assertOk();
        $this->assertEquals(1, $response->json('meta.total'));
    }
}
