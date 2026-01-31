<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Http\Controllers\ApiController;

class AdminOnly extends ApiController
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->error('Authentication required.');
        }

        // Check if user is deleted
        if ($user->deleted == 1) {
            return $this->error('Account is deactivated.');
        }

        // Admin check (based on your users table)
        if ((int) $user->is_admin !== 1) {
            return $this->error('Admin access required for this resource.');
        }

        return $next($request);
    }
}