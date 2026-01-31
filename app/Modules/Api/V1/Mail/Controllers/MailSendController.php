<?php 

namespace App\Modules\Api\V1\Mail\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Mail\MailService;
use App\Traits\ResultTrait;
use Illuminate\Http\Request;

class MailSendController extends Controller
{
    use ResultTrait;

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
            return $this->error($result['error']);
        }

        return $this->success([], 'Mail sent successfully');
    }
    public function send(Request $request)
    {
	    $orgId = auth()->user()->organization_id;
	    
	    if (!$orgId) return $this->error('Organization not found');

	    $result = $this->service->sendMail($request->all());
	    
	    if($result['status'] === false) {
            	return $this->error($result['error']);
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

        try {
            $record = \App\Services\CRM\RecordObject::make($module, $recordId);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }

        if (!$record) {
            return $this->error('Record not found');
        }

        $results = [];

	foreach ($values['recipients'] as $fieldName) {
		$crmField = DB::table('crm_fields')
			->where('modulename', $module)
			->where('apifieldname', $fieldName)
			->first('fieldname');

		if(!$crmField){
			$results[] = [
				'crm_field' => $fieldName,
				'success'   => false,
				'error'     => "Field Name {$fieldName} not found"
			];
			continue;
		}
		$fieldName = $crmField->fieldname;
            $to = $record->{$fieldName} ?? null;

            if (!$to) {
                $results[] = [
                    'recipient_field' => $fieldName,
                    'to' => null,
                    'success' => false,
                    'error' => 'Recipient email not found'
                ];
                continue;
            }

            $mailData = [
                'server_id' => $values['server_id'],
                'to' => $to,
                'subject' => $values['subject'] ?? 'No Subject',
                'body' => $values['body'] ?? '',
                'cc' => $values['cc'] ?? [],
                'bcc' => $values['bcc'] ?? []
            ];

            $result = $this->service->sendMail($mailData, $module, $recordId);

            $results[] = [
                'recipient_field' => $fieldName,
                'to' => $to,
                'success' => $result['status'] ?? false,
                'response' => $result['status'] ? $result['data'] : null,
                'error' => $result['status'] ? null : ($result['error'] ?? 'Mail failed')
            ];
        }

        return $this->success([
            'values' => $results
        ], 'Emails processed');
    }
}
