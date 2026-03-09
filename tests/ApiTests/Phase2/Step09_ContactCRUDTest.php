<?php

namespace Tests\ApiTests\Phase2;

use Tests\ApiTests\BaseApiTest;
use Tests\Helpers\PayloadGenerator;
use Illuminate\Support\Str;

class Step09_ContactCRUDTest extends BaseApiTest
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

    public function test_create_contact(): void
    {
        $payload = PayloadGenerator::generate('Contact');

        $response = $this->postJson('/api/v1/Contact/new', [
            'data' => [
                'values' => $payload
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $id = $response->json('data.id');

        $this->report('Create Contact (Automated Payload)', $status, 'Contact', [
            'id' => $id,
            'name' => ($payload['firstname'] ?? '') . ' ' . ($payload['lastname'] ?? '')
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
        $this->assertNotEmpty($id);

        $this->saveState('last_contact_id', $id);
    }

    public function test_get_contact_details(): void
    {
        $id = $this->getState('last_contact_id');
        if (!$id)
            $this->markTestSkipped('No Contact ID found.');

        $response = $this->getJson("/api/v1/Contact/{$id}", $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Get Contact Details', $status, 'Contact', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('data.id'));
    }

    public function test_update_contact_full(): void
    {
        $id = $this->getState('last_contact_id');
        if (!$id)
            $this->markTestSkipped('No Contact ID found.');

        $payload = PayloadGenerator::generate('Contact', [], true);

        $response = $this->putJson("/api/v1/Contact/{$id}", [
            'data' => [
                'values' => $payload
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Update Contact (PUT)', $status, 'Contact', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_inline_edit_contact(): void
    {
        $id = $this->getState('last_contact_id');
        if (!$id)
            $this->markTestSkipped('No Contact ID found.');

        $fieldName = 'email';
        $newValue = 'updated_' . uniqid() . '@example.com';

        $response = $this->patchJson("/api/v1/Contact/{$id}/inline-edit", [
            'field' => $fieldName,
            'value' => $newValue
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Inline Edit Contact (PATCH)', $status, 'Contact', [
            'id' => $id,
            'field' => $fieldName,
            'value' => $newValue
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_delete_contact(): void
    {
        $id = $this->getState('last_contact_id');
        if (!$id)
            $this->markTestSkipped('No Contact ID found.');

        $response = $this->deleteJson("/api/v1/Contact/{$id}", [], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Delete Contact', $status, 'Contact', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }
}
