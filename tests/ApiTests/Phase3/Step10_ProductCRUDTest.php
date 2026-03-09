<?php

namespace Tests\ApiTests\Phase3;

use Tests\ApiTests\BaseApiTest;
use Tests\Helpers\PayloadGenerator;

class Step10_ProductCRUDTest extends BaseApiTest
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
            $this->markTestSkipped('Failed to authenticate admin for Phase 3.');
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_create_product(): void
    {
        $payload = PayloadGenerator::generate('Product');

        $response = $this->postJson('/api/v1/Product/new', [
            'data' => [
                'values' => $payload
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $id = $response->json('data.id');

        $this->report('Create Product (Automated Payload)', $status, 'Product', [
            'id' => $id,
            'productname' => $payload['productname'] ?? ''
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
        $this->assertNotEmpty($id);

        $this->saveState('last_product_id', $id);
    }

    public function test_get_product_details(): void
    {
        $id = $this->getState('last_product_id');
        if (!$id)
            $this->markTestSkipped('No Product ID found.');

        $response = $this->getJson("/api/v1/Product/{$id}", $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Get Product Details', $status, 'Product', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('data.id'));
    }

    public function test_update_product_full(): void
    {
        $id = $this->getState('last_product_id');
        if (!$id)
            $this->markTestSkipped('No Product ID found.');

        $payload = PayloadGenerator::generate('Product', [], true);

        $response = $this->putJson("/api/v1/Product/{$id}", [
            'data' => [
                'values' => $payload
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Update Product (PUT)', $status, 'Product', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_inline_edit_product(): void
    {
        $id = $this->getState('last_product_id');
        if (!$id)
            $this->markTestSkipped('No Product ID found.');

        $fieldName = 'productname';
        $newValue = 'Updated Product ' . uniqid();

        $response = $this->patchJson("/api/v1/Product/{$id}/inline-edit", [
            'field' => $fieldName,
            'value' => $newValue
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Inline Edit Product (PATCH)', $status, 'Product', [
            'id' => $id,
            'field' => $fieldName,
            'value' => $newValue
        ]);

        // Keep assertion based on guidelines (do not fail tests explicitly if we just want to output report)
        // Wait, guidelines say "if it failed we note the issue". Laravel's assertStatus(200) will fail the test execution entirely.
        // It's standard Laravel PHPUnit, so failing an assertion IS noting the issue in standard execution, but since we capture in try-catch in full runner or just let PHPUnit report.
        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_delete_product(): void
    {
        $id = $this->getState('last_product_id');
        if (!$id)
            $this->markTestSkipped('No Product ID found.');

        $response = $this->deleteJson("/api/v1/Product/{$id}", [], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Delete Product', $status, 'Product', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }
}
