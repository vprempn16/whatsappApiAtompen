<?php

namespace App\Services;

use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_Permission;

class GoogleDriveService
{
	protected $drive;

	public function __construct()
	{
		$client = new Google_Client();
		$client->setAuthConfig(storage_path('app/google/credentials.json'));
		$client->addScope(Google_Service_Drive::DRIVE);
		$client->setAccessType('offline');

		$this->drive = new Google_Service_Drive($client);
	}

	public function upload($file, $folderId = null)
	{
		$fileMetadata = new Google_Service_Drive_DriveFile([
			'name' => $file->getClientOriginalName(),
			'parents' => $folderId ? [$folderId] : []
		]);

		$content = file_get_contents($file->getRealPath());

		$uploadedFile = $this->drive->files->create($fileMetadata, [
			'data' => $content,
			'mimeType' => $file->getClientMimeType(),
			'uploadType' => 'multipart',
			'fields' => 'id, name, thumbnailLink, webContentLink'
		]);

		$permission = new Google_Service_Drive_Permission([
			'type' => 'anyone',
			'role' => 'reader',
		]);

		$this->drive->permissions->create($uploadedFile->id, $permission);

		return $uploadedFile;
	}
	public function getOrCreateFolder($folderName, $parentId = null)
	{
		$query = "mimeType = 'application/vnd.google-apps.folder' and name = '$folderName' and trashed = false";
		if ($parentId) {
			$query .= " and '$parentId' in parents";
		}

		$folders = $this->drive->files->listFiles([
			'q' => $query,
			'fields' => 'files(id, name)',
		]);

		if (count($folders->getFiles()) > 0) {
			return $folders->getFiles()[0]->getId(); // Return existing folder ID
		}

		$folderMetadata = new \Google_Service_Drive_DriveFile([
			'name' => $folderName,
			'mimeType' => 'application/vnd.google-apps.folder',
			'parents' => $parentId ? [$parentId] : []
		]);

		$folder = $this->drive->files->create($folderMetadata, [
			'fields' => 'id'
		]);

		return $folder->id; // Return newly created folder ID
	}

}

