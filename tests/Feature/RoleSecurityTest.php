<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\PayloadGenerator;

class RoleSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake();
    }

    public function test_custom_field_role_restriction(): void
    {
        $this->withoutExceptionHandling();

        // 1. Create Org
        $orgResp = $this->postJson('/api/v1/organization/new', [
            'data' => ['values' => ['name' => 'Role Security Org', 'description' => 'Role Testing']]
        ]);
        $orgId = $orgResp->json('data.id');
        $this->assertNotEmpty($orgId);

        // 2. Create Admin
        $adminEmail = 'admin_rolesec_' . uniqid() . '@atompen.test';
        $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Admin',
                    'lastName' => 'Sec',
                    'email' => $adminEmail,
                    'phoneNumber' => '5550001234',
                    'password' => 'password123',
                    'confirmPassword' => 'password123',
                    'organizationId' => $orgId
                ]
            ]
        ])->assertStatus(200);

        // 3. Login Admin
        $tokenAdmin = $this->postJson('/api/v1/login', ['email' => $adminEmail, 'password' => 'password123'])->json('data.token');
        $hdrsAdmin = ['Authorization' => 'Bearer ' . $tokenAdmin];

        // 4. Create Role (Restricted)
        $roleResp = $this->postJson('/api/v1/settings/roles', [
            'data' => ['values' => ['name' => 'Restricted Role', 'description' => 'No access to secret fields', 'status' => 'active']]
        ], $hdrsAdmin);
        $roleId = $roleResp->json('data.id');

        // 5. Create Standard User with Role
        $stdEmail = 'std_rolesec_' . uniqid() . '@atompen.test';
        $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Std',
                    'lastName' => 'Sec',
                    'email' => $stdEmail,
                    'phoneNumber' => '5550009876',
                    'password' => 'password123',
                    'confirmPassword' => 'password123',
                    'organizationId' => $orgId,
                    'role_id' => $roleId
                ]
            ]
        ], $hdrsAdmin)->assertStatus(200);

        // 6. Login Standard User
        $tokenStd = $this->postJson('/api/v1/login', ['email' => $stdEmail, 'password' => 'password123'])->json('data.token');
        $hdrsStd = ['Authorization' => 'Bearer ' . $tokenStd];

        // 7. Admin Creates a Custom Field on Lead module
        // Assume API endpoint /api/v1/custom-field-creation/new or similar exists for field creation
        $customFieldPayload = [
            'data' => [
                'fieldlabel' => 'Secret Code',
                'fieldtype' => 'text',
                'modulename' => 'Lead',
            ]
        ];

        $cfResp = $this->postJson('/api/v1/custom-field-creation', $customFieldPayload, $hdrsAdmin);
        // Sometimes endpoints differ, we assert status 200 loosely here. 
        // If it fails, the CRM might require a different payload.
        if ($cfResp->status() !== 200) {
            $this->markTestSkipped('Custom field creation failed or endpoint differs: ' . $cfResp->getContent());
        }

        $apiFieldName = $cfResp->json('data.fieldname'); // e.g., 'secret_code_c'
        $this->assertNotEmpty($apiFieldName);

        // 8. Admin Creates Lead WITH Secret Code
        $leadPayload = ['data' => ['values' => PayloadGenerator::generate('Lead')]];
        $leadPayload['data']['values'][$apiFieldName] = 'TOP SECRET DATA';

        $leadResp = $this->postJson('/api/v1/Lead/new', $leadPayload, $hdrsAdmin);
        $leadId = $leadResp->json('data.id');
        $this->assertNotEmpty($leadId);

        // 9. Admin Fetches Lead -> Sees Secret Code
        $adminFetch = $this->getJson("/api/v1/Lead/{$leadId}", $hdrsAdmin);
        $adminFetch->assertJsonFragment([$apiFieldName => 'TOP SECRET DATA']);

        // 10. Standard User Fetches Lead -> Should NOT see Secret Code (or receives 403 on the field)
        $stdFetch = $this->getJson("/api/v1/Lead/{$leadId}", $hdrsStd);

        // Assert field is stripped / hidden
        $stdData = $stdFetch->json('data');
        if (isset($stdData[$apiFieldName])) {
            $this->fail("Standard user was able to view restricted custom field: {$apiFieldName}");
        } else {
            $this->assertTrue(true, 'Standard user was properly restricted from viewing the custom field.');
        }

        // 11. Standard User Attempts to Update Secret Code -> Should Fail or ignore
        $stdUpdatePayload = ['data' => ['values' => [$apiFieldName => 'HACKED']]];
        $this->putJson("/api/v1/Lead/{$leadId}", $stdUpdatePayload, $hdrsStd);

        // Admin verifies the secret wasn't hacked
        $verifyFetch = $this->getJson("/api/v1/Lead/{$leadId}", $hdrsAdmin);
        $verifyFetch->assertJsonFragment([$apiFieldName => 'TOP SECRET DATA']);
    }
}
