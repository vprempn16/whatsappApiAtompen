<?php

namespace App\Modules\Api\V1\Workflow\Actions;

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
        
        $this->mailboxService->processAndSendComplexRecipients(
            $job->params,
            $job->organization_id,
            $job->user_id
        );
    }
}
