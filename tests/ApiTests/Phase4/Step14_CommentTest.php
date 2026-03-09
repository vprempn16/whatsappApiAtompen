<?php

namespace Tests\ApiTests\Phase4;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Support\Facades\DB;

class Step14_CommentTest extends BaseApiTest
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $email = $this->getState('admin_email');
        $password = $this->getState('admin_password');

        if (!$email || !$password) {
            $this->markTestSkipped('Admin credentials not found in state. Run Phase 1 first.');
        }

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password
        ]);

        $this->token = $response->json('data.token') ?? '';

        if (empty($this->token)) {
            $this->markTestSkipped('Failed to authenticate admin for Phase 4.');
        }

        // Set the user in the test environment for auth() helper
        $user = \App\Models\User::where('email', $email)->first();
        if ($user) {
            $this->actingAs($user, 'sanctum');
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_add_comment_to_lead(): void
    {
        $leadId = $this->getState('last_lead_id');
        if (!$leadId) {
            $this->markTestSkipped('No Lead ID found.');
        }

        $payload = [
            'data' => [
                'values' => [
                    'content' => 'Standard comment for lead visibility'
                ],
                'relatedRecord' => [
                    'comment_rels' => [
                        [
                            'parent_module' => 'Lead',
                            'parent_id' => $leadId
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/comment/new', $payload, $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $id = $response->json('data.id');

        $this->report('Add Comment to Lead', $status, 'Comment', [
            'id' => $id,
            'parentId' => $leadId
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_comment_auto_follow_up(): void
    {
        // 1. Create a temporary Activity to attach the comment to
        $leadId = $this->getState('last_lead_id');
        if (!$leadId) {
            $this->markTestSkipped('No Lead ID found.');
        }

        // Create Activity
        $activityResponse = $this->postJson('/api/v1/Activity/new', [
            'data' => [
                'values' => [
                    'title' => 'Initial Call with Prospect',
                    'activityType' => 'call',
                    'startDate' => date('Y-m-d'),
                    'startTime' => '09:00:00',
                    'endDate' => date('Y-m-d'),
                    'endTime' => '09:30:00',
                    'status' => 'completed'
                ],
                'relatedRecords' => [
                    'activity_relations' => [
                        [
                            'relationType' => 'participant',
                            'entityType' => 'Lead',
                            'entityId' => $leadId
                        ]
                    ]
                ]
            ]
        ], $this->headers());

        if ($activityResponse->status() !== 200) {
            $this->report('Create Pre-requisite Activity', 'FAILED', 'Activity', [
                'error' => $activityResponse->json('message') ?? 'Unknown error',
                'response' => $activityResponse->json()
            ]);
            $activityResponse->assertStatus(200);
        }

        $activityId = $activityResponse->json('data.id');
        $this->assertNotEmpty($activityId, 'Failed to create pre-requisite Activity');

        // 2. Add Comment with trigger text "call tomorrow"
        $commentPayload = [
            'data' => [
                'values' => [
                    'content' => 'Had a great chat. Will call tomorrow to finalize.'
                ],
                'relatedRecord' => [
                    'comment_rels' => [
                        [
                            'parent_module' => 'Activity',
                            'parent_id' => $activityId
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/comment/new', $commentPayload, $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';

        $this->report('Add Comment with Auto Follow-up Trigger', $status, 'Comment', [
            'comment' => $commentPayload['data']['values']['content'],
            'activityId' => $activityId
        ]);

        $response->assertStatus(200);

        // 3. Verify that a follow-up Activity was created
        // We can check the DB or use the API to list activities for this lead
        $followUpExists = DB::table('activities')
            ->where('title', 'like', 'Follow-up%')
            ->where('organization_id', auth()->user()->organization_id)
            ->exists();

        $this->report('Verify Auto Follow-up Activity Creation', $followUpExists ? 'PASSED' : 'FAILED', 'Activity', [
            'parentActivityId' => $activityId
        ]);

        $this->assertTrue($followUpExists, 'Follow-up activity was not automatically created');
    }
}
