<?php

namespace App\Modules\Api\V1\Workflow\Actions;

use App\Models\WorkflowQueue;
use App\Services\Whatsapp\WhatsappService; // Assuming this exists or path needs adjustment
use Illuminate\Support\Facades\Log;

class SendWhatsAppAction implements WorkflowActionInterface
{
    protected $whatsappService;

    public function __construct()
    {
        // Path/Service injection might need adjustment based on actual WhatsApp service location
        // $this->whatsappService = app(\App\Services\Whatsapp\WhatsappService::class);
    }

    public function handle(WorkflowQueue $job): void
    {
        $params = $job->params;
        $context = $params['context'] ?? null;

        Log::info("SendWhatsAppAction: Handling job {$job->id}", [
            'context' => $context,
            'params' => $params
        ]);

        if (!$context) {
            Log::error("SendWhatsAppAction: Missing context for job {$job->id}");
            return;
        }

        try {
            $orgId = $job->organization_id;
            $channelId = $params['channel_id'] ?? null;
            $type = $params['type'] ?? 'template'; // 'message' or 'template'
            $details = $params['details'] ?? [];

            if (!$channelId) {
                Log::error("SendWhatsAppAction: Missing channel_id for job {$job->id}");
                return;
            }

            $waService = new \App\Services\WhatsApp\WhatsAppApiService($orgId, $channelId);
            $record = \App\Services\CRM\RecordObject::make($context['module'], $context['record_id']);

            // 1. Resolve Recipient Number
            $to = null;
            $recipients = $params['recipients'] ?? [];
            if (empty($recipients)) {
                // Fallback to record mobile if no recipient field defined
                $to = preg_replace('/[^0-9]/', '', $record->mobile ?? $record->phone ?? '');
            } else {
                $field = $recipients[0]; // Take first field name
                $to = preg_replace('/[^0-9]/', '', $record->{$field} ?? '');
            }

            if (!$to) {
                Log::error("SendWhatsAppAction: Could not resolve recipient phone number for job {$job->id}");
                return;
            }

            $logData = [
                'organization_id' => $orgId,
                'whatsapp_channel_id' => $channelId,
                'crm_module' => $context['module'],
                'related_module' => $context['module'],
                'related_id' => $context['record_id'],
            ];

            // 2. Handle Action Type
            if ($type === 'template') {
                $templateId = $details['templateId'] ?? $params['template_uuid'] ?? null;
                $templateName = $details['templateName'] ?? $params['template_name'] ?? null;
                $language = $details['language'] ?? $params['language'] ?? 'en_US';

                if (!$templateId) {
                    Log::error("SendWhatsAppAction: Missing templateId for job {$job->id}");
                    return;
                }

                $buildResult = $waService->buildTemplateComponents($orgId, $templateId, $context['module'], $record);

                if (!$buildResult['status']) {
                    Log::error("SendWhatsAppAction: Component build failed: " . $buildResult['message']);
                    return;
                }

                $waService->sendTemplateMessage(
                    $to,
                    $templateName ?? 'unknown',
                    $language,
                    $buildResult['components'],
                    array_merge($logData, ['type' => 'template'])
                );

            } else {
                // Default to raw text message
                $text = $details['text'] ?? "Notification from Workflow";
                $waService->sendTextMessage($to, $text, array_merge($logData, ['type' => 'text']));
            }

            Log::info("SendWhatsAppAction: Successfully executed for job {$job->id}");

        } catch (\Throwable $e) {
            Log::error("SendWhatsAppAction Failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate and prepare WhatsApp action parameters.
     */
    public function save(array $params, string $module, string $orgId): array
    {
        // 1. Validate Channel ID
        $channelId = $params['channel_id'] ?? null;
        if (!$channelId) {
            throw new \Exception("WhatsApp Channel ID is required.");
        }

        $channel = \DB::table('whatsapp_channels')
            ->where('id', $channelId)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$channel) {
            throw new \Exception("Invalid WhatsApp Channel ID or channel does not belong to your organization.");
        }

        // 2. Validate Type (message or template)
        $type = $params['type'] ?? 'template';
        $details = $params['details'] ?? [];

        if ($type === 'template') {
            $templateId = $details['templateId'] ?? null;
            if (!$templateId) {
                throw new \Exception("Template ID is required for WhatsApp template messages.");
            }

            // Verify template exists for this channel
            $template = \DB::table('whatsapp_templates')
                ->where('id', $templateId)
                ->where('whatsapp_channel_id', $channelId)
                ->first();

            if (!$template) {
                throw new \Exception("Template not found for the selected WhatsApp channel.");
            }
        }

        // 3. Validate Recipients (fields must exist in module)
        $recipients = $params['recipients'] ?? [];
        if (empty($recipients)) {
            throw new \Exception("At least one recipient field must be specified.");
        }

        foreach ($recipients as $field) {
            if (
                !\Schema::hasColumn(\Str::snake(\Str::plural($module)), $field) &&
                !\Schema::hasColumn(\Str::snake($module), $field)
            ) {
                // Fallback: check if the module table has the column
                // In some systems, it might be 'leads' for 'Lead'
                throw new \Exception("Recipient field '{$field}' does not exist in module '{$module}'.");
            }
        }

        return $params;
    }

    /**
     * Define the dynamic fields required by this action type.
     *
     * @return array
     */
    public function getParamsFields(): array
    {
        return [
            [
                'name' => 'channel_id',
                'label' => 'WhatsApp Channel',
                'type' => 'reference',
                'module' => 'WhatsAppChannel',
                'required' => true,
            ],
            [
                'name' => 'type',
                'label' => 'Message Type',
                'type' => 'picklist',
                'options' => ['template' => 'Template', 'message' => 'Text Message'],
                'required' => true,
                'default' => 'template',
            ],
            [
                'name' => 'recipients',
                'label' => 'Recipient Phone Field',
                'type' => 'module_fields',
                'field_types' => ['phone', 'mobile'],
                'multiple' => true,
                'required' => true,
            ],
            [
                'name' => 'details',
                'label' => 'Message Details',
                'type' => 'json', // Front-end should handle template parsing via details block
                'required' => true,
            ],
        ];
    }
}
