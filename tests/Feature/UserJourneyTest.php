<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class UserJourneyTest extends TestCase
{
    //use DatabaseTransactions;

    /**
     * Test the full user journey:
     * 1. Create Organization
     * 2. Create User
     * 3. Login
     * 4. Create Lead
     * 5. Create Contact
     */
    public function test_full_user_journey(): void
    {
        $this->withoutExceptionHandling();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake();

        // 1. Create Organization
        $orgPayload = [
            'data' => [
                'values' => [
                    'name' => 'Atompen Test Org',
                    'description' => 'Test Organization for feature tests',
                ]
            ]
        ];

        $orgResponse = $this->postJson('/api/v1/organization/new', $orgPayload);

        $orgResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Organization created successfully.'
            ]);

        $orgId = $orgResponse->json('data.id');
        $this->assertNotNull($orgId, 'Organization ID should not be null');

        // 2. Create First User (Admin)
        $userPayload = [
            'data' => [
                'values' => [
                    'firstName' => 'Admin',
                    'lastName' => 'User',
                    'email' => 'admin@atompen.test',
                    'phoneNumber' => '1234567890',
                    'password' => 'password123',
                    'confirmPassword' => 'password123',
                    'organizationId' => $orgId, // Using the created organization
                ]
            ]
        ];

        $userResponse = $this->postJson('/api/v1/settings/User/new', $userPayload);

        $userResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'User created successfully'
            ]);

        $this->assertEquals('admin@atompen.test', $userResponse->json('data.user.email'));

        // 3. Login with the created user
        $loginPayload = [
            'email' => 'admin@atompen.test',
            'password' => 'password123',
        ];

        $loginResponse = $this->postJson('/api/v1/login', $loginPayload);

        $loginResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Success'
            ]);

        $token = $loginResponse->json('data.token');
        $this->assertNotNull($token, 'Authentication token should not be null');

        // Prepare headers with the bearer token for subsequent requests
        $headers = [
            'Authorization' => 'Bearer ' . $token,
        ];

        // 4. Create a Workflow (Commented out until branch merge)
        /*
        $workflowPayload = [
            "name" => "Welcome Email for John or Admin",
            "description" => "Sends a welcome email if conditions are met",
            "trigger" => [
                "event_type" => "created",
                "module_name" => "Lead"
            ],
            "conditions" => [
                [
                    "field_name" => "firstName",
                    "operator" => "contains",
                    "value" => "John",
                    "logic" => "AND"
                ],
                [
                    "field_name" => "email",
                    "operator" => "starts_with",
                    "value" => "prem",
                    "logic" => "AND"
                ]
            ],
            "actions" => [
                [
                    "action_type_id" => "550e8400-e29b-41d4-a716-446655440003",
                    "params" => [
                        "server_id" => "4f7bdf5f-88d0-4b06-b865-f4630b85ace5",
                        "subject" => "Special Welcome to Atompen",
                        "body" => "Hello! We noticed you are an admin or named John. Welcome to our system!",
                        "recipients" => [
                            ["field" => "email", "module_name" => "Lead"]
                        ]
                    ]
                ]
            ]
        ];

        // We wrap it in data.values as per the module endpoints standard, but also fallback to raw if the controller changes
        $workflowResponse = $this->postJson('/api/v1/Workflow/new', ['data' => ['values' => $workflowPayload]], $headers);
        if ($workflowResponse->status() !== 200) {
            $workflowResponse = $this->postJson('/api/v1/Workflow/new', $workflowPayload, $headers);
        }
        $workflowResponse->assertStatus(200);
        */

        // 5. Create a Lead
        $leadPayload = [
            'data' => [
                'values' => [
                    'firstName' => 'John',
                    'lastName' => 'Lead',
                    'email' => 'prem_test@example.com',
                    'phoneNumber' => '5551234567',
                    'company' => 'Lead Company',
                    'leadStatus' => 'New',
                    'leadSource' => 'Website',
                ]
            ]
        ];

        $leadResponse = $this->postJson('/api/v1/Lead/new', $leadPayload, $headers);
        $leadResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);
        $this->assertNotNull($leadResponse->json('data.id'));

        // 5. Create a Contact
        $contactPayload = [
            'data' => [
                'values' => [
                    'firstName' => 'Test',
                    'lastName' => 'Contact',
                    'email' => 'contact@example.com',
                    'phoneNumber' => '5559876543',
                    'title' => 'CEO',
                ]
            ]
        ];

        $contactResponse = $this->postJson('/api/v1/Contact/new', $contactPayload, $headers);
        $contactResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);
        $this->assertNotNull($contactResponse->json('data.id'));

        // 6. Create an Activity linked to the Lead
        $leadId = $leadResponse->json('data.id');
        $activityPayload = [
            'data' => [
                'values' => [
                    'title' => 'Follow up meeting with John',
                    'activityType' => 'meeting',
                    'startDate' => '2026-03-05',
                    'endDate' => '2026-03-05',
                    'startTime' => '10:00:00',
                    'endTime' => '11:00:00',
                    'status' => 'scheduled',
                ],
                'relatedRecords' => [
                    [
                        'module' => 'Lead',
                        'id' => $leadId
                    ]
                ]
            ]
        ];

        $activityResponse = $this->postJson('/api/v1/Activity/new', $activityPayload, $headers);
        $activityResponse->assertStatus(200)
            ->assertJson([
                'status' => true,
            ]);
        $this->assertNotNull($activityResponse->json('data.id'));
    }
}
