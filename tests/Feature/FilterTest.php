<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * FilterTest
 *
 * Endpoints covered:
 *   POST   /api/v1/filters/new
 *   GET    /api/v1/filters
 *   GET    /api/v1/filters/{id}
 *   GET    /api/v1/filters/{id}/records
 *   PUT    /api/v1/filters/{id}
 *   DELETE /api/v1/filters/{id}
 */
class FilterTest extends TestCase
{
    private string $token = '';
    private string $filterId = '';

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
            $resp = $this->postJson('/api/v1/filters/new', [
                'module_name' => 'Lead',
                'name' => 'FilterTest Filter',
                'is_shared' => false,
                'is_default' => false,
                'conditions' => [
                    [
                        'field_name' => 'leadStatus',
                        'operator_key' => 'equals',
                        'value' => 'New',
                        'condition_type' => 'AND',
                    ],
                ],
            ], ['Authorization' => 'Bearer ' . $this->token]);

            $this->filterId = $resp->json('data.id') ?? '';
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_create_filter(): void
    {
        $this->assertNotEmpty($this->filterId, 'Filter must be created in setUp');
    }

    public function test_list_filters(): void
    {
        $response = $this->getJson('/api/v1/filters?module=Lead', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_show_filter(): void
    {
        $this->assertNotEmpty($this->filterId, 'Filter must be created in setUp');

        $response = $this->getJson('/api/v1/filters/' . $this->filterId, $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_get_filter_records(): void
    {
        $this->assertNotEmpty($this->filterId, 'Filter must be created in setUp');

        $response = $this->getJson('/api/v1/filters/' . $this->filterId . '/records', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_update_filter(): void
    {
        $this->assertNotEmpty($this->filterId, 'Filter must be created in setUp');

        $response = $this->putJson('/api/v1/filters/' . $this->filterId, [
            'name' => 'FilterTest Filter Updated',
        ], $this->headers());

        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_delete_filter(): void
    {
        $this->assertNotEmpty($this->filterId, 'Filter must be created in setUp');

        $response = $this->deleteJson('/api/v1/filters/' . $this->filterId, [], $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }
}
