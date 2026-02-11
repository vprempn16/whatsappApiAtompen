<?php

namespace App\Modules\Api\V1\Mail\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Mail\MailService;
use App\Traits\ResultTrait;
use Illuminate\Support\Facades\Validator;

class MailImapController extends Controller
{
    use ResultTrait;

    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    /**
     * List all IMAP servers
     */
    public function index()
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId) {
            return $this->error('Organization not found');
        }

        try {
            $servers = $this->mailService->getAllImapServers($orgId);
            return $this->success($servers, 'IMAP servers fetched successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * Delete IMAP server
     */
    public function destroy($id)
    {
        $orgId = auth()->user()->organization_id;
        if (!$orgId) {
            return $this->error('Organization not found');
        }

        try {
            $this->mailService->deleteImapServer($id, $orgId);
            return $this->success([], 'IMAP server deleted successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
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
        $data = $request->input('data.values');
        if (!$data) {
            return $this->error('Invalid payload structure');
        }

        $validator = Validator::make($data, [
            'name' => 'required|string',
            'description' => 'nullable|string',
            'host' => 'required|string',
            'port' => 'required|integer',
            'encryption' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
            'folder' => 'required|string',
        ]);

        if ($validator->fails()) {
            $message = collect($validator->errors()->all())->implode(',');
            return $this->error($message);
        }

        $data = $validator->validated();
        $data['organization_id'] = $orgId;
        $data['created_by'] = $userId;

        try {
            $imap = $this->mailService->createImapServer($data);
            return $this->success($imap, 'IMAP server saved successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update($id, Request $request)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $data = $request->input('data.values');
        if (!$data) {
            return $this->error('Invalid payload structure');
        }

        $validator = Validator::make($data, [
            'name' => 'required|string',
            'description' => 'nullable|string',
            'host' => 'required|string',
            'port' => 'required|integer',
            'encryption' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string', // Optional on update
            'folder' => 'required|string',
	    'last_uid' =>  'nullable|integer',
	    'min_uid' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            $message = collect($validator->errors()->all())->implode(',');
            return $this->error($message);
        }

        $data = $validator->validated();
        $data['organization_id'] = $orgId;

        try {
            $imap = $this->mailService->updateImapServer($id, $data);
            return $this->success($imap, 'IMAP server updated successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    
    public function show($id)
    {
        $orgId = auth()->user()->organization_id;

        if (!$orgId) {
            return $this->error('Organization not found');
        }
        
        try {
            $server = $this->mailService->getImapServer($id, $orgId);
            return $this->success($server, 'IMAP server details fetched');
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
    // Inbox method removed - moved to MailboxController@syncAll as per refactoring plan
    /*
    public function inbox(Request $request, $id)
    {
        try {
            $limit = $request->get('limit', 20);
            $mails = $this->mailService->syncAllFolders($id, $limit);
            return $this->success($mails, 'Mailbox synced successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }
    */

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

