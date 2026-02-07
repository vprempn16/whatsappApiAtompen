<?php 
public function syncMailboxStructure(string $serverId, string $orgId, string $userId, string $type = 'Folder')
    {
        $imapFolders = $this->mailService->getImapFolders($serverId);
        $synced = [];

        // -------------------------------------------------------------
        // 1. SYNC FOLDERS
        // -------------------------------------------------------------

        if (strtolower($type) === 'folder') {
            foreach ($imapFolders as $imapFolder) {
                $name = $imapFolder['name'];

                // Determine Type & Icon
                $folderType = 'user';
                $icon = 'folder';

                // Basic detection
                $isSystem = false;
                if (stripos($name, 'inbox') !== false) {
                    $folderType = 'system';
                    $icon = 'inbox';
                    $isSystem = true;
                } elseif (stripos($name, 'sent') !== false || stripos($name, 'send') !== false) {
                    $folderType = 'system';
                    $icon = 'send';
                    $isSystem = true;
                } elseif (stripos($name, 'trash') !== false || stripos($name, 'bin') !== false) {
                    $folderType = 'system';
                    $icon = 'trash';
                    $isSystem = true;
                } elseif (stripos($name, 'draft') !== false) {
                    $folderType = 'system';
                    $icon = 'file-text';
                    $isSystem = true;
                } elseif (stripos($name, 'junk') !== false || stripos($name, 'spam') !== false) {
                    $folderType = 'system';
                    $icon = 'alert-octagon';
                    $isSystem = true;
                }

                // For 'Folder' sync, we generally might want mostly System folders
                // BUT GMail treats 'Labels' as Folders too.
                // If the user INTENDS for 'Labels' to be in MailLabel,
                // we should perhaps SKIP user-folders here?
                // However, traditionally 'MailboxFolder' holds actual folders.
                // If it's a non-system folder, we preserve it as 'user'.

                $slug = Str::slug($name);

                $folder = MailboxFolder::updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'mail_server_id' => $serverId,
                        'name' => $name
                    ],
                    [
                        'user_id' => $userId,
                        'slug' => $slug,
                        'type' => $folderType,
                        'icon' => $icon,
                        'deleted' => 0
                    ]
                );

                $synced[] = $folder;

                // Sync Logic: Associate existing logs
                // Only if IDs are NULL to avoid overwriting user moves
                if ($isSystem) {
                    if (stripos($name, 'inbox') !== false) {
                         // Map Incoming -> Inbox
                        MailLog::where('organization_id', $orgId)
                            ->where('mail_server_id', $serverId)
                            ->where('direction', 'incoming')
                            ->whereNull('folder_id')
                            ->update(['folder_id' => $folder->id]);
                    } elseif (stripos($name, 'sent') !== false || stripos($name, 'send') !== false) {
                         // Map Outgoing -> Sent
                        MailLog::where('organization_id', $orgId)
                            ->where('mail_server_id', $serverId)
                            ->where('direction', 'outgoing')
                            ->whereNull('folder_id')
                            ->update(['folder_id' => $folder->id]);
                    } elseif (stripos($name, 'draft') !== false) {
                        // Drafts provided by IMAP - Update MailDrafts table
                        // User requested "mail_drafts" table update
                         MailDraft::where('organization_id', $orgId)
                            ->where('mail_server_id', $serverId)
                            ->whereNull('folder_id')
                            ->update(['folder_id' => $folder->id]);
                    }
                }
            }
        }

        // -------------------------------------------------------------
        // 2. SYNC LABELS
        // -------------------------------------------------------------
        if (strtolower($type) === 'label') {
             foreach ($imapFolders as $imapFolder) {
                $name = $imapFolder['name'];

                // Filter out likely System folders
                if (stripos($name, 'inbox') !== false) continue;
                if (stripos($name, 'sent') !== false) continue;
                if (stripos($name, 'trash') !== false) continue;
                if (stripos($name, 'bin') !== false) continue;
                if (stripos($name, 'draft') !== false) continue;
                if (stripos($name, 'junk') !== false) continue;
                if (stripos($name, 'spam') !== false) continue;

                // Rest are Labels
                // e.g. "Work", "Personal", "[Gmail]/Important" (maybe?)

                $slug = Str::slug($name);

                $label = MailLabel::updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'mail_server_id' => $serverId,
                        'name' => $name
                    ],
                    [
                        'user_id' => $userId,
                        'color' => '#6B7280', // default gray
                        'deleted' => 0
                    ]
                );
                $synced[] = $label;
             }
        }

        return $synced;
    }
