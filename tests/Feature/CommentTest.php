<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CommentTest
 *
 * Endpoints covered:
 *   POST /api/v1/comment/new
 *   GET  /api/v1/{module}/{entity_id}/comment/records
 */
class CommentTest extends TestCase
{
    private string $token = '';
    private string $leadId = '';

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
            $lead = $this->postJson('/api/v1/Lead/new', [
                'data' => [
                    'values' => \Tests\Helpers\PayloadGenerator::generate('Lead', [], true),
                ],
            ], ['Authorization' => 'Bearer ' . $this->token]);
            $this->leadId = $lead->json('data.id') ?? '';
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_create_comment_on_lead(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->postJson('/api/v1/comment/new', [
            'comment' => 'This is a test comment from CommentTest.',
            'module' => 'Lead',
            'entity_id' => $this->leadId,
        ], $this->headers());

        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_get_comments_for_lead(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->getJson('/api/v1/Lead/' . $this->leadId . '/comment/records', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }
}
