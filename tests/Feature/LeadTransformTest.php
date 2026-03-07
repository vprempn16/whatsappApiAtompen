<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * LeadTransformTest
 *
 * Endpoint covered:
 *   GET /api/v1/leads/{id}/transform
 */
class LeadTransformTest extends TestCase
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

        // Create a fresh lead that has never been converted
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

    public function test_transform_lead_to_contact(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->getJson('/api/v1/leads/' . $this->leadId . '/transform', $this->headers());

        $response->assertStatus(200)->assertJson(['status' => true]);
        $this->assertNotNull($response->json('data.contact'), 'Contact must be returned after transform');
    }
}
