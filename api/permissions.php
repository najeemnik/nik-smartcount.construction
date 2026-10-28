<?php
/** Shared, side-effect-free permission checks for SQL APIs and workspace snapshots. */
function permissionData($value): array {
    if (is_string($value)) $value = json_decode($value, true);
    return is_array($value) ? $value : [];
}
function hasProjectRestrictions(array $user): bool {
    if ((int)($user['is_master'] ?? 0) === 1 || ($user['role'] ?? '') === 'admin') return false;
    $profile = permissionData($user['app_profile_json'] ?? []);
    return !empty($profile['projectGrants']) || !empty($profile['assignedProjectIds']);
}
function canAccessModule(array $user, string $module, string $action = 'view', ?string $projectId = null, bool $moduleOnly = false): bool {
    if ((int)($user['is_locked'] ?? 0) === 1 || (int)($user['is_active'] ?? 1) !== 1) return false;
    if ((int)($user['is_master'] ?? 0) === 1) return true;
    if (!empty($user['_tenant_owner']) && !canAccessModule($user['_tenant_owner'], $module, $action, $projectId, $moduleOnly)) return false;
    $aliases = resolveModuleAliases($module);
    $actions = in_array($action, ['del', 'delete'], true) ? ['del', 'delete'] : [$action];
    $mods = permissionData($user['allowed_modules_json'] ?? []);
    foreach ($aliases as $alias) if (array_key_exists($alias, $mods) && !$mods[$alias]) return false;
    $tabs = permissionData($user['allowed_tabs_json'] ?? []);
    // Dashboard is a derived view rather than a selectable service tab.
    if ($module !== 'dashboard' && $tabs && !array_intersect($aliases, $tabs)) return false;
    $role = $user['role'] ?? 'viewer';
    if ($role === 'admin') return true;
    if (!in_array($role, ['viewer', 'accountant', 'project_manager', 'procurement'], true)) return false;
    if ($role === 'viewer' && $action !== 'view') return false;
    $perms = permissionData($user['permissions_json'] ?? []);
    foreach ($aliases as $alias) {
        $p = $perms[$alias] ?? [];
        if (!is_array($p)) continue;
        if (array_key_exists('view', $p) && !$p['view']) return false;
        foreach ($actions as $a) if (array_key_exists($a, $p) && !$p[$a]) return false;
    }
    $profile = permissionData($user['app_profile_json'] ?? []);
    $assigned = $profile['assignedProjectIds'] ?? [];
    if (is_array($assigned) && $assigned && !$moduleOnly) {
        if (!in_array('*', $assigned, true) && ($projectId === null || !in_array($projectId, $assigned, true))) return false;
    }
    $grants = $profile['projectGrants'] ?? [];
    if (is_array($grants) && $grants) {
        foreach ($grants as $grant) {
            if (!is_array($grant)) continue;
            $pid = (string)($grant['projectId'] ?? '');
            if (!$moduleOnly && $pid !== '*' && ($projectId === null || $pid !== $projectId)) continue;
            foreach ($aliases as $alias) {
                $p = $grant['sections'][$alias] ?? [];
                if (!is_array($p) || empty($p['view'])) continue;
                if ($action === 'view') return true;
                foreach ($actions as $a) if (!empty($p[$a])) return true;
            }
        }
        return false;
    }
    return true;
}
function requireScopedProject(array $user, string $module, ?string $projectId): void {
    if (hasProjectRestrictions($user) && ($projectId === null || $projectId === '')) {
        jsonResponse(false, null, 'برای این حساب، یک پروژهٔ مجاز را انتخاب کنید.', 403);
    }
    assertModulePermission($user, $module, 'view', $projectId);
}

/** Scope-only check for private draft records; no client-supplied form/module can expand access. */
function canAccessProject(array $user, ?string $projectId): bool {
    if(!hasProjectRestrictions($user))return true;
    if($projectId===null || $projectId==='')return false;
    $profile=permissionData($user['app_profile_json']??[]);
    $assigned=$profile['assignedProjectIds']??[];
    if(!is_array($assigned))return false;
    if($assigned&&!in_array('*',$assigned,true)&&!in_array($projectId,$assigned,true))return false;
    $grants=$profile['projectGrants']??[];
    if(!is_array($grants))return false;
    if(!$grants)return true;
    foreach($grants as $grant){
        if(!is_array($grant)||!in_array((string)($grant['projectId']??''),['*',$projectId],true))continue;
        foreach(($grant['sections']??[]) as $section)if(is_array($section)&&!empty($section['view']))return true;
    }
    return false;
}
