<?php

namespace App\Modules\Api\V1\Mail\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Mail\MailService;
use App\Traits\ResultTrait;

class MailImapController extends Controller
{
    use ResultTrait;

    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * Save / Update IMAP configuration
     */
    public function store(Request $request)
{
    $orgId  = auth()->user()->organization_id;
    $userId = auth()->id();

    if (!$orgId) {
        return $this->error('Organization not found');
    }

    $data = $request->only([
        'mail_server_id',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'folder',
    ]);

    if (empty($data['mail_server_id'])) {
        return $this->error('Mail server id is required');
    }

    $data['organization_id'] = $orgId;
    $data['created_by'] = $userId;

    try {
        $imap = $this->mailService->saveImapServer($data);
        return $this->success($imap, 'IMAP server saved successfully');
    } catch (\Throwable $e) {
        return $this->error($e->getMessage());
    }
}

    /**
     * Test IMAP connection
     */
    public function connect($id)
    {
        try {
            $this->mailService->connectImap($id);
            return $this->success([], 'IMAP connected successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Fetch inbox mails
     */
    public function inbox(Request $request, $id)
    {
        try {
            
            $limit = $request->get('limit', 20);
           
            $mails = $this->mailService->fetchImapInbox($id, $limit);

            return $this->success($mails, 'Inbox fetched successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Search emails
     */
    public function search(Request $request, $id)
    {
        try {
            $filters = $request->only(['keyword', 'from', 'date_from', 'date_to', 'is_read', 'limit']);
            $mails = $this->mailService->searchMails($id, $filters);
            return $this->success($mails, 'Mails searched successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Get all emails in a thread
     */
    public function showThread(Request $request, $id, $threadId)
    {
        try {
            $mails = $this->mailService->getThreadMails($id, $threadId);
            return $this->success($mails, 'Thread fetched successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Track email opening via tracking pixel
     */
    public function trackOpen($token)
    {
        try {
            $this->mailService->recordOpen($token);
        } catch (\Throwable $e) {
            // Silently fail to not break the email image display
            \Log::error("Mail tracking failed: " . $e->getMessage());
        }

        // Return 1x1 transparent PNG
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        return response($pixel, 200)->header('Content-Type', 'image/png');
    }
}

