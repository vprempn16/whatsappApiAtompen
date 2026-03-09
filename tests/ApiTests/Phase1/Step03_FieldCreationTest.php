<?php

namespace Tests\ApiTests\Phase1;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Support\Str;

class Step03_FieldCreationTest extends BaseApiTest
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

    public function test_create_text_field_lead(): void
    {
        $label = 'Lead Source Code';
        $response = $this->postJson('/api/v1/custom-field-creation', [
            'data' => [
                'fieldlabel' => $label,
                'fieldtype' => 'text',
                'modulename' => 'Lead',
                'mandatory' => '0',
                'profiles' => []
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create text field', $status, 'Lead', [
            'field_name' => $label,
            'field_type' => 'text'
        ]);

        $response->assertStatus(200);
        $this->saveState('field_lead_source_code_id', $response->json('data.id'));
    }

    public function test_create_number_field_lead(): void
    {
        $label = 'Lead Score';
        $response = $this->postJson('/api/v1/custom-field-creation', [
            'data' => [
                'fieldlabel' => $label,
                'fieldtype' => 'number',
                'modulename' => 'Lead',
                'mandatory' => '0',
                'profiles' => []
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create number field', $status, 'Lead', [
            'field_name' => $label,
            'field_type' => 'number'
        ]);

        $response->assertStatus(200);
        $this->saveState('field_lead_score_id', $response->json('data.id'));
    }

    public function test_create_number_field_contact(): void
    {
        $label = 'Contact Rating';
        $response = $this->postJson('/api/v1/custom-field-creation', [
            'data' => [
                'fieldlabel' => $label,
                'fieldtype' => 'number',
                'modulename' => 'Contact',
                'mandatory' => '0',
                'profiles' => []
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create number field', $status, 'Contact', [
            'field_name' => $label,
            'field_type' => 'number'
        ]);

        $response->assertStatus(200);
        $this->saveState('field_contact_rating_id', $response->json('data.id'));
    }

    public function test_create_text_field_contact(): void
    {
        $label = 'Contact Category';
        $response = $this->postJson('/api/v1/custom-field-creation', [
            'data' => [
                'fieldlabel' => $label,
                'fieldtype' => 'text',
                'modulename' => 'Contact',
                'mandatory' => '0',
                'profiles' => []
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create text field', $status, 'Contact', [
            'field_name' => $label,
            'field_type' => 'text'
        ]);

        $response->assertStatus(200);
        $this->saveState('field_contact_category_id', $response->json('data.id'));
    }

    public function test_create_date_field(): void
    {
        $label = 'Next Follow Up';
        $response = $this->postJson('/api/v1/custom-field-creation', [
            'data' => [
                'fieldlabel' => $label,
                'fieldtype' => 'date',
                'modulename' => 'Lead',
                'mandatory' => '0',
                'profiles' => []
            ]
        ], $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $this->report('Create date field', $status, 'Lead', [
            'field_name' => $label,
            'field_type' => 'date'
        ]);

        $response->assertStatus(200);
    }

    public function test_duplicate_field_name_validation(): void
    {
        $label = 'Lead Score';
        $response = $this->postJson('/api/v1/custom-field-creation', [
            'data' => [
                'fieldlabel' => $label,
                'fieldtype' => 'number',
                'modulename' => 'Lead',
                'mandatory' => '0',
                'profiles' => []
            ]
        ], $this->headers());

        $status = $response->status() === 400 || $response->json('status') === false ? 'PASSED' : 'FAILED';
        $this->report('Duplicate field name validation', $status, 'Lead', [
            'field_name' => $label
        ]);

        $this->assertFalse($response->json('status'));
    }

    public function test_invalid_field_type(): void
    {
        $label = 'Invalid Field';
        $response = $this->postJson('/api/v1/custom-field-creation', [
            'data' => [
                'fieldlabel' => $label,
                'fieldtype' => 'invalid_type',
                'modulename' => 'Lead',
                'mandatory' => '0',
                'profiles' => []
            ]
        ], $this->headers());

        $status = ($response->status() === 400 || $response->json('status') === false) ? 'PASSED' : 'FAILED';
        $this->report('Invalid field type', $status, 'Lead', [
            'field_type' => 'invalid_type'
        ]);

        $this->assertFalse($response->json('status'));
    }
}
