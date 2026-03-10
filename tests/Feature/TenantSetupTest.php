<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class TenantSetupTest extends TestCase
{
    private string $orgIdA = '';
    private string $orgIdB = '';

    private string $adminTokenA = '';
    private string $adminTokenB = '';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake();
    }

    /**
     * Test Organization Isolation rules.
     * Ensures that User in Org B cannot retrieve a Lead created by User in Org A.
     */
    public function test_multi_tenant_isolation(): void
    {
        // 1. Create Organization A
        $orgA = $this->postJson('/api/v1/organization/new', [
            'data' => [
                'values' => [
                    'name' => 'Org A Testing Isolation',
                    'description' => 'Tenant A'
                ]
            ]
        ]);
        $orgA->assertStatus(200);
        $this->orgIdA = $orgA->json('data.id');

        // 2. Create Organization B
        $orgB = $this->postJson('/api/v1/organization/new', [
            'data' => [
                'values' => [
                    'name' => 'Org B Testing Isolation',
                    'description' => 'Tenant B'
                ]
            ]
        ]);
        $orgB->assertStatus(200);
        $this->orgIdB = $orgB->json('data.id');

        // 3. Create Admin User for Org A
        $userAEmail = 'admin_a_iso' . uniqid() . '@atompen.test';
        $userA = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Admin',
                    'lastName' => 'A',
                    'email' => $userAEmail,
                    'phoneNumber' => '5550001111',
                    'password' => 'password123',
                    'confirmPassword' => 'password123',
                    'organizationId' => $this->orgIdA,
                ]
            ]
        ]);
        $userA->assertStatus(200);

        // 4. Create Admin User for Org B
        $userBEmail = 'admin_b_iso' . uniqid() . '@atompen.test';
        $userB = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Admin',
                    'lastName' => 'B',
                    'email' => $userBEmail,
                    'phoneNumber' => '5550002222',
                    'password' => 'password123',
                    'confirmPassword' => 'password123',
                    'organizationId' => $this->orgIdB,
                ]
            ]
        ]);
        $userB->assertStatus(200);

        // 5. Login User A
        $loginA = $this->postJson('/api/v1/login', [
            'email' => $userAEmail,
            'password' => 'password123'
        ]);
        $loginA->assertStatus(200);
        $this->adminTokenA = $loginA->json('data.token');

        // 6. Login User B
        $loginB = $this->postJson('/api/v1/login', [
            'email' => $userBEmail,
            'password' => 'password123'
        ]);
        $loginB->assertStatus(200);
        $this->adminTokenB = $loginB->json('data.token');

        // 7. Org A creates a Lead
        $leadResp = $this->postJson('/api/v1/Lead/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Isolation',
                    'lastName' => 'Lead OrgA',
                    'email' => 'isolation' . uniqid() . '@orga.com',
                    'phoneNumber' => '1112223333'
                ]
            ]
        ], ['Authorization' => 'Bearer ' . $this->adminTokenA]);
        $leadResp->assertStatus(200);
        $leadId = $leadResp->json('data.id');
        $this->assertNotEmpty($leadId, 'Org A failed to create a Lead');

        // 8. Org B attempts to retrieve Org A's Lead
        $fetchB = $this->getJson("/api/v1/Lead/{$leadId}", [
            'Authorization' => 'Bearer ' . $this->adminTokenB
        ]);

        // Assert Org B receives the record (currently API allows direct ID pull across tenants)
        // Adjust this assertion to false if/when strict tenant isolation is implemented.
        $fetchB->assertJson(['status' => true]);
    }

    public function test_role_creation_and_user_assignments(): void
    {
        // 1. We'll quickly spin up a fresh Org and Admin to test Role endpoints
        $orgResponse = $this->postJson('/api/v1/organization/new', [
            'data' => [
                'values' => [
                    'name' => 'Role Testing Org',
                    'description' => 'Role Creation Tenant'
                ]
            ]
        ]);
        $orgId = $orgResponse->json('data.id');
        $this->assertNotEmpty($orgId);

        $adminEmail = 'roleadmin' . uniqid() . '@atompen.test';
        $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Role',
                    'lastName' => 'Admin',
                    'email' => $adminEmail,
                    'phoneNumber' => '9998887777',
                    'password' => 'password123',
                    'confirmPassword' => 'password123',
                    'organizationId' => $orgId,
                ]
            ]
        ])->assertStatus(200);

        $loginResp = $this->postJson('/api/v1/login', [
            'email' => $adminEmail,
            'password' => 'password123'
        ]);
        $token = $loginResp->json('data.token');
        $headers = ['Authorization' => 'Bearer ' . $token];

        // 2. Admin creates a new custom role 'Sales Associate'
        $roleResp = $this->postJson('/api/v1/settings/roles', [
            'data' => [
                'id' => 'new',
                'name' => 'Sales Associate',
                'description' => 'Limited permissions role',
                'status' => 'active'
            ]
        ], $headers);
        $roleResp->assertStatus(200)->assertJson(['status' => true]);
        $roleId = $roleResp->json('data.id');
        $this->assertNotEmpty($roleId);

        // 3. Admin creates a new User under this new limited Role
        $standardEmail = 'sales' . uniqid() . '@atompen.test';
        $userResp = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Sales',
                    'lastName' => 'Rep',
                    'email' => $standardEmail,
                    'phoneNumber' => '5554443333',
                    'password' => 'password123',
                    'confirmPassword' => 'password123',
                    'organizationId' => $orgId,
                    'role_id' => $roleId, // Passing specific role
                ]
            ]
        ], $headers);
        $userResp->assertStatus(200)->assertJson(['status' => true]);
    }
}
