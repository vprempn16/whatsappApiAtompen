<?php

namespace Tests\ApiTests\Phase4;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Step13_AssetCRUDTest extends BaseApiTest
{
    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $email = $this->getState('admin_email');
        $password = $this->getState('admin_password');

        if (!$email || !$password) {
            $this->markTestSkipped('Admin credentials not found in state. Run Phase 1 first.');
        }

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => $password
        ]);

        $this->token = $response->json('data.token') ?? '';

        if (empty($this->token)) {
            $this->markTestSkipped('Failed to authenticate admin for Phase 4.');
        }

        $user = \App\Models\User::where('email', $email)->first();
        if ($user) {
            $this->actingAs($user, 'sanctum');
        }
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    public function test_upload_asset_to_lead(): void
    {
        // 1. Get a valid Lead ID from Phase 2
        $leadId = $this->getState('last_lead_id');
        if (!$leadId) {
            $this->markTestSkipped('No Lead ID found. Phase 2 must run first.');
        }

        // 2. Prepare dummy file
        $file = UploadedFile::fake()->create('test_document.pdf', 100);

        // 3. Prepare Multi-part payload
        // The controller expects upload[index][title] and upload[index][file]
        $payload = [
            'parentModule' => 'Lead',
            'parentId' => $leadId,
            'upload' => [
                0 => [
                    'title' => 'Test Asset for Lead',
                    'description' => 'A test PDF file uploaded via API',
                    'local_id' => 'local_123',
                    'file' => $file
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/Asset/new', $payload, $this->headers());

        $status = $response->status() === 200 && $response->json('status') === true ? 'PASSED' : 'FAILED';
        $assets = $response->json('data');
        $assetId = $assets[0]['id'] ?? null;

        $this->report('Upload Asset to Lead', $status, 'Asset', [
            'id' => $assetId,
            'title' => 'Test Asset for Lead',
            'parentId' => $leadId
        ]);

        if ($status === 'FAILED') {
            $response->dump();
        }

        $response->assertStatus(200);
        $this->assertTrue($response->json('status'));
        $this->assertNotEmpty($assetId);

        $this->saveState('last_asset_id', $assetId);
    }

    public function test_get_parent_assets(): void
    {
        $leadId = $this->getState('last_lead_id');
        if (!$leadId) {
            $this->markTestSkipped('No Lead ID found.');
        }

        // We use the generic related records endpoint: {module}/{id}/{relatedmodule}/records
        $response = $this->getJson("/api/v1/Lead/{$leadId}/Asset/records", $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $count = count($response->json('data') ?? []);

        $this->report('Get Lead Assets', $status, 'Asset', [
            'parentId' => $leadId,
            'assetCount' => $count
        ]);

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'));
    }
}
