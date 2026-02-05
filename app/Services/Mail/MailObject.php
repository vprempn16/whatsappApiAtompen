<?php

namespace App\Services\Mail;

use App\Modules\Api\V1\Mail\Models\MailLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;
use Illuminate\Support\Facades\Auth;

class MailObject
{
    /**
     * Factory method to get or create a MailLog object.
     * Mimics RecordObject::make but for Mail module specifically.
     *
     * @param string $module (kept for consistency, typically 'Mail')
     * @param string|null $id
     * @param array $data
     * @return MailLog
     * @throws Exception
     */
    public static function make(
        string $module = 'Mail',
        ?string $id = null,
        array $data = []
    ): MailLog {
        $user = Auth::user();
        if (!$user) {
            throw new Exception("Unauthenticated user");
        }

        if ($id && $id !== 'new') {
            try {
                // Enforce Organization Scope
                $model = MailLog::where('organization_id', $user->organization_id)
                    ->findOrFail($id);
            } catch (ModelNotFoundException $e) {
                // If admin, maybe try without global scope if implementation requires, 
                // but usually MailLog enforces data isolation.
                throw new Exception("Mail record not found or you do not have permission to view it.");
            }
        } else {
            $model = new MailLog();
            $model->organization_id = $user->organization_id;
            $model->created_by = $user->id;
        }

        // Fill data if provided
        if (!empty($data)) {
            $fillable = $model->getFillable();
            // Only allow keys that are in the fillable array
            $allowedData = array_intersect_key($data, array_flip($fillable));
            
            $model->fill($allowedData);
        }

        return $model;
    }

    /**
     * Setup helper to resolve ID to Model
     */
    private static function resolveModel($record)
    {
        if ($record instanceof MailLog) {
            return $record;
        }
        if (is_string($record)) {
            return self::make('Mail', $record);
        }
        throw new Exception("Invalid record provided to MailObject");
    }

    /**
     * Get To Email
     */
    public static function getToMail($record)
    {
        $model = self::resolveModel($record);
        return $model->to_email;
    }

    /**
     * Get From Email
     */
    public static function getFromMail($record)
    {
        $model = self::resolveModel($record);
        return $model->from_email;
    }

    /**
     * Get Recipients (Alias for To Email for now, can be expanded to include CC/BCC if stored)
     */
    public static function getRecipients($record)
    {
        $model = self::resolveModel($record);
        // If you had CC/BCC columns, you would merge them here.
        return $model->to_email;
    }

    /**
     * Get Subject
     */
    public static function getSubject($record)
    {
        $model = self::resolveModel($record);
        return $model->subject;
    }

    /**
     * Get Body
     */
    public static function getBody($record)
    {
        $model = self::resolveModel($record);
        return $model->body;
    }

    /**
     * Get Mail Server ID
     */
    public static function getMailServerId($record)
    {
        $model = self::resolveModel($record);
        return $model->mail_server_id;
    }
}
