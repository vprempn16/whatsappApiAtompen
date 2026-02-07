<?php

namespace App\Modules\Api\V1\Mailbox\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\Api\V1\Mailbox\Models\MailboxFolder;
use App\Traits\ResultTrait;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use App\Services\Mail\MailboxService;
use Illuminate\Support\Facades\Validator;
class FolderController extends Controller
{
    use ResultTrait;

    protected $mailboxService;

    public function __construct(MailboxService $mailboxService)
    {
        $this->mailboxService = $mailboxService;
    }

    public function listByServer(string $mailServerId)
    {
        $orgId = auth()->user()->organization_id;

        $folders = $this->mailboxService->listFolders($orgId,$mailServerId);

        return $this->success($folders, 'Folders retrieved');
    }

    public function sync(Request $request) 
    {
        //dd($request->all());
        $orgId = auth()->user()->organization_id;
        $userId = auth()->id();

        $values = $request->input('data.values');
        if (!$values) {
            $values = $request->all();
        }

        $validator = Validator::make($values, [
            'mail_server_id' => 'required|uuid',
            'type' => 'nullable|string|in:Folder,Label'
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first());
        }

        $data = $validator->validated();
        
        try {
            $result = $this->mailboxService->syncMailboxStructure(
                $data['mail_server_id'],
                $orgId,
                $userId,
                $data['type'] ?? 'Folder'
            );
            return $this->success($result, 'Mailbox structure synced successfully');
        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $orgId = auth()->user()->organization_id;
        
        $userId = auth()->id();

     $values = $request->input('data.values');
        if (!$values) {
            return $this->error('Invalid payload structure');
        }
        
        $validator = Validator::make($values, [
            'mail_server_id' => 'required|uuid',
            'name' => 'required|string|max:100',
            'content' => 'required|string',
            'is_default' => 'boolean'
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }


        $folder = $this->mailboxService->createFolder($orgId, $userId, $data);

        return $this->success($folder, 'Folder created');
    }


    public function update(Request $request, $id)
    {
        $orgId = auth()->user()->organization_id;

        $values = $request->input('data.values');

        if (!$values) {
            return $this->error('Invalid payload structure');
        }

        $validated = Validator::make($values, [
            'name' => 'nullable|string|max:100',
            'icon' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'mail_server_id' => 'nullable|uuid'
        ])->validate();

        $folder = $this->mailboxService->updateFolder($id, $orgId, $validated);

        return $this->success($folder, 'Folder updated');
    }

    public function destroy($id)
    {
        $orgId = auth()->user()->organization_id;

        $validator = Validator::make($id, [
            'id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return $this->error(collect($validator->errors()->all())->implode(','));
        }

        $id = $validator->validated()['id'];    

        try {
            $this->mailboxService->deleteFolder($id, $orgId);
            return $this->success([], 'Folder deleted');
        } catch (\Throwable $e) {
            return $this->error('Failed to delete folder: ' . $e->getMessage());
        }
    }
}
