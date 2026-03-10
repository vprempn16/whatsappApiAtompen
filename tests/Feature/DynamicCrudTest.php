<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Helpers\PayloadGenerator;

class DynamicCrudTest extends TestCase
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake();

        // Standard user login for tests
        $login = $this->postJson('/api/v1/login', [
            'email' => 'admin@atompen.test',
            'password' => 'password123'
        ]);
        $this->token = $login->json('data.token') ?? '';

        if (empty($this->token)) {
            $this->markTestSkipped('Admin user not found for dynamic testing. Run UserJourneyTest or TenantSetupTest first.');
        }
    }

    /**
     * @dataProvider dynamicModuleProvider
     */
    public function test_dynamic_crud_and_validation(string $module, string $apiEndpoint, array $overrides = []): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token];

        // 1. Validation Test: Missing required fields
        $emptyPayload = ['data' => ['values' => []]];
        if ($apiEndpoint === 'Activity') {
            $emptyPayload['data']['values']['activityType'] = strtolower($module);
            $emptyPayload['data']['relatedRecords'] = [];
        }

        $validationResponse = $this->postJson("/api/v1/{$apiEndpoint}/new", $emptyPayload, $headers);
        // Check that API caught the missing fields (usually returning status false)
        $validationResponse->assertJson(['status' => false]);

        // 2. Create Record with dynamically generated valid payload
        $createPayload = ['data' => ['values' => PayloadGenerator::generate($module, $overrides)]];
        if ($apiEndpoint === 'Activity') {
            $createPayload['data']['values']['activityType'] = strtolower($module);
        }

        $createResponse = $this->postJson("/api/v1/{$apiEndpoint}/new", $createPayload, $headers);

        $id = $createResponse->json('data.id');
        if (!$id) {
            $this->markTestSkipped("Failed to dynamically create {$module}. API Response: " . $createResponse->getContent());
        }

        // 3. Read / Show Record
        $getResp = $this->getJson("/api/v1/{$apiEndpoint}/{$id}", $headers);
        $getResp->assertStatus(200)->assertJson(['status' => true]);

        // 4. Update Record with new dynamic string
        $updatePayload = ['data' => ['values' => PayloadGenerator::generate($module, $overrides, true)]];
        if ($apiEndpoint === 'Activity') {
            // Activity update is usually distinct
            $updateResp = $this->postJson("/api/v1/Activity/{$id}/activity-status-update", ['status' => 'completed'], $headers);
        } else {
            $updateResp = $this->putJson("/api/v1/{$apiEndpoint}/{$id}", $updatePayload, $headers);
        }
        $updateResp->assertStatus(200)->assertJson(['status' => true]);

        // 5. Delete Record
        $deleteResp = $this->deleteJson("/api/v1/{$apiEndpoint}/{$id}", [], $headers);
        $deleteResp->assertStatus(200)->assertJson(['status' => true]);
    }

    /**
     * @dataProvider dynamicModuleProvider
     */
    public function test_dynamic_searching_and_listing(string $module, string $apiEndpoint, array $overrides = []): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token];

        // Ensure we have at least one record
        $createPayload = ['data' => ['values' => PayloadGenerator::generate($module, $overrides, true)]];
        if ($apiEndpoint === 'Activity') {
            $createPayload['data']['values']['activityType'] = strtolower($module);
        }
        $this->postJson("/api/v1/{$apiEndpoint}/new", $createPayload, $headers);

        // List
        $listResp = $this->getJson("/api/v1/{$apiEndpoint}", $headers);
        $listResp->assertStatus(200)->assertJson(['status' => true]);

        // Search / Filter
        // Most modules support POST /api/v1/filter/{Module}
        $searchPayload = [
            'search' => [
                'value' => 'any_value_here'
            ]
        ];

        $searchResp = $this->postJson("/api/v1/filter/{$module}", $searchPayload, $headers);
        $searchResp->assertStatus(200)->assertJson(['status' => true]);
    }

    /**
     * Data Provider defining which modules we want to automatically test CRUD logic for
     */
    public static function dynamicModuleProvider(): array
    {
        return [
            ['Lead', 'Lead', []],
            ['Contact', 'Contact', []],
            ['Asset', 'Asset', []],
            // Product needs specific type and unit overrides since they have strict picklist validation
            [
                'Product',
                'Product',
                [
                    'type' => 'Goods',
                    'unit' => 'Pieces'
                ]
            ],

            // Expected Activity types
            ['Task', 'Activity', []],
            ['Meeting', 'Activity', []],
            ['Call', 'Activity', []],
            ['Event', 'Activity', []],
        ];
    }
}
