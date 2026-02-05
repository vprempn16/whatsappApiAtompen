<?php

namespace App\Modules\Api\V1\Mailbox\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Mail\MailboxService;
use App\Traits\ResultTrait;

class SentController extends Controller
{
    use ResultTrait;

    protected $mailboxService;

    public function __construct(MailboxService $mailboxService)
    {
        $this->mailboxService = $mailboxService;
    }

    /**
     * List Sent Emails
     */
    public function index(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();
        
        $filters = $request->only(['mail_server_id', 'search']);
        $perPage = $request->input('per_page', 20);
        
        try {
            $result = $this->mailboxService->getSentMails($orgId, $userId, $filters, $perPage);
            return $this->success($result, 'Sent mails retrieved');
        } catch (\Throwable $e) {
            return $this->error('Failed to retrieve sent mails: ' . $e->getMessage());
        }
    }

    /**
     * Show Single Sent Email
     */
    public function show($id)
    {
        $orgId = auth()->user()->organization_id;

        try {
            $mail = $this->mailboxService->getSentMail($id, $orgId);
            return $this->success($mail, 'Sent mail details');
        } catch (\Throwable $e) {
            return $this->error('Sent mail not found or error retrieving: ' . $e->getMessage());
        }
    }
}
