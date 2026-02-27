<?php

namespace App\Modules\Api\V1\Workflow\Actions;

use Illuminate\Support\Facades\Log;
use App\Models\WorkflowQueue;
use App\Services\Mail\MailboxService;

class SendEmailAction implements WorkflowActionInterface
{
    protected $mailboxService;

    public function __construct(MailboxService $mailboxService)
    {
        $this->mailboxService = $mailboxService;
    }

    /**
     * Execute the workflow action.
     */
    public function handle(WorkflowQueue $job): void
    {
        $params = $job->params;
        $context = $params['context'] ?? null;

        Log::info("SendEmailAction: Handling job {$job->id}", [
            'context' => $context,
            'recipients_before' => $params['recipients'] ?? []
        ]);

        if ($context && isset($params['recipients'])) {
            foreach ($params['recipients'] as $idx => &$recipient) {
                Log::info("SendEmailAction: Processing recipient {$idx}", ['before' => $recipient]);
                if (empty($recipient['recordId'])) {
                    $recipient['recordId'] = $context['record_id'];
                }
                if (empty($recipient['module_name'])) {
                    $recipient['module_name'] = $context['module'];
                }
                Log::info("SendEmailAction: After processing recipient {$idx}", ['after' => $recipient]);
            }
            unset($recipient);
        }

        Log::info("SendEmailAction: Final params for MailboxService", [
            'subject' => $params['subject'] ?? 'MISSING',
            'has_body' => isset($params['body']),
            'server_id' => $params['server_id'] ?? 'MISSING',
            'recipients_after' => $params['recipients'] ?? []
        ]);
        $this->mailboxService->processAndSendComplexRecipients(
            $params,
            $job->organization_id,
            $job->user_id
        );
    }

    /**
     * Validate and prepare Email action parameters.
     */
    public function save(array $params, string $module, string $orgId): array
    {
        // 1. Validate Mail Server ID
        $serverId = $params['server_id'] ?? null;
        if (!$serverId) {
            throw new \Exception("Mail Server ID is required.");
        }

        $server = \DB::table('mail_servers')
            ->where('id', $serverId)
            ->where('organization_id', $orgId)
            ->where('deleted', 0)
            ->first();

        if (!$server) {
            throw new \Exception("Invalid Mail Server ID or server does not belong to your organization.");
        }

        // 2. Validate Subject and Body
        if (empty($params['subject'])) {
            throw new \Exception("Email subject is required.");
        }
        if (empty($params['body'])) {
            throw new \Exception("Email body is required.");
        }

        // 3. Validate Recipients (fields must exist in module)
        $recipients = $params['recipients'] ?? [];
        if (empty($recipients)) {
            throw new \Exception("At least one recipient must be specified.");
        }

        foreach ($recipients as $recipient) {
            $field = $recipient['field'] ?? null;
            $recModule = $recipient['module_name'] ?? $module;

            if ($field && !str_contains($field, '@')) { // If it's a field name, not a hardcoded email
                if (
                    !\Schema::hasColumn(\Str::snake(\Str::plural($recModule)), $field) &&
                    !\Schema::hasColumn(\Str::snake($recModule), $field)
                ) {
                    throw new \Exception("Recipient field '{$field}' does not exist in module '{$recModule}'.");
                }
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
                'name' => 'server_id',
                'label' => 'Mail Server',
                'type' => 'reference',
                'module' => 'MailServer',
                'required' => true,
            ],
            [
                'name' => 'subject',
                'label' => 'Email Subject',
                'type' => 'string',
                'required' => true,
            ],
            [
                'name' => 'body',
                'label' => 'Email Body',
                'type' => 'textarea', // Or 'richtext' if your frontend supports it
                'required' => true,
            ],
            [
                'name' => 'recipients',
                'label' => 'Recipients',
                'type' => 'module_fields',
                'field_types' => ['email'],
                'multiple' => true,
                'required' => true,
            ]
        ];
    }
}