<?php

namespace Tests\ApiTests\Phase1;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Support\Str;

class Step07_NonAdminAccessTest extends BaseApiTest
{
    private string $adminToken = '';
    private string $managerToken = '';
    private string $executiveToken = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Admin Token
        $response = $this->postJson('/api/v1/login', [
            'email' => $this->getState('admin_email'),
            'password' => $this->getState('admin_password')
        ]);
        $this->adminToken = $response->json('data.token') ?? '';

        // Manager Token
        $response = $this->postJson('/api/v1/login', [
            'email' => $this->getState('user_manager_email'),
            'password' => $this->getState('user_manager_password')
        ]);
        $this->managerToken = $response->json('data.token') ?? '';

        // Executive Token
        $response = $this->postJson('/api/v1/login', [
            'email' => $this->getState('user_executive_email'),
            'password' => $this->getState('user_executive_password')
        ]);
        $this->executiveToken = $response->json('data.token') ?? '';
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_manager_access_to_lead_fields(): void
    {
        // Get Lead fields as Sales Manager using 'headers' (ListView)
        // Read-only fields should be visible in ListView/DetailView
        $response = $this->getJson('/api/v1/Lead/headers', $this->headers($this->managerToken));

        $fields = collect($response->json('data.fields'));
        $leadScoreField = $fields->where('fieldname', 'leadScore')->first();
        // $leadSourceField = $fields->where('fieldname', 'leadSourceCode')->first(); // This line was removed in the instruction

        // Lead Score should be visible (invisible=0 or present)
        $visible = $leadScoreField !== null;
        $status = $visible ? 'PASSED' : 'FAILED';
        $this->report('Manager field visibility (leadScore)', $status, 'Lead', ['visible' => $visible]);

        // Lead Source Code should be ReadOnly
        // Wait, getApiFormFields filters out fields if you can't write to them in CreateView?
        // Let's check FieldModelManager again.
        // Line 187: in CreateView, if !canWriteField, continue.
        // So if it's read-only, it might NOT appear in CreateView/EditView but SHOULD appear in DetailView.

        $response->assertStatus(200);
        $this->assertTrue($visible, 'Manager should be able to see the leadScore field in list view');
    }

    public function test_executive_hidden_field_lead(): void
    {
        // Get Lead fields as Sales Executive
        $response = $this->getJson('/api/v1/Lead/new/forms', $this->headers($this->executiveToken));

        $fields = collect($response->json('data.fields'));
        $leadScoreField = $fields->where('fieldname', 'leadScore')->first();

        // Lead Score should be HIDDEN (not in the list)
        $hidden = $leadScoreField === null;
        $status = $hidden ? 'PASSED' : 'FAILED';
        $this->report('Executive field hidden (leadScore)', $status, 'Lead', ['hidden' => $hidden]);

        $response->assertStatus(200);
        $this->assertTrue($hidden, 'Executive should NOT see the leadScore field');
    }

    public function test_unauthorized_inline_edit(): void
    {
        // Sample Lead ID if needed, but we can test with a nonexistent ID to check permission first
        $leadId = (string) Str::uuid();

        // Executive tries to edit leadScore (which is hidden/no-write)
        $response = $this->patchJson("/api/v1/Lead/{$leadId}/inline-edit", [
            'field' => 'leadScore',
            'value' => 100
        ], $this->headers($this->executiveToken));

        // Expect 403 or error message
        $forbidden = $response->status() === 403 || $response->json('status') === false;
        $status = $forbidden ? 'PASSED' : 'FAILED';
        $this->report('Executive unauthorized inline edit', $status, 'Lead', ['field' => 'leadScore']);

        $this->assertTrue($forbidden, 'Executive should be prevented from editing a hidden/read-only field');
    }
}
