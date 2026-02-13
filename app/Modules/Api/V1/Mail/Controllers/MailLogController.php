<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Controllers\ApiController;

class MailLogController extends ApiController
{
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

}
