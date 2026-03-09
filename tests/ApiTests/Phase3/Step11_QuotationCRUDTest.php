<?php

namespace Tests\ApiTests\Phase3;

use Tests\ApiTests\BaseApiTest;
use Tests\Helpers\PayloadGenerator;
use Illuminate\Support\Facades\Log;

class Step11_QuotationCRUDTest extends BaseApiTest
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

    public function test_create_quotation_with_items(): void
    {
        // 1. We need a Product ID for the line item. We created one in Step10.
        $productId = $this->getState('last_product_id');
        if (!$productId) {
            $this->markTestSkipped('No Product ID found. Step10 must run first.');
        }

        // 2. We need a valid Contact ID for the customer.
        // Lead/Contact from Phase 2 might be deleted, so let's check or create a temporary one.
        $customerId = $this->getState('last_contact_id');

        // Manual Quotation Payload (Header)
        $payload = [
            'subject' => 'Manual Quote ' . uniqid(),
            'customerId' => $customerId,
            'quotationStatus' => 'draft',
            'validUntil' => date('Y-m-d', strtotime('+30 days')),
            'subtotal' => 200.00,
            'totalAmount' => 200.00,
            'currencyCode' => 'USD',
        ];

        // Manual Item Payloads
        $item1 = [
            'productId' => $productId,
            'description' => 'Manual Item 1',
            'quantity' => 1,
            'unitPrice' => 100.00,
            'lineTotal' => 100.00,
        ];

        $item2 = [
            'productId' => $productId,
            'description' => 'Manual Item 2',
            'quantity' => 1,
            'unitPrice' => 100.00,
            'lineTotal' => 100.00,
        ];

        $response = $this->postJson('/api/v1/Quotation/new', [
            'data' => [
                'values' => $payload,
                'relatedRecords' => [
                    'quotation_items' => [
                        $item1,
                        $item2
                    ]
                ]
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $id = $response->json('data.id');

        $this->report('Create Quotation w/ Items (Manual Payload)', $status, 'Quotation', [
            'id' => $id,
            'subject' => $payload['subject']
        ]);

        if ($status === 'FAILED') {
            Log::error('Create Quotation Failed', ['response' => $response->json()]);
            $response->dump();
        }

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
        $this->assertNotEmpty($id);

        $this->saveState('last_quotation_id', $id);
    }

    public function test_get_quotation_details(): void
    {
        $id = $this->getState('last_quotation_id');
        if (!$id)
            $this->markTestSkipped('No Quotation ID found.');

        // Verify that the get endpoint returns the relation or that the record exists
        $response = $this->getJson("/api/v1/Quotation/{$id}", $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Get Quotation Details', $status, 'Quotation', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('data.id'));
    }

    public function test_update_quotation_full(): void
    {
        $id = $this->getState('last_quotation_id');
        if (!$id)
            $this->markTestSkipped('No Quotation ID found.');

        $payload = PayloadGenerator::generate('Quotation', [], true);

        $response = $this->putJson("/api/v1/Quotation/{$id}", [
            'data' => [
                'values' => $payload
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Update Quotation (PUT)', $status, 'Quotation', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_inline_edit_quotation(): void
    {
        $id = $this->getState('last_quotation_id');
        if (!$id)
            $this->markTestSkipped('No Quotation ID found.');

        $fieldName = 'subject';
        $newValue = 'Updated Quote ' . uniqid();

        $response = $this->patchJson("/api/v1/Quotation/{$id}/inline-edit", [
            'field' => $fieldName,
            'value' => $newValue
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Inline Edit Quotation (PATCH)', $status, 'Quotation', [
            'id' => $id,
            'field' => $fieldName,
            'value' => $newValue
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_delete_quotation(): void
    {
        $id = $this->getState('last_quotation_id');
        if (!$id)
            $this->markTestSkipped('No Quotation ID found.');

        $response = $this->deleteJson("/api/v1/Quotation/{$id}", [], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Delete Quotation', $status, 'Quotation', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }
}
