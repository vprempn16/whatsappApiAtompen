<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CustomFieldTest
 *
 * Endpoints covered:
 *   POST   /api/v1/custom-field-creation         (create)
 *   GET    /api/v1/custom-field-creation/list     (list)
 *   GET    /api/v1/field-details/{module}/{id}    (show)
 *   PUT    /api/v1/field-update                   (update label)
 *   DELETE /api/v1/field/{id}                     (delete)
 */
class CustomFieldTest extends TestCase
{
    private string $token = '';
    private string $fieldId = '';

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
            // Create a custom text field on Lead module
            $resp = $this->postJson('/api/v1/custom-field-creation', [
                'data' => [
                    'fieldlabel' => 'Custom Test Field',
                    'fieldtype' => 'text',
                    'modulename' => 'Lead',
                    'mandatory' => '0',
                    'profiles' => [],
                ]
            ], ['Authorization' => 'Bearer ' . $this->token]);

            $this->fieldId = $resp->json('data.id') ?? '';
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_create_custom_field(): void
    {
        $this->assertNotEmpty($this->fieldId, 'Custom field must be created in setUp');
    }

    public function test_list_custom_fields(): void
    {
        $response = $this->getJson('/api/v1/custom-field-creation/list?module=Lead', $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_show_custom_field(): void
    {
        $this->assertNotEmpty($this->fieldId, 'Custom field must be created in setUp');

        $response = $this->getJson('/api/v1/field-details/Lead/' . $this->fieldId, $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_update_custom_field_label(): void
    {
        $this->assertNotEmpty($this->fieldId, 'Custom field must be created in setUp');

        $response = $this->putJson('/api/v1/field-update', [
            'data' => [
                'id' => $this->fieldId,
                'fieldlabel' => 'Custom Test Field Updated',
                'modulename' => 'Lead',
            ]
        ], $this->headers());

        $response->assertStatus(200)->assertJson(['status' => true]);
    }

    public function test_delete_custom_field(): void
    {
        $this->assertNotEmpty($this->fieldId, 'Custom field must be created in setUp');

        $response = $this->deleteJson('/api/v1/field/' . $this->fieldId, [], $this->headers());
        $response->assertStatus(200)->assertJson(['status' => true]);
    }
}
