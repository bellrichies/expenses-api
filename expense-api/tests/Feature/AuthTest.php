<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_company_admin_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                 => 'Owner',
            'email'                => 'owner@acme.test',
            'password'             => 'password123',
            'password_confirmation'=> 'password123',
            'company_name'         => 'Acme Corp',
            'company_email'        => 'hq@acme.test',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'name', 'email', 'role', 'company'],
                ],
            ]);

        $user = User::where('email', 'owner@acme.test')->first();
        $this->assertNotNull($user);
        $this->assertEquals(UserRole::Admin, $user->role);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@acme.test']);

        $this->postJson('/api/auth/register', [
            'name'                 => 'Clone',
            'email'                => 'taken@acme.test',
            'password'             => 'password123',
            'password_confirmation'=> 'password123',
            'company_name'         => 'NewCo',
            'company_email'        => 'newco@test.com',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_with_wrong_password_returns_422(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_user_can_retrieve_own_profile(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', $user->role->value);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/auth/user')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated');
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
