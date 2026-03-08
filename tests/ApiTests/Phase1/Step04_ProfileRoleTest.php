<?php

namespace Tests\ApiTests\Phase1;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Support\Str;

class Step04_ProfileRoleTest extends BaseApiTest
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $email = $this->getState('admin_email');
        $password = $this->getState('admin_password');

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password
        ]);
        $this->token = $response->json('data.token') ?? '';
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_create_profiles(): void
    {
        $profileNames = ['Sales Manager', 'Sales Executive', 'Relationship Manager', 'Customer Success Manager'];
        $profileIds = [];

        foreach ($profileNames as $name) {
            $response = $this->postJson('/api/v1/settings/profile/save', [
                'data' => [
                    'profile' => [
                        'name' => $name,
                        'description' => $name . ' Profile',
                        'status' => 'Active'
                    ],
                    'modules' => []
                ]
            ], $this->headers());

            $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
            $this->report('Create profile', $status, 'Profile', ['name' => $name]);

            $response->assertStatus(200);
            $profileId = $response->json('data.profile.id');
            $profileIds[$name] = $profileId;
            $this->saveState('profile_id_' . Str::snake($name), $profileId);
        }
    }

    public function test_duplicate_profile_validation(): void
    {
        $name = 'Sales Executive';
        $response = $this->postJson('/api/v1/settings/profile/save', [
            'data' => [
                'profile' => [
                    'name' => $name,
                    'description' => 'Duplicate Profile',
                    'status' => 'Active'
                ]
            ]
        ], $this->headers());

        // Expect failure if uniqueness is enforced
        $status = ($response->status() !== 200 || $response->json('status') === false) ? 'PASSED' : 'FAILED';
        $this->report('Duplicate profile validation', $status, 'Profile', ['name' => $name]);

        $this->assertEquals('PASSED', $status, 'Duplicate profile was unexpectedly created or returned 200 without error');
    }

    public function test_create_role_ceo(): void
    {
        $response = $this->postJson('/api/v1/settings/Role/new', [
            'data' => [
                'id' => 'new',
                'name' => 'CEO',
                'description' => 'Chief Executive Officer',
                'status' => 'Active',
                'profile_ids' => []
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create role', $status, 'Role', ['name' => 'CEO']);

        $response->assertStatus(200);
        $this->saveState('role_ceo_id', $response->json('data.id'));
    }

    public function test_create_child_role(): void
    {
        $ceoId = $this->getState('role_ceo_id');

        $response = $this->postJson('/api/v1/settings/Role/new', [
            'data' => [
                'id' => 'new',
                'name' => 'Sales Manager',
                'description' => 'Head of Sales',
                'status' => 'Active',
                'parent_id' => $ceoId, // Note: This might not be supported in schema
                'profile_ids' => [$this->getState('profile_id_sales_executive')]
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create child role', $status, 'Role', [
            'name' => 'Sales Manager',
            'parent_id' => $ceoId
        ]);

        if ($response->status() === 200) {
            $this->saveState('role_sales_manager_id', $response->json('data.id'));
        }

        $this->assertEquals('PASSED', $status, 'Failed to create child role');
    }

    public function test_duplicate_role_validation(): void
    {
        $response = $this->postJson('/api/v1/settings/Role/new', [
            'data' => [
                'id' => 'new',
                'name' => 'CEO',
                'description' => 'Duplicate CEO',
                'status' => 'Active'
            ]
        ], $this->headers());

        $status = ($response->status() !== 200 || $response->json('status') === false) ? 'PASSED' : 'FAILED';
        $this->report('Duplicate role', $status, 'Role', ['name' => 'CEO']);

        $this->assertEquals('PASSED', $status, 'Duplicate role was unexpectedly created');
    }
}
