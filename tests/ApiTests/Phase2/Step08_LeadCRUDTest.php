<?php

namespace Tests\ApiTests\Phase2;

use Tests\ApiTests\BaseApiTest;
use Tests\Helpers\PayloadGenerator;
use Illuminate\Support\Str;

class Step08_LeadCRUDTest extends BaseApiTest
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
            $this->markTestSkipped('Failed to authenticate admin for Phase 2.');
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_create_lead(): void
    {
        $payload = PayloadGenerator::generate('Lead');

        $response = $this->postJson('/api/v1/Lead/new', [
            'data' => [
                'values' => $payload
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $id = $response->json('data.id');

        $this->report('Create Lead (Automated Payload)', $status, 'Lead', [
            'id' => $id,
            'name' => ($payload['firstname'] ?? '') . ' ' . ($payload['lastname'] ?? '')
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
        $this->assertNotEmpty($id);

        $this->saveState('last_lead_id', $id);
    }

    public function test_get_lead_details(): void
    {
        $id = $this->getState('last_lead_id');
        if (!$id)
            $this->markTestSkipped('No Lead ID found.');

        $response = $this->getJson("/api/v1/Lead/{$id}", $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Get Lead Details', $status, 'Lead', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('data.id'));
    }

    public function test_update_lead_full(): void
    {
        $id = $this->getState('last_lead_id');
        if (!$id)
            $this->markTestSkipped('No Lead ID found.');

        $payload = PayloadGenerator::generate('Lead', [], true); // Only mandatory or update set

        $response = $this->putJson("/api/v1/Lead/{$id}", [
            'data' => [
                'values' => $payload
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Update Lead (PUT)', $status, 'Lead', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_inline_edit_lead(): void
    {
        $id = $this->getState('last_lead_id');
        if (!$id)
            $this->markTestSkipped('No Lead ID found.');

        $fieldName = 'company';
        $newValue = 'Updated Company ' . uniqid();

        $response = $this->patchJson("/api/v1/Lead/{$id}/inline-edit", [
            'field' => $fieldName,
            'value' => $newValue
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Inline Edit Lead (PATCH)', $status, 'Lead', [
            'id' => $id,
            'field' => $fieldName,
            'value' => $newValue
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_delete_lead(): void
    {
        $id = $this->getState('last_lead_id');
        if (!$id)
            $this->markTestSkipped('No Lead ID found.');

        $response = $this->deleteJson("/api/v1/Lead/{$id}", [], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Delete Lead', $status, 'Lead', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }
}
