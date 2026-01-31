<?php

namespace App\UserAccess;

class UserAccess
{
    /**
     * Check if the user has permission for a specific field in a module.
     *
     * @param string $fieldKey
     * @param string $moduleName
     * @param array $config
     * @return bool
     */
    public function hasFieldPermission(string $fieldKey, string $moduleName, array $config): bool
    {
        if (empty($config['modules'][$moduleName]['fields'])) {
            return false;
        }

        $moduleConfig = $config['modules'][$moduleName];
        $fieldConfig = $moduleConfig['fields'][$fieldKey] ?? null;

        if (!$fieldConfig) {
            return false;
        }

        $fieldPermissions = $fieldConfig['permissions'] ?? [];

        return !empty($fieldPermissions['invisible']) && $fieldPermissions['invisible'] == 0
            && isset($fieldPermissions['readonly']) && $fieldPermissions['readonly'] == 0;
    }

    /**
     * Check if the user has module-level view permissions for a specific action.
     *
     * @param string $moduleName
     * @param string $action
     * @param array $config
     * @return bool
     */
    public function hasModuleViewPermission(string $moduleName,$action , $action_type , array $config): bool
    {
	    if ($config['modules'][$moduleName]['permissions'][$action][$action_type]) {
		    return true;
	    }
	    return false;
    }

    /**
     * Check if the user has module-level action permissions for a specific action.
     *
     * @param string $moduleName
     * @param string $action
     * @param array $config
     * @return bool
     */
    public function hasModuleActionPermission(string $moduleName, string $action, array $config): bool
    {
        if (empty($config['modules'][$moduleName]['permissions']['actions'])) {
            return false;
        }

        $modulePermissions = $config['modules'][$moduleName]['permissions']['actions'];

        return !empty($modulePermissions[$action]) && $modulePermissions[$action] === true;
    }

    /**
     * Check if the user has general module-level permissions for a specific action.
     *
     * @param string $moduleName
     * @param string $action
     * @param array $config
     * @return bool
     */
    public function hasModulePermission(string $moduleName, string $action, array $config): bool
    {
        if (empty($config['modules'][ $moduleName]['permissions'])) {
            return false;
        }

        $modulePermissions = $config['modules'][$moduleName]['permissions'];

        return !empty($modulePermissions);
    }
    public function getPermittedFields($module_name, $profileData){
        if (empty($config['modules']['Product']['fields'])) {
            return false;
        }

        $fields = $config['modules']['Product']['fields'];

        return $fields;
    }
}

