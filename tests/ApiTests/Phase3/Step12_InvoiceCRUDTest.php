<?php

namespace Tests\ApiTests\Phase3;

use Tests\ApiTests\BaseApiTest;
use Tests\Helpers\PayloadGenerator;
use Illuminate\Support\Facades\Log;

class Step12_InvoiceCRUDTest extends BaseApiTest
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

    public function test_create_invoice_with_items(): void
    {
        // 1. We need a Product ID for the line item. We created one in Step10.
        $productId = $this->getState('last_product_id');
        if (!$productId) {
            $this->markTestSkipped('No Product ID found. Step10 must run first.');
        }

        // 2. We need a valid Contact ID for the customer.
        $customerId = $this->getState('last_contact_id');

        // Manual Invoice Payload (Header)
        $payload = [
            'invoiceNumber' => 'INV-' . strtoupper(uniqid()),
            'customerId' => $customerId,
            'invoiceDate' => date('Y-m-d'),
            'dueDate' => date('Y-m-d', strtotime('+30 days')),
            'invoiceStatus' => 'draft',
            'subtotal' => 200.00,
            'totalAmount' => 200.00,
            'amountPaid' => 0.00,
            'balanceDue' => 200.00,
            'currencyCode' => 'USD',
        ];

        // Manual Item Payloads
        $item1 = [
            'productId' => $productId,
            'description' => 'Manual Invoice Item 1',
            'quantity' => 1,
            'unitPrice' => 100.00,
            'lineTotal' => 100.00,
        ];

        $item2 = [
            'productId' => $productId,
            'description' => 'Manual Invoice Item 2',
            'quantity' => 1,
            'unitPrice' => 100.00,
            'lineTotal' => 100.00,
        ];

        $response = $this->postJson('/api/v1/Invoice/new', [
            'data' => [
                'values' => $payload,
                'relatedRecords' => [
                    'invoice_items' => [
                        $item1,
                        $item2
                    ]
                ]
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $id = $response->json('data.id');

        $this->report('Create Invoice w/ Items (Manual Payload)', $status, 'Invoice', [
            'id' => $id,
            'invoiceNumber' => $payload['invoiceNumber']
        ]);

        if ($status === 'FAILED') {
            Log::error('Create Invoice Failed', ['response' => $response->json()]);
            $response->dump();
        }

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
        $this->assertNotEmpty($id);

        $this->saveState('last_invoice_id', $id);
    }

    public function test_get_invoice_details(): void
    {
        $id = $this->getState('last_invoice_id');
        if (!$id)
            $this->markTestSkipped('No Invoice ID found.');

        $response = $this->getJson("/api/v1/Invoice/{$id}", $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Get Invoice Details', $status, 'Invoice', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertEquals($id, $response->json('data.id'));
    }

    public function test_update_invoice_full(): void
    {
        $id = $this->getState('last_invoice_id');
        if (!$id)
            $this->markTestSkipped('No Invoice ID found.');

        $payload = PayloadGenerator::generate('Invoice', [], true);

        $response = $this->putJson("/api/v1/Invoice/{$id}", [
            'data' => [
                'values' => $payload
            ]
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Update Invoice (PUT)', $status, 'Invoice', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_inline_edit_invoice(): void
    {
        $id = $this->getState('last_invoice_id');
        if (!$id)
            $this->markTestSkipped('No Invoice ID found.');

        $fieldName = 'subject';
        $newValue = 'Updated Invoice ' . uniqid();

        $response = $this->patchJson("/api/v1/Invoice/{$id}/inline-edit", [
            'field' => $fieldName,
            'value' => $newValue
        ], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Inline Edit Invoice (PATCH)', $status, 'Invoice', [
            'id' => $id,
            'field' => $fieldName,
            'value' => $newValue
        ]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }

    public function test_delete_invoice(): void
    {
        $id = $this->getState('last_invoice_id');
        if (!$id)
            $this->markTestSkipped('No Invoice ID found.');

        $response = $this->deleteJson("/api/v1/Invoice/{$id}", [], $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $this->report('Delete Invoice', $status, 'Invoice', ['id' => $id]);

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
    }
}
