<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AuthTest
 *
 * Tests: POST /api/v1/login (success + failure), POST /api/v1/logout
 *
 * PRE-REQUISITE: Run UserJourneyTest first to create 'admin@atompen.test',
 * or create it manually. This test does NOT create the user.
 */
class AuthTest extends TestCase
{
    // ── helpers ──────────────────────────────────────────────────────────────

    /** Log in and return a Bearer token. */
    private function getToken(string $email = 'admin@atompen.test', string $password = 'password123'): string
    {
        $response = $this->postJson('/api/v1/login', compact('email', 'password'));
        return $response->json('data.token') ?? '';
    }

    // ── tests ────────────────────────────────────────────────────────────────

    /** POST /api/v1/login — valid credentials */
    public function test_login_success(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'admin@atompen.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => true, 'message' => 'Success']);

        $this->assertNotNull($response->json('data.token'), 'Token must not be null');
        $this->assertNotNull($response->json('data.user.id'), 'User ID must be present');
        $this->assertEquals('admin@atompen.test', $response->json('data.user.email'));
    }

    /** POST /api/v1/login — wrong password */
    public function test_login_wrong_password(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'admin@atompen.test',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => false, 'message' => 'Invalid credentials']);
    }

    /** POST /api/v1/login — non-existent email */
    public function test_login_nonexistent_email(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'nobody_here@atompen.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => false]);
    }

    /** POST /api/v1/logout — valid token logs out successfully */
    public function test_logout(): void
    {
        $token = $this->getToken();
        $this->assertNotEmpty($token, 'Login must succeed before logout test');

        $response = $this->postJson('/api/v1/logout', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => true, 'message' => 'Successfully logged out']);
    }
}
