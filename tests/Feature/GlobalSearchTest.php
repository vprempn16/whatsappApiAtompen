<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * GlobalSearchTest
 *
 * Endpoints covered:
 *   POST /api/v1/global-search
 *   GET  /api/v1/global-search
 *   POST /api/v1/filter/{module}
 *   GET  /api/v1/filter/{module}
 */
class GlobalSearchTest extends TestCase
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
            'password' => 'password123',
        ]);
        $this->token = $login->json('data.token') ?? '';
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_global_search_post(): void
    {
        $response = $this->postJson('/api/v1/global-search', [
            'data' => [
                'value' => 'John',
            ]
        ], $this->headers());

        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_global_search_get(): void
    {
        $response = $this->getJson('/api/v1/global-search?value=John', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_module_filter_post(): void
    {
        $response = $this->postJson('/api/v1/filter/Lead', [
            'search' => [
                'value' => 'LeadName',
            ]
        ], $this->headers());

        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_module_filter_get(): void
    {
        $response = $this->getJson('/api/v1/filter/Lead?search=LeadName', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }
}
