<?php

namespace App\Modules\Api\V1\Mail\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;
use App\Modules\Api\V1\Mail\Models\MailLog;
use App\Services\Mail\MailService;

class MailLogController extends ApiController
{
    protected $mailService;

    public function __construct(MailService $mailService)
    {
        $this->mailService = $mailService;
    }

    public function index($organizationId)
    {
        return MailLog::where('organization_id', $organizationId)
            ->where('deleted', 0)
            ->orderBy('created_at', 'desc')
            ->paginate(20);
    }

    public function show($id)
    {
        return MailLog::where('id', $id)
            ->where('deleted', 0)
            ->firstOrFail();
    }

    public function store(Request $request)
    {
        return MailLog::create([
            ...$request->all(),
            'created_at' => now(),
            'deleted' => 0
        ]);
    }

    public function destroy($id)
    {
        $log = MailLog::where('id', $id)->firstOrFail();
        $log->update([
            'deleted' => 1,
            'updated_at' => now()
        ]);

        return ['status' => 'deleted'];
    }

    /**
     * Get mails related to a specific module record.
     *
     * @param Request $request
     * @param string $module
     * @param string $recordId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMailsByRecord(Request $request, $module, $recordId)
{
    try {
        $orgId = auth()->user()->organization_id;
        $perPage = (int) $request->input('per_page', 20);

        $emails = $this->mailService->getMailsByRecord($orgId, $module, $recordId, $perPage);

        return $this->success($emails, 'Mails retrieved successfully');
    } catch (\Throwable $e) {
        return $this->error('Failed to retrieve mails: ' . $e->getMessage());
    }
}
}
