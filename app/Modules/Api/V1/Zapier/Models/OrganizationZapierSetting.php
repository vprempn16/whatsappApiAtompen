<?php

namespace App\Modules\Api\V1\Zapier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class OrganizationZapierSetting extends Model
{
    protected $table = 'organization_zapier_settings';
    
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'zapier_api_key',
        'api_key_hash',
        'contacts_enabled',
        'leads_enabled',
        'products_enabled',
    ];

    protected $casts = [
        'contacts_enabled' => 'boolean',
        'leads_enabled' => 'boolean',
        'products_enabled' => 'boolean',
    ];

    protected $hidden = [
        'zapier_api_key',
        'api_key_hash',
    ];

    /**
     * Encrypt API key when setting
     */
    public function setZapierApiKeyAttribute($value)
    {
        $this->attributes['zapier_api_key'] = Crypt::encryptString($value);
        $this->attributes['api_key_hash'] = !empty($value) ? hash('sha256', $value) : null;
    }

    /**
     * Decrypt API key when getting
     */
    public function getZapierApiKeyAttribute($value)
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if a module is enabled
     */
    public function isModuleEnabled(string $module): bool
{
    $map = [
        'leads'    => 'leads_enabled',
        'contacts' => 'contacts_enabled',
        'products' => 'products_enabled',
    ];

    if (!isset($map[$module])) {
        return false;
    }

    return (bool) $this->{$map[$module]};
}


    /**
     * Get enabled modules as array
     */
    public function getEnabledModules(): array
    {
        $modules = [];
        if ($this->contacts_enabled) $modules[] = 'contacts';
        if ($this->leads_enabled) $modules[] = 'leads';
        if ($this->products_enabled) $modules[] = 'products';
        return $modules;
    }

    /**
     * Scope: Get settings for organization
     */
    public function scopeForOrganization($query, $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Scope: Get settings with at least one module enabled
     */
    public function scopeWithEnabledModules($query)
    {
        return $query->where(function ($q) {
            $q->where('contacts_enabled', 1)
              ->orWhere('leads_enabled', 1)
              ->orWhere('products_enabled', 1);
        });
    }
}
