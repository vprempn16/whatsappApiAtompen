<?php

namespace App\Modules\Api\V1\Asset\Controllers;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use App\Services\GoogleDriveService;
use App\Services\CRM\RecordObject;
use App\Modules\Api\V1\Folder\Models\Folder;
use App\Services\PermissionService;

class AssetController extends ApiController
{
    public function createAssetDoc(Request $request)
    {
		 $user = auth()->user();
        if (!$user) {
            throw new \Exception("Unauthenticated user");
        }

        $permissionService = new PermissionService($user);
		 $action = 'view';
        if (!$permissionService->hasPermission('Asset', $action)) {
            throw new \Exception(
                "Unauthorized: Module permission denied for {$action} on Asset"
            );
        }

        Log::info("Asset upload initiated", [
            'user_id' => auth()->user()->id,
            'inputs'  => $request->all()
        ]);

        if (!$request->has('upload')) {
            return $this->error('No files uploaded');
        }

	try {
		$googleDrive = app(GoogleDriveService::class);

		$organizationId = auth()->user()->organization_id ?? 'unknown_org';
		// Security: Filter folder by organization
		$folderDef = Folder::where('is_default', 1)
			->where('organization_id', $organizationId)
			->value('folder_name') ?? 'N/A';

		// Step 1: Get or create the organization folder
		$orgFolderId = $googleDrive->getOrCreateFolder($organizationId);

		// Step 2: Inside organization folder, get or create the module folder
		$folderId = $googleDrive->getOrCreateFolder($folderDef, $orgFolderId);

		$uploads = $request->input('upload', []);
		$entities = [];

		$parentModule = $request->input('parentModule');
		$parentId     = $request->input('parentId');

		$parentColumns = [
			'Invoice' => 'invoiceId',
			'Contact' => 'contactId',
			'Product' => 'productId',
			'Lead' => 'leadId',
			'Activity' => 'activityId',
		];

		$dynamicData = [];
		if (isset($parentColumns[$parentModule]) && !empty($parentId)) {
			// Security: Validate that parent record belongs to user's organization
			try {
				$parentRecord = RecordObject::make($parentModule, $parentId);
				if (isset($parentRecord->organization_id) && $parentRecord->organization_id !== $organizationId) {
					return $this->error('Parent record does not belong to your organization');
				}
				$dynamicData[$parentColumns[$parentModule]] = $parentId;
			} catch (\Exception $e) {
				return $this->error('Invalid parent record');
			}
		}

		$files = $request->file('upload', []);

		foreach ($uploads as $index => $uploadItem) {
			$file = $files[$index]['file'] ?? null;

			if (!$file instanceof UploadedFile || !$file->isValid()) {
				Log::warning("Invalid or missing file skipped", ['index' => $index]);
				continue;
			}

			$title = $uploadItem['title'] ?? null;
			if (empty($title)) continue;

			$description = $uploadItem['description'] ?? null;
			$localId     = $uploadItem['local_id'] ?? null;

			// Step 3: Upload inside the org/subfolder
			$uploadedFile = $googleDrive->upload($file, $folderId);

			// Security: Validate folderId belongs to organization
			$requestedFolderId = $request->input('folderId');
			$finalFolderId = $folderId;
			if ($requestedFolderId && $requestedFolderId !== $folderId) {
				// Verify the requested folder belongs to the organization
				$folderExists = Folder::where('id', $requestedFolderId)
					->where('organization_id', $organizationId)
					->exists();
				if ($folderExists) {
					$finalFolderId = $requestedFolderId;
				}
			}
			
			$data = array_merge([
				'organizationId' => $organizationId,
				'folderId'       => $finalFolderId,
				'title'          => $title,
				'description'    => $description,
				'localId'        => $localId,
				'downloadUrl'    => $uploadedFile->webContentLink ?? null,
				'thumbnailUrl'   => $uploadedFile->thumbnailLink ?? null,
				'createdBy'      => auth()->user()->id,
			], $dynamicData);

			$entity = RecordObject::make('Asset', null, $data)->save();
			$entities[] = $entity;

			Log::info("Asset record saved", ['entity' => $entity, 'local_id' => $localId]);
		}

		if (empty($entities)) {
			return $this->error('No valid uploads processed');
		}

		return $this->success($entities);

	} catch (\Exception $e) {
            Log::error("Asset upload failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data'  => $request->all()
            ]);
            return $this->error($e->getMessage());
        }
    }
}

