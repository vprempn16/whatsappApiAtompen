<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller; // ✅ IMPORTANT
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class GoogleAuthController extends Controller
{

	/**
	* Step 1: Verify email & password, then return Google redirect URL
	 */

	public function getSigninUrl(Request $request)
	{
		// ✅ Generate Google redirect URL (no actual redirect in Postman)
		$redirectUrl = Socialite::driver('google')->stateless()->redirect()->getTargetUrl();

		return response()->json([
			'message' => 'Redirect to Google login',
			'redirect_url' => $redirectUrl
		]);
	}

	/**
	 * Step 2: Handle Google callback after login
	 */
	public function handleGoogleCallback()
	{
		try {
			$googleUser = Socialite::driver('google')->stateless()->user();

			// Get email from Google account
			$email = $googleUser->getEmail();

			// Check if the email exists in your users table
			$user = User::where('email', $email)
				->where('deleted', 0)
				->first();

			if ($user) {
				// Use the found Google user, not Auth::user()
				$token = $user->createToken('api-token')->plainTextToken;

				return response()->json([
					'status' => true,
					'message' => 'Success',
					'data' => [
						'user' => $user,
						'token' => $token
					]
				]);
			} else {
				// If user not found, return an error
				return response()->json([
                                	'status' => false,
                                	'message' => 'User not found.'
                        	], 401);
			}
		} catch (\Exception $e) {
			// Catch any errors from Google callback
			return response()->json([
				'status' => false,
				'message' => 'Something went wrong during Google login.',
				'error' => $e->getMessage(),
			], 500);
		}
	}
}

