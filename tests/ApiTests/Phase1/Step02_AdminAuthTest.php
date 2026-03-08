<?php

namespace Tests\ApiTests\Phase1;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Support\Str;

class Step02_AdminAuthTest extends BaseApiTest
{
    public function test_create_admin_user(): void
    {
        $orgId = $this->getState('organization_id');
        $this->assertNotEmpty($orgId, 'Organization ID must exist from previous step');

        $email = 'admin_' . uniqid() . '@atompen.test';
        $password = 'Admin@123';

        $response = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Admin',
                    'lastName' => 'User',
                    'email' => $email,
                    'phoneNumber' => '5550001111',
                    'password' => $password,
                    'confirmPassword' => $password,
                    'organizationId' => $orgId,
                ]
            ]
        ]);

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create admin user for organization', $status, 'User', [
            'email' => $email,
            'password' => $password
        ]);

        $response->assertStatus(200);
        $this->saveState('admin_email', $email);
        $this->saveState('admin_password', $password);
    }

    public function test_admin_email_validation(): void
    {
        $orgId = $this->getState('organization_id');
        $email = $this->getState('admin_email');
        $password = 'Password123';

        // Duplicate email
        $response = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Another',
                    'lastName' => 'Admin',
                    'email' => $email,
                    'password' => $password,
                    'confirmPassword' => $password,
                    'organizationId' => $orgId,
                ]
            ]
        ]);

        $status = ($response->status() === 422 || ($response->status() === 200 && $response->json('status') === false)) ? 'PASSED' : 'FAILED';
        $this->report('Admin email validation', $status, 'User', [
            'email' => $email,
            'reason' => 'Duplicate email check'
        ]);

        $this->assertEquals('PASSED', $status, 'Duplicate email was not caught by validation');
    }

    public function test_admin_password_validation(): void
    {
        $orgId = $this->getState('organization_id');
        $email = 'short_pwd_' . uniqid() . '@atompen.test';
        $password = 'short'; // Too short

        $response = $this->postJson('/api/v1/settings/User/new', [
            'data' => [
                'values' => [
                    'firstName' => 'Short',
                    'lastName' => 'Pwd',
                    'email' => $email,
                    'password' => $password,
                    'confirmPassword' => $password,
                    'organizationId' => $orgId,
                ]
            ]
        ]);

        $status = ($response->status() === 422 || ($response->status() === 200 && $response->json('status') === false)) ? 'PASSED' : 'FAILED';
        $this->report('Password validation', $status, 'User', [
            'password' => $password,
            'reason' => 'Min length check'
        ]);

        $this->assertEquals('PASSED', $status, 'Short password was not caught by validation');
    }

    public function test_login_success(): void
    {
        $email = $this->getState('admin_email');
        $password = $this->getState('admin_password');

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password
        ]);

        $status = ($response->status() === 200 && $response->json('status') === true) ? 'PASSED' : 'FAILED';
        $token = $response->json('data.token');

        $this->report('Login success', $status, 'Auth', [
            'user' => $email,
            'password' => $password
        ]);

        $response->assertStatus(200);
        $this->saveState('admin_token', $token);
    }

    public function test_login_wrong_password(): void
    {
        $email = $this->getState('admin_email');
        $password = 'wrong_password';

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password
        ]);

        // Controller returns 200 with status: false
        $status = ($response->status() === 200 && $response->json('status') === false) ? 'PASSED' : 'FAILED';

        $this->report('Login wrong password', $status, 'Auth', [
            'user' => $email,
            'password' => $password
        ]);

        $this->assertFalse($response->json('status'));
    }

    public function test_login_nonexistent_email(): void
    {
        $email = 'nonexistent_' . uniqid() . '@atompen.test';
        $password = 'Password123';

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password
        ]);

        $status = ($response->status() === 200 && $response->json('status') === false) ? 'PASSED' : 'FAILED';

        $this->report('Login nonexistent email', $status, 'Auth', [
            'user' => $email
        ]);

        $this->assertFalse($response->json('status'));
    }

    public function test_logout(): void
    {
        $token = $this->getState('admin_token');
        $this->assertNotEmpty($token);

        $response = $this->postJson('/api/v1/logout', [], [
            'Authorization' => 'Bearer ' . $token
        ]);

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Logout', $status, 'Auth');

        $response->assertStatus(200);
    }
}
