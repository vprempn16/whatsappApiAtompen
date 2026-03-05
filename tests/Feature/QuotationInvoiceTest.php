<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * QuotationInvoiceTest
 *
 * Endpoints covered:
 *   POST /api/v1/Quotation/new
 *   GET  /api/v1/Quotation/{id}
 *   GET  /api/v1/Quotation/headers
 *   POST /api/v1/Invoice/new
 *   GET  /api/v1/Invoice/{id}
 *   GET  /api/v1/Invoice/headers
 */
class QuotationInvoiceTest extends TestCase
{
    private string $token = '';
    private string $contactId = '';
    private string $quotationId = '';
    private string $invoiceId = '';

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
            // Create a contact to relate to quotation/invoice
            $contact = $this->postJson('/api/v1/Contact/new', [
                'data' => [
                    'values' => [
                        'firstName' => 'QuotTest',
                        'lastName' => 'Contact',
                        'email' => 'quottest_contact@atompen.test',
                        'phoneNumber' => '9990005555',
                    ],
                ],
            ], ['Authorization' => 'Bearer ' . $this->token]);
            $this->contactId = $contact->json('data.id') ?? '';

            // Create a quotation
            $quotation = $this->postJson('/api/v1/Quotation/new', [
                'data' => [
                    'values' => [
                        'title' => 'Test Quotation',
                        'status' => 'Draft',
                        'contactId' => $this->contactId,
                        'lineItems' => [
                            [
                                'name' => 'Test Item',
                                'quantity' => 1,
                                'unitPrice' => 100.00,
                            ],
                        ],
                    ],
                ],
            ], ['Authorization' => 'Bearer ' . $this->token]);
            $this->quotationId = $quotation->json('data.id') ?? '';

            // Create an invoice
            $invoice = $this->postJson('/api/v1/Invoice/new', [
                'data' => [
                    'values' => [
                        'title' => 'Test Invoice',
                        'status' => 'Draft',
                        'contactId' => $this->contactId,
                        'lineItems' => [
                            [
                                'name' => 'Test Item',
                                'quantity' => 1,
                                'unitPrice' => 200.00,
                            ],
                        ],
                    ],
                ],
            ], ['Authorization' => 'Bearer ' . $this->token]);
            $this->invoiceId = $invoice->json('data.id') ?? '';
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_quotation_headers(): void
    {
        $response = $this->getJson('/api/v1/Quotation/headers', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_create_quotation(): void
    {
        $this->assertNotEmpty($this->quotationId, 'Quotation must be created in setUp');
    }

    public function test_show_quotation(): void
    {
        $this->assertNotEmpty($this->quotationId, 'Quotation must be created in setUp');

        $response = $this->getJson('/api/v1/Quotation/' . $this->quotationId, $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_invoice_headers(): void
    {
        $response = $this->getJson('/api/v1/Invoice/headers', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_create_invoice(): void
    {
        $this->assertNotEmpty($this->invoiceId, 'Invoice must be created in setUp');
    }

    public function test_show_invoice(): void
    {
        $this->assertNotEmpty($this->invoiceId, 'Invoice must be created in setUp');

        $response = $this->getJson('/api/v1/Invoice/' . $this->invoiceId, $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }
}
