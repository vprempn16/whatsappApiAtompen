<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    private string $token = '';
    private string $orgId = '';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake();

        // Use a generic admin or login here. We assume tenant setup ran or an admin exists.
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

    public function test_outgoing_mail_server_configuration(): void
    {
        $this->assertNotEmpty($this->token);

        // Typical mail configuration path
        $payload = [
            'server_type' => 'smtp',
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'nathprem529@gmail.com',
            'password' => 'bwsg ftbm cafk voht', // Provided by user
            'encryption' => 'tls',
            'status' => 'active'
        ];

        // Replace endpoint if it differs in Atompen
        $response = $this->postJson('/api/v1/settings/mail/outgoing', $payload, $this->getHeaders());

        // As long as the API processes the config gracefully, we pass
        $response->assertStatus(200);
    }

    public function test_imap_incoming_configuration(): void
    {
        $this->assertNotEmpty($this->token);

        $payload = [
            'server' => 'imap.gmail.com',
            'port' => 993,
            'username' => 'nathprem529@gmail.com',
            'password' => 'bwsg ftbm cafk voht', // Provided by user
            'encryption' => 'ssl',
            'fetch_interval' => 5, // minutes
            'status' => 'active'
        ];

        $response = $this->postJson('/api/v1/settings/mail/incoming', $payload, $this->getHeaders());
        $response->assertStatus(200);
    }

    public function test_whatsapp_api_configuration(): void
    {
        $this->assertNotEmpty($this->token);

        $payload = [
            'app_id' => '1697915567636266',
            'app_secret' => '527b6024ea5ff024385f3258eb05dc28',
            'phone_number_id' => '399445176588320',
            'business_id' => '347901028417242',
            'access_token' => 'EAAYIPsZAEfyoBPtMSd5HjosfxVAiPZCv01kxeA3vbxGF95e5ZBUPZBUI6yXbZApoDLFRDGNHrWBZCZC6sZCdAfYZC0LsPzd8pUaPeWKmMtF6SjORtF8OO9ce3Ho87HTZCtFeH3FF5rMqyU8N6DUp8ZC8MV84IGnMpY2AOGjJ0dId2l1OAmOkv4BrCIdEuQQCSbEHrKuuAZDZD',
            'status' => 'active'
        ];

        $response = $this->postJson('/api/v1/settings/whatsapp/config', $payload, $this->getHeaders());
        $response->assertStatus(200);
    }
}
