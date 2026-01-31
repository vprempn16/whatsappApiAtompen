<?php

namespace App\Modules\Api\V1\User\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Static property to hold role IDs map (set from controller)
     */
    public static $roleIdsMap = [];

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        // Get role IDs for this user
        $roleIds = self::$roleIdsMap[$this->id] ?? [];

        return [
            'id'            => $this->id,
            'firstName'     => $this->first_name,
            'lastName'      => $this->last_name,
            'email'         => $this->email,
            'phoneNumber'   => $this->phone,
            'organizationId' => $this->organization_id,
            'is_active'      => $this->is_active,
           'role' => 'Admin'
           // 'roleId'        => $roleIds ?? [], // Array of role IDs (integers)
        ];
    }
}