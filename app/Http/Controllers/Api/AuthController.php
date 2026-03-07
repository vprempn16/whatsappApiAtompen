<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * Logout user and revoke current token
     */
    public function logout(Request $request)
    {
        // Revoke the current access token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Successfully logged out'
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        $user = User::where('email', $request->email)
            ->where('deleted', 0)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials',
            ], 200);
        }

        // Check if password is encrypted (old format) or hashed (new format)
        $passwordValid = false;

        // Try to check if it's a hash (starts with $2y$ for bcrypt)
        if (str_starts_with($user->password, '$2y$') || str_starts_with($user->password, '$2a$') || str_starts_with($user->password, '$argon2')) {
            // Password is hashed, use Hash::check
            $passwordValid = Hash::check($request->password, $user->password);
        } else {
            // Legacy encrypted password - try to decrypt and compare
            // This allows migration period for existing users
            try {
                $storedPassword = \Illuminate\Support\Facades\Crypt::decrypt($user->password);
                $passwordValid = ($storedPassword === $request->password);

                // If valid, re-hash the password for future use
                if ($passwordValid) {
                    $user->password = Hash::make($request->password);
                    $user->save();
                }
            } catch (\Throwable $e) {
                $passwordValid = false;
            }
        }

        if (!$passwordValid) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials',
            ], 200);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Success',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'is_admin' => (bool) $user->is_admin,
                    'organization_id' => $user->organization_id,
                ],
                'token' => $token
            ]
        ]);
    }
}
