<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class UserJourneyTest extends TestCase
{
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

        // 4. Create a Lead
        $leadPayload = [
            'data' => [
                'values' => [
                    'first_name' => 'Test',
                    'last_name' => 'Lead',
                    'email' => 'lead@example.com',
                    'company' => 'Lead Company',
                    'lead_status' => 'New',
                    'lead_source' => 'Website',
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
                    'first_name' => 'Test',
                    'last_name' => 'Contact',
                    'email' => 'contact@example.com',
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
    }
}
