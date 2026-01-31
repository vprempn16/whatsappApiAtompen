<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\PermissionService;

class CheckPermission
{
    public function handle(Request $request, Closure $next, $module, $action)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Authentication required.'
            ], 401);
        }
        
        $permissionService = new PermissionService($user);

        // Fixed: Use correct method name hasPermission() instead of hasModulePermission()
        if (!$permissionService->hasPermission($module, $action)) {
            return response()->json([
                'status' => false,
                'message' => "Unauthorized: No {$action} permission for {$module}"
            ], 403);
        }

        return $next($request);
    }
}
