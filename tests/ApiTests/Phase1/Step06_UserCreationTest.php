<?php

namespace Tests\ApiTests\Phase1;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Support\Str;

class Step06_UserCreationTest extends BaseApiTest
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $email = $this->getState('admin_email');
        $password = $this->getState('admin_password');

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password
        ]);
        $this->token = $response->json('data.token') ?? '';
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_create_sales_manager_user(): void
    {
        $email = 'manager_' . uniqid() . '@example.com';
        $password = 'Password123!';

        $response = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'name' => 'John Manager',
                'email' => $email,
                'password' => $password,
                'role_id' => $this->getState('role_sales_manager_id'),
                'profile_id' => $this->getState('profile_id_sales_manager'),
                'status' => 'Active'
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create Sales Manager user', $status, 'User', ['email' => $email]);

        $response->assertStatus(200);
        $this->saveState('user_manager_email', $email);
        $this->saveState('user_manager_password', $password);
    }

    public function test_create_sales_executive_user(): void
    {
        $email = 'executive_' . uniqid() . '@example.com';
        $password = 'Password123!';

        $response = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'name' => 'Jane Executive',
                'email' => $email,
                'password' => $password,
                'role_id' => $this->getState('role_sales_manager_id'), // Assuming child role if it worked
                'profile_id' => $this->getState('profile_id_sales_executive'),
                'status' => 'Active'
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create Sales Executive user', $status, 'User', ['email' => $email]);

        $response->assertStatus(200);
        $this->saveState('user_executive_email', $email);
        $this->saveState('user_executive_password', $password);
    }

    public function test_duplicate_email_validation(): void
    {
        $email = $this->getState('user_manager_email');

        $response = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'name' => 'Duplicate User',
                'email' => $email,
                'password' => 'Password123!',
                'status' => 'Active'
            ]
        ], $this->headers());

        $status = ($response->status() !== 200 || $response->json('status') === false) ? 'PASSED' : 'FAILED';
        $this->report('Duplicate email validation', $status, 'User', ['email' => $email]);

        $this->assertEquals('PASSED', $status, 'Duplicate user email was unexpectedly accepted');
    }
}
