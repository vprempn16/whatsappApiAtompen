<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "type" => 'Profile',
            "id" => $this->id,
            "details" => [
                "name" => $this->name,
                "description" => $this->description,
                "status" => $this->status,
                "createdAt" => $this->created_at,
                "updatedAt" => $this->updated_at,
            ],

            /*"relatedRecords" => [
                'organization' => new OrganizationResource($this->whenLoaded('organization')), // If related organization is loaded
                'owner' => [
                    "data" => [
                        "type" => "Organization",
                        "id" => $this->organization_id,
                    ]
                ],
	    ]*/
        ];

    }
}

