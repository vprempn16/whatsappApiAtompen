<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Crypt;

class UserController extends ApiController
{
    public function store(Request $request)
    {
        try {
            $data = $request->input('data.values', []);

            if (empty($data)) {
                return $this->error('No data received for saving');
            }

            // Strict org: only current organization; never trust organizationId from request
            $orgId = auth()->user()->organization_id ?? null;
            if (!$orgId) {
                return $this->error('No organization set for current user');
            }

            // Rename input keys if needed (e.g., from frontend format)
            $data = [
                'firstName'            => $data['firstName'] ?? null,
                'lastName'             => $data['lastName'] ?? null,
                'email'                => $data['email'] ?? null,
                'phone'                => $data['phone'] ?? $data['phoneNumber'] ?? null,
                'role'                 => $data['role'] ?? null,
                'password'             => $data['password'] ?? null,
                'password_confirmation'=> $data['confirmPassword'] ?? null,
                'isActive'             => $data['isActive'] ?? 1,
            ];

            // Validate clean data (organizationId removed - always use auth org)
            $validated = validator($data, [
                'firstName' => ['required', 'string', 'max:100'],
                'lastName'  => ['nullable', 'string', 'max:100'],
                'email'     => ['required', 'email', 'max:150', 'unique:users,email'],
                'phone'     => ['nullable', 'string', 'max:20'],
                'role'      => ['nullable', 'string', 'in:admin,manager,service_staff,user'],
                'password'  => ['required', 'string', 'min:8', 'confirmed'],
                'isActive'  => ['nullable', 'boolean'],
            ])->validate();

            // Create user (always in current org)
            $user = new User();
            $user->id              = (string) Str::uuid();
            $user->first_name      = $validated['firstName'];
            $user->last_name       = $validated['lastName'] ?? null;
            $user->role            = $validated['role'] ?? 'service_staff';
            $user->email           = $validated['email'];
            $user->phone           = $validated['phone'] ?? null;
            $user->password        = Crypt::encrypt($validated['password']);
            $user->is_active       = $validated['isActive'] ?? 1;
            $user->organization_id = $orgId;
            $user->save();

            $token = $user->createToken('api-token')->plainTextToken;

            return $this->success([
                'user'  => $user,
                'token' => $token,
            ]);

        } catch (ValidationException $e) {
            return $this->error($e->errors());
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $organizationId = auth()->user()->organization_id ?? null;
        //dd( $organizationId );
        $fieldManager = \App\Models\FieldModelManager::make('User', 'ListView' , false);
        if (!$organizationId) {
            return $this->error('No organization set for current user');
        }

        $query = User::where('organization_id', $organizationId);

        // Optionally: Pagination parameters with defaults
        $perPage = (int) $request->query('per_page', 20);
        $page = (int) $request->query('page', 1);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return $this->success([
            'fields' => $fieldManager->getApiFormFields(),
            'list'  => $paginated->items(),
            'meta'  => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'from'         => $paginated->firstItem(),
                'to'           => $paginated->lastItem(),
            ],
            'links' => [
                'prev' => $paginated->previousPageUrl(),
                'next' => $paginated->nextPageUrl(),
            ],
        ]);
    }
}