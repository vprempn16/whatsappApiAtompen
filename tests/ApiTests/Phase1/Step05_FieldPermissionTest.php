<?php

namespace Tests\ApiTests\Phase1;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Support\Str;

class Step05_FieldPermissionTest extends BaseApiTest
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

    public function test_update_lead_field_permissions(): void
    {
        $salesManagerProfileId = $this->getState('profile_id_sales_manager');
        $salesExecutiveProfileId = $this->getState('profile_id_sales_executive');

        // Configure Sales Manager: Lead Score Visible (0), Lead Source Code ReadOnly (1)
        $response1 = $this->postJson('/api/v1/settings/profile/save', [
            'data' => [
                'profile' => ['id' => $salesManagerProfileId],
                'modules' => [
                    'Lead' => [
                        'fields' => [
                            'leadScore' => ['invisible' => 0, 'readonly' => 0, 'editable' => 1],
                            'leadSourceCode' => ['invisible' => 0, 'readonly' => 1, 'editable' => 0]
                        ]
                    ]
                ]
            ]
        ], $this->headers());

        $status1 = $response1->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Update field permissions (Sales Manager)', $status1, 'Lead', ['profile' => 'Sales Manager']);

        // Configure Sales Executive: Lead Score Hidden (1)
        $response2 = $this->postJson('/api/v1/settings/profile/save', [
            'data' => [
                'profile' => ['id' => $salesExecutiveProfileId],
                'modules' => [
                    'Lead' => [
                        'fields' => [
                            'leadScore' => ['invisible' => 1, 'readonly' => 0, 'editable' => 0]
                        ]
                    ]
                ]
            ]
        ], $this->headers());

        $status2 = $response2->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Update field permissions (Sales Executive)', $status2, 'Lead', ['profile' => 'Sales Executive']);

        $response1->assertStatus(200);
        $response2->assertStatus(200);
    }

    public function test_update_contact_field_permissions(): void
    {
        $salesManagerProfileId = $this->getState('profile_id_sales_manager');

        // Configure Sales Manager: Contact Rating Editable, Contact Category Visible
        $response = $this->postJson('/api/v1/settings/profile/save', [
            'data' => [
                'profile' => ['id' => $salesManagerProfileId],
                'modules' => [
                    'Contact' => [
                        'fields' => [
                            'contactRating' => ['invisible' => 0, 'readonly' => 0, 'editable' => 1],
                            'contactCategory' => ['invisible' => 0, 'readonly' => 0, 'editable' => 1]
                        ]
                    ]
                ]
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Update field permissions (Sales Manager)', $status, 'Contact', ['profile' => 'Sales Manager']);

        $response->assertStatus(200);
    }
}
