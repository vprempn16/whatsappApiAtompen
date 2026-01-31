<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\ApiRequest;

class StoreProfileRequest extends ApiRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // You can add role-based validation here if needed.
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data.details.name' => 'required|string|max:50',
            'data.details.description' => 'required|string',
            'data.details.status' => 'required|string|in:Active,Inactive',
            'data.details.organization' => 'required|uuid|exists:organizations,id', // Ensures organization exists
        ];
    }

    /**
     * Custom error messages for validation failures.
     */
    public function messages()
    {
        return [
            'data.details.name.required' => 'Profile name is required.',
            'data.details.description.required' => 'Profile description is required.',
            'data.details.status.in' => 'Invalid status. Please use Active or Inactive.',
            'data.details.organization' => 'The specified organization does not exist.',
        ];
    }
}

