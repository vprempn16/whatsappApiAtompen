<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class HookManager
{
    protected static $hooks = [];

    /**
     * Register a hook for a specific event and module
     */
    public static function registerHook(string $module, string $event, callable $callback)
    {
        self::$hooks[$module][$event][] = $callback;
    }

    /**
     * Execute hooks for a specific event and module
     */
    public static function executeHook(string $module, string $event, array &$data)
    {
        $hooks = self::$hooks[$module][$event] ?? [];
        $globalHooks = self::$hooks['*'][$event] ?? [];
        $allHooks = array_merge($globalHooks, $hooks);

        foreach ($allHooks as $callback) {
            try {
                $result = $callback($data);

                // If hook signals error, stop execution
                if (is_array($result) && ($result['error'] ?? false) === true) {
                    return $result;
                }
            } catch (\Exception $e) {
                Log::error("Error executing {$event} hook for {$module}", [
                    'error' => $e->getMessage(),
                    'module' => $module,
                    'event' => $event,
                    'data' => $data
                ]);
                return ['error' => true, 'message' => $e->getMessage()];
            }
        }

        return ['error' => false];
    }
}

