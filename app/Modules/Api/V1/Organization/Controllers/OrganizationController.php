<?php

namespace App\Modules\Api\V1\Organization\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Controllers\ApiController;
use App\Modules\Api\V1\Organization\Models\Organization;        
class OrganizationController extends ApiController
{
    /**
     * Store a newly created resource in storage.
     */

public function store(Request $request)
{
    try {
        DB::beginTransaction();

        // ✅ Validate nested payload
        $validated = $request->validate([
            'data.values.name'        => 'required|string|max:255',
            'data.values.description' => 'nullable|string'
        ]);

        $values = $validated['data']['values'];

        $organization = Organization::create([
            'id'          => (string) Str::uuid(),
            'name'        => $values['name'],
            'description' => $values['description'] ?? null,
            'status'      => "Active",
        ]);

        DB::commit();

        return $this->success($organization,
            'Organization created successfully.'
        );
        } catch (ValidationException $e) {
        DB::rollBack();

         return $this->error($e->getMessage());

    } catch (\Exception $e) {
        DB::rollBack();
          return $this->error($e->getMessage());
    }
}


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}