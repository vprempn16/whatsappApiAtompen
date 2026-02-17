<?php 

namespace App\Modules\Api\V1\Mail\Controllers;

use App\Http\Controllers\ApiController;
use App\Services\Mail\MailService;
use Illuminate\Http\Request;
use App\Services\Mail\MailObject;
use App\Services\CRM\RecordObject;
use App\Models\WorkflowQueue;
use Illuminate\Support\Str;

class MailSendController extends ApiController
{
    protected $service;

    public function __construct(MailService $service)
    {
        $this->service = $service;
    }

    public function sendold(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $result = $this->service->sendMail(
            $orgId,
            $request->to,
            $request->subject,
            $request->body,
            $userId
        );

        if ($result['status'] === 'failed') {
            return $this->error('Failed to send mail');
        }

        return $this->success([], 'Mail sent successfully');
    }
    public function send(Request $request)
    {
	    $orgId = auth()->user()->organization_id;
	    
	    if (!$orgId) return $this->error('Organization not found');

	    $result = $this->service->sendMail($request->all());
	    
	    if($result['status'] === false) {
            	return $this->error('Failed to send mail');
	    }

	    return $this->success([], 'Mail sent successfully');
    }

    public function sendFromRecord(Request $request, string $module, string $recordId)
    {
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();

        if (!$orgId) {
            return $this->error('Organization not found');
        }

        $values = $request->input('data.values');

        if (!$values || !isset($values['server_id'])) {
            return $this->error('Server ID is required');
        }

        if (!isset($values['recipients']) || empty($values['recipients'])) {
            return $this->error('Recipients are required');
        }

        // Handle File Uploads
        $attachments = [];
        if ($request->hasFile('attachments')) {
            $folderPath = 'mail-attachments/' . $orgId;
            if (!\Illuminate\Support\Facades\Storage::disk('mail_attachments')->exists($folderPath)) {
                \Illuminate\Support\Facades\Storage::disk('mail_attachments')->makeDirectory($folderPath);
            }

            foreach ($request->file('attachments') as $file) {
                 $path = $file->store($folderPath, 'mail_attachments');
                 $attachments[] = [
                     'path' => \Illuminate\Support\Facades\Storage::disk('mail_attachments')->path($path),
                     'name' => $file->getClientOriginalName(),
                     'original_name' => $file->getClientOriginalName(),
                     'mime_type' => $file->getMimeType(),
                     'size' => $file->getSize(),
                     'disk' => 'mail_attachments'
                 ];
            }
        }
        
        // Add attachments to values if any
        if (!empty($attachments)) {
            $values['attachments'] = $attachments;
        }

        // Create Workflow Queue Item
        try {
            WorkflowQueue::create([
                'id' => Str::uuid(),
                'organization_id' => $orgId,
                'user_id' => $userId,
                'type' => 'send_email',
                'params' => $values,
                'status' => 'pending',
                'priority' => $values['priority'] ?? 0,
                'scheduled_at' => !empty($values['scheduled_at']) ? $values['scheduled_at'] : now(),
                'related_module' => $module,
                'related_record_id' => $recordId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->success([], 'Email has been queued for sending.');

        } catch (\Throwable $e) {
            return $this->error('Failed to queue email: ' . $e->getMessage());
        }
    }
    public function getEmailAddress(string $module, string $recordId)
    {
        $record = RecordObject::make($module, $recordId, [], 'DetailView', false);
        $result = $record->getEmailAddress($module, $recordId);

        return $this->success($result, 'Email address retrieved');
    }
}
