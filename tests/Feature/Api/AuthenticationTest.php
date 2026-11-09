<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_registration_always_creates_a_regular_user(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new-user@example.com',
            'password' => 'Strong-password-123',
            'password_confirmation' => 'Strong-password-123',
            'role' => User::ROLE_ADMIN,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', User::ROLE_USER)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'role'], 'token']);

        $this->assertDatabaseHas('users', [
            'email' => 'new-user@example.com',
            'role' => User::ROLE_USER,
        ]);
    }

    public function test_api_login_issues_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['token']);

        $this->withToken($response->json('token'))
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_api_login_rejects_invalid_credentials(): void
    {
        $this->postJson('/api/login', [
            'email' => 'unknown@example.com',
            'password' => 'incorrect',
        ])->assertUnauthorized();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    public function test_regular_users_cannot_access_admin_login_or_admin_routes(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->postJson('/api/admin/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertForbidden();

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admins_can_login_and_access_admin_routes(): void
    {
        $admin = User::factory()->create(['password' => 'secret-password']);
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk()
            ->assertJsonPath('data.role', User::ROLE_ADMIN);

        $this->withToken($response->json('token'))
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $admin->id);
    }

    public function test_legacy_is_admin_accounts_can_login_and_access_admin_routes(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'password' => 'secret-password',
        ]);

        $response = $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ])->assertOk()
            ->assertJsonPath('data.id', $admin->id);

        $this->withToken($response->json('token'))
            ->getJson('/api/admin/users')
            ->assertOk();
    }

    public function test_users_can_only_access_their_own_user_record_unless_admin(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        Sanctum::actingAs($owner);

        $this->getJson("/api/users/{$owner->id}")->assertOk();
        $this->getJson("/api/users/{$otherUser->id}")->assertForbidden();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
        Sanctum::actingAs($admin);

        $this->getJson("/api/users/{$owner->id}")->assertOk();
    }

    public function test_web_registration_ignores_submitted_admin_role(): void
    {
        $this->post('/register', [
            'name' => 'Web User',
            'email' => 'web-user@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'web-user@example.com',
            'role' => User::ROLE_USER,
        ]);
    }
}
