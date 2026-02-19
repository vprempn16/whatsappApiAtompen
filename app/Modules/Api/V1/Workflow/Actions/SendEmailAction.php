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
     *
     * @param WorkflowQueue $job
     * @return void
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
}
