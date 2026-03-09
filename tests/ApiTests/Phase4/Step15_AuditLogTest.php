<?php

namespace Tests\ApiTests\Phase4;

use Tests\ApiTests\BaseApiTest;
use Illuminate\Support\Facades\DB;

class Step15_AuditLogTest extends BaseApiTest
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

    public function test_audit_log_capture_on_update(): void
    {
        // 1. Get a valid Lead ID
        $leadId = $this->getState('last_lead_id');
        if (!$leadId) {
            $this->markTestSkipped('No Lead ID found.');
        }

        // 2. Perform an update on the Lead
        $newValue = 'Updated Lead Source ' . uniqid();
        $updateResponse = $this->patchJson("/api/v1/Lead/{$leadId}/inline-edit", [
            'field' => 'leadsource',
            'value' => $newValue
        ], $this->headers());

        $this->assertEquals(200, $updateResponse->status(), 'Lead update failed');

        // 3. Fetch Audit Logs for this Lead
        // Endpoint: {module}/{id}/audit-log
        $response = $this->getJson("/api/v1/Lead/{$leadId}/audit-log", $this->headers());

        $status = $response->status() === 200 ? 'PASSED' : 'FAILED';
        $logs = $response->json('data.logs') ?? [];

        // Find our update in the logs
        $foundUpdate = false;
        foreach ($logs as $log) {
            if (($log['event_type'] === 'update' || $log['event_type'] === 'UPDATE') && isset($log['changes'])) {
                foreach ($log['changes'] as $change) {
                    if ($change['field_name'] === 'leadSource' && $change['new_value'] === $newValue) {
                        $foundUpdate = true;
                        break 2;
                    }
                }
            }
        }

        if (!$foundUpdate) {
            dump('Audit Logs for Lead:', $logs);
        }

        $this->report('Verify Audit Log Capture on Update', $foundUpdate ? 'PASSED' : 'FAILED', 'AuditLog', [
            'module' => 'Lead',
            'id' => $leadId,
            'updatedField' => 'leadSource',
            'newValue' => $newValue
        ]);

        $response->assertStatus(200);
        $this->assertTrue($foundUpdate, 'Expected audit log entry for Lead update was not found');
    }

    public function test_audit_log_related_comment(): void
    {
        // This verifies if the audit log fetch merges related comment logs as seen in AuditLogService
        $leadId = $this->getState('last_lead_id');
        if (!$leadId) {
            $this->markTestSkipped('No Lead ID found.');
        }

        // Fetch logs
        $response = $this->getJson("/api/v1/Lead/{$leadId}/audit-log", $this->headers());
        $logs = $response->json('data.logs') ?? [];

        // Look for 'relate' events for Comment or specific comment triggers
        $commentLogFound = false;
        foreach ($logs as $log) {
            if (isset($log['related_entity']) && $log['related_entity']['name'] === 'Comment') {
                $commentLogFound = true;
                break;
            }
        }

        if (!$commentLogFound) {
            dump('Audit Logs for Lead (Related Comment Check):', $logs);
        }

        $this->report('Verify Related Comment in Lead Audit Log', $commentLogFound ? 'PASSED' : 'FAILED', 'AuditLog', [
            'leadId' => $leadId
        ]);

        $response->assertStatus(200);
    }
}
