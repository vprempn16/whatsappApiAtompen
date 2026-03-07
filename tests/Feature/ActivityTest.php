<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ActivityTest
 *
 * Tests Activity-specific endpoints:
 *   POST /api/v1/Activity/new
 *   GET  /api/v1/Activity/{id}
 *   POST /api/v1/Activity/{id}/activity-status-update
 *   GET  /api/v1/{module}/{entity_id}/Activity/records
 *   GET  /api/v1/Activity/my-list
 */
class ActivityTest extends TestCase
{
    private string $token = '';
    private string $leadId = '';
    private string $activityId = '';

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

        if ($this->token) {
            // Create a lead to link activities to
            $lead = $this->postJson('/api/v1/Lead/new', [
                'data' => [
                    'values' => \Tests\Helpers\PayloadGenerator::generate('Lead', [], true),
                ],
            ], ['Authorization' => 'Bearer ' . $this->token]);
            $this->leadId = $lead->json('data.id') ?? '';

            // Create an activity to test detail / status-update endpoints
            if ($this->leadId) {
                $act = $this->postJson('/api/v1/Activity/new', [
                    'data' => [
                        'values' => array_merge(
                            \Tests\Helpers\PayloadGenerator::generate('Activity', [], true),
                            ['activityType' => 'meeting']
                        ),
                        'relatedRecords' => [
                            ['module' => 'Lead', 'id' => $this->leadId],
                        ],
                    ],
                ], ['Authorization' => 'Bearer ' . $this->token]);
                $this->activityId = $act->json('data.id') ?? '';
            }
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_create_activity(): void
    {
        $this->assertNotEmpty($this->activityId, 'Activity must be created in setUp');
    }

    public function test_get_activity_details(): void
    {
        $this->assertNotEmpty($this->activityId, 'Activity must be created in setUp');

        $response = $this->getJson('/api/v1/Activity/' . $this->activityId, $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_update_activity_status(): void
    {
        $this->assertNotEmpty($this->activityId, 'Activity must be created in setUp');

        $response = $this->postJson(
            '/api/v1/Activity/' . $this->activityId . '/activity-status-update',
            [
                'data' => [
                    'values' => ['status' => 'completed']
                ]
            ],
            $this->headers()
        );
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_get_entity_activities(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->getJson('/api/v1/Lead/' . $this->leadId . '/Activity/records', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_my_activities_list(): void
    {
        $response = $this->getJson('/api/v1/Activity/my-list', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }
}
