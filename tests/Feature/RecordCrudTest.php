<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * RecordCrudTest
 *
 * Tests generic RecordController endpoints against Lead and Contact modules.
 *
 * Endpoints covered:
 *   GET  /api/v1/{module}
 *   GET  /api/v1/{module}/{id}
 *   PUT  /api/v1/{module}/{id}
 *   DELETE /api/v1/{module}/{id}
 *   GET  /api/v1/{module}/{id}/audit-log
 *   GET  /api/v1/{module}/{id}/{relatedmodule}/records
 */
class RecordCrudTest extends TestCase
{
    private string $token = '';
    private string $leadId = '';

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake();

        // Login
        $login = $this->postJson('/api/v1/login', [
            'email' => 'admin@atompen.test',
            'password' => 'password123',
        ]);
        $this->token = $login->json('data.token') ?? '';

        if ($this->token) {
            // Try to create a lead; if the email already exists from a prior run
            // the API will return 200 but with status:false and no data.id.
            $resp = $this->postJson('/api/v1/Lead/new', [
                'data' => [
                    'values' => [
                        'firstName' => 'CrudTest',
                        'lastName' => 'Lead',
                        'email' => 'crudtest_lead@atompen.test',
                        'phoneNumber' => '9990001111',
                    ],
                ],
            ], ['Authorization' => 'Bearer ' . $this->token]);

            $this->leadId = $resp->json('data.id') ?? '';

            // Fallback: find the existing lead by filtering
            if (empty($this->leadId)) {
                $list = $this->postJson('/api/v1/filter/Lead', [
                    'conditions' => [
                        [
                            'field_name' => 'email',
                            'operator_key' => 'equals',
                            'value' => 'crudtest_lead@atompen.test',
                            'condition_type' => 'AND',
                        ]
                    ],
                ], ['Authorization' => 'Bearer ' . $this->token]);

                $this->leadId = $list->json('data.records.0.id') ?? '';
            }
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function test_list_leads(): void
    {
        $response = $this->getJson('/api/v1/Lead', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_show_lead(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->getJson('/api/v1/Lead/' . $this->leadId, $this->headers());
        $response->assertStatus(200)
            ->assertJson(['status' => true]);
    }

    public function test_update_lead(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->putJson('/api/v1/Lead/' . $this->leadId, [
            'data' => [
                'values' => [
                    'firstName' => 'UpdatedCrud',
                ],
            ],
        ], $this->headers());

        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_get_audit_log_for_lead(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->getJson('/api/v1/Lead/' . $this->leadId . '/audit-log', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_list_related_contact_records_of_lead(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->getJson('/api/v1/Lead/' . $this->leadId . '/Contact/records', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_delete_lead(): void
    {
        $this->assertNotEmpty($this->leadId, 'Lead must be created in setUp');

        $response = $this->deleteJson('/api/v1/Lead/' . $this->leadId, [], $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }
}
