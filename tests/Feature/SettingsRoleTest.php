<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * SettingsRoleTest
 *
 * Tests admin-only settings endpoints (requires admin token):
 *   GET    /api/v1/settings/User
 *   GET    /api/v1/settings/User/{id}
 *   GET    /api/v1/settings/roles
 *   POST   /api/v1/settings/roles
 *   GET    /api/v1/settings/roles/{id}
 *   DELETE /api/v1/settings/roles/{id}
 */
class SettingsRoleTest extends TestCase
{
    private string $token = '';
    private string $userId = '';
    private string $roleId = '';

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake();

        $login = $this->postJson('/api/v1/login', [
            'email' => 'admin@atompen.test',
            'password' => 'password123',
        ]);
        $this->token = $login->json('data.token') ?? '';

        // Get the current user ID from the login response
        $this->userId = $login->json('data.user.id') ?? '';

        if ($this->token) {
            // Create a role to test show and delete
            $role = $this->postJson('/api/v1/settings/roles', [
                'data' => [
                    'id' => 'new',
                    'name' => 'Test Role From SettingsRoleTest ' . uniqid(),
                    'description' => 'Automated test role',
                    'status' => 1,
                ]
            ], ['Authorization' => 'Bearer ' . $this->token]);
            $this->roleId = $role->json('data.id') ?? '';
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_list_users(): void
    {
        $response = $this->getJson('/api/v1/settings/User', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_show_user(): void
    {
        $this->assertNotEmpty($this->userId, 'User ID must be known from login');

        $response = $this->getJson('/api/v1/settings/User/' . $this->userId, $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_list_roles(): void
    {
        $response = $this->getJson('/api/v1/settings/roles', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_create_role(): void
    {
        $this->assertNotEmpty($this->roleId, 'Role must be created in setUp');
    }

    public function test_show_role(): void
    {
        $this->assertNotEmpty($this->roleId, 'Role must be created in setUp');

        $response = $this->getJson('/api/v1/settings/roles/' . $this->roleId, $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_delete_role(): void
    {
        $this->assertNotEmpty($this->roleId, 'Role must be created in setUp');

        $response = $this->deleteJson('/api/v1/settings/roles/' . $this->roleId, [], $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }
}
