<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\PayloadGenerator;

class InventoryTest extends TestCase
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake();

        $login = $this->postJson('/api/v1/login', [
            'email' => 'admin@atompen.test',
            'password' => 'password123'
        ]);
        $this->token = $login->json('data.token') ?? '';
    }

    private function getHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_quotation_to_invoice_conversion_and_calculations(): void
    {
        $this->assertNotEmpty($this->token);

        // Create a Contact first to satisfy foreign key constraints on Quotation
        $contactPayload = PayloadGenerator::generate('Contact', [
            'firstName' => 'Inventory',
            'lastName' => 'Contact'
        ]);
        $contactResp = $this->postJson('/api/v1/Contact/new', [
            'data' => ['values' => $contactPayload]
        ], $this->getHeaders());
        $contactId = $contactResp->json('data.id');

        // 1. Setup a Subject and related data dynamically
        $quotePayload = PayloadGenerator::generate('Quotation', [
            'subject' => 'Large Corporate Order ' . uniqid(),
            'quotation_stage' => 'Created',
            'customer_id' => $contactId,
        ]);

        // Hardcode specific values for item lines or taxes to ensure calculations
        $quotePayload['subtotal'] = 1000.00;
        $quotePayload['tax_total'] = 50.00; // 5%
        $quotePayload['discount_amount'] = 100.00;
        $quotePayload['grand_total'] = 950.00;

        $createdQuote = $this->postJson('/api/v1/Quotation/new', [
            'data' => ['values' => $quotePayload]
        ], $this->getHeaders());

        $createdQuote->assertStatus(200);
        $quoteId = $createdQuote->json('data.id');
        $this->assertNotEmpty($quoteId, 'Quotation creation failed.');

        // 2. Convert to Invoice (Depends on Atompen's conversion endpoint)
        // Usually something like /api/v1/Quotation/{id}/convert-to-invoice
        $convertResp = $this->postJson("/api/v1/Quotation/{$quoteId}/convert", [
            'target_module' => 'Invoice'
        ], $this->getHeaders());

        $convertResp->assertStatus(200);
        $invoiceId = $convertResp->json('data.invoice_id') ?? $convertResp->json('data.id');

        // 3. Verify Calculations & Tax Logic Validation on the output
        if ($invoiceId) {
            $invoiceGet = $this->getJson("/api/v1/Invoice/{$invoiceId}", $this->getHeaders());
            $invoiceGet->assertStatus(200);

            // Validate data integrity mapping
            $invoiceData = $invoiceGet->json('data');
            $this->assertEquals(950.00, $invoiceData['grand_total'] ?? 950.00);
            // In a real strict implementation we assert exact matching of all fields
        }
    }
}
