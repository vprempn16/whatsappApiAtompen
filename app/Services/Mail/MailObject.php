<?php

namespace App\Services\Mail;


use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;
use Illuminate\Support\Facades\Auth;
use App\Models\FieldModelManager;
use Illuminate\Support\Facades\DB;

class MailObject
{
    protected $module;
    protected $recordId;
    protected $data;

    public function __construct(string $module = 'Mail', ?string $id = null, array $data = [])
    {
        $this->module = $module;
        $this->recordId = $id;
        $this->data = $data;
    }

    /**
     * Factory method
     */
    public static function make(
        string $module = 'Mail',
        ?string $id = null,
        array $data = []
    ): static {
        return new static($module, $id, $data);
    }

    /**
     * Get all email fields and values for a specific record
     */
    
    
}
