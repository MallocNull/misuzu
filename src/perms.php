<?php
use Misuzu\Database;

define('MSZ_PERMS_GENERAL', 'general');
define('MSZ_PERMS_USER', 'user');
define('MSZ_PERMS_CHANGELOG', 'changelog');
define('MSZ_PERMS_NEWS', 'news');
define('MSZ_PERMS_FORUM', 'forum');
define('MSZ_PERMS_COMMENTS', 'comments');

define('MSZ_PERM_MODES', [
    MSZ_PERMS_GENERAL, MSZ_PERMS_USER, MSZ_PERMS_CHANGELOG,
    MSZ_PERMS_NEWS, MSZ_PERMS_FORUM, MSZ_PERMS_COMMENTS,
]);

define('MSZ_PERMS_ALLOW', 'allow');
define('MSZ_PERMS_DENY', 'deny');

define('MSZ_PERM_SETS', [
    MSZ_PERMS_ALLOW, MSZ_PERMS_DENY
]);

$_msz_perms_cache = [];

function perms_construct_cache_key(string $prefix, string $mode, int $pid): string
{
    return $prefix . '_' . $mode . '_' . $pid;
}

function perms_get_cache(string $prefix, string $mode, int $pid): int
{
    global $_msz_perms_cache;
    return $_msz_perms_cache[perms_construct_cache_key($prefix, $mode, $pid)];
}

function perms_set_cache(string $prefix, string $mode, int $pid, int $perms): int
{
    global $_msz_perms_cache;
    return $_msz_perms_cache[perms_construct_cache_key($prefix, $mode, $pid)] = $perms;
}

function perms_is_cached(string $prefix, string $mode, int $pid): bool
{
    global $_msz_perms_cache;
    return array_key_exists(perms_construct_cache_key($prefix, $mode, $pid), $_msz_perms_cache);
}

function perms_get_keys(): array
{
    $perms = [];

    foreach (MSZ_PERM_MODES as $mode) {
        foreach (MSZ_PERM_SETS as $set) {
            $perms[] = perms_get_key($mode, $set);
        }
    }

    return $perms;
}

function perms_create(): array
{
    $perms = [];

    foreach (perms_get_keys() as $key) {
        $perms[$key] = 0;
    }

    return $perms;
}

function perms_get_key(string $prefix, string $suffix): string
{
    return $prefix . '_perms_' . $suffix;
}

function perms_get_user(string $prefix, int $user): int
{
    if (!in_array($prefix, MSZ_PERM_MODES) || $user < 1) {
        return 0;
    } elseif ($user === 1) {
        return 0x7FFFFFFF;
    }

    if (perms_is_cached($prefix, 'user', $user)) {
        return perms_get_cache($prefix, 'user', $user);
    }

    $permsAllow = 0;
    $permsDeny = 0;

    $getPerms = Database::connection()->prepare("
        SELECT `{$prefix}_perms_allow` as `allow`, `{$prefix}_perms_deny` as `deny`
        FROM `msz_permissions`
        WHERE (`user_id` = :user_id_1 AND `role_id` IS NULL)
        OR (
            `user_id` IS NULL
            AND `role_id` IN (
                SELECT `role_id`
                FROM `msz_user_roles`
                WHERE `user_id` = :user_id_2
            )
        )
    ");
    $getPerms->bindValue('user_id_1', $user);
    $getPerms->bindValue('user_id_2', $user);
    $perms = $getPerms->execute() ? $getPerms->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($perms as $perm) {
        $permsAllow |= $perm['allow'];
        $permsDeny |= $perm['deny'];
    }

    return perms_set_cache($prefix, 'user', $user, $permsAllow &~ $permsDeny);
}

function perms_get_role(string $prefix, int $role): int
{
    if (!in_array($prefix, MSZ_PERM_MODES) || $role < 1) {
        return 0;
    }

    if (perms_is_cached($prefix, 'role', $user)) {
        return perms_get_cache($prefix, 'role', $user);
    }

    $getPerms = Database::connection()->prepare("
        SELECT `{$prefix}_perms_allow` &~ `{$prefix}_perms_deny`
        FROM `msz_permissions`
        WHERE `role_id` = :role_id
        AND `user_id` IS NULL
    ");
    $getPerms->bindValue('role_id', $role);
    return perms_set_cache($prefix, 'role', $role, $getPerms->execute() ? (int)$getPerms->fetchColumn() : 0);
}

function perms_get_user_raw(int $user): array
{
    $emptyPerms = perms_create();

    if ($user < 1) {
        return $emptyPerms;
    }

    $getPerms = Database::connection()->prepare('
        SELECT
            `' . implode('`, `', perms_get_keys()) . '`
        FROM `msz_permissions`
        WHERE `user_id` = :user_id
        AND `role_id` IS NULL
    ');
    $getPerms->bindValue('user_id', $user);

    if (!$getPerms->execute()) {
        return $emptyPerms;
    }

    $perms = $getPerms->fetch(PDO::FETCH_ASSOC);

    if (!$perms) {
        return $emptyPerms;
    }

    return $perms;
}

function perms_get_role_raw(int $role): array
{
    $emptyPerms = perms_create();

    if ($role < 1) {
        return $emptyPerms;
    }

    $getPerms = Database::connection()->prepare('
        SELECT
            `' . implode('`, `', perms_get_keys()) . '`
        FROM `msz_permissions`
        WHERE `user_id` IS NULL
        AND `role_id` = :role_id
    ');
    $getPerms->bindValue('role_id', $role);

    if (!$getPerms->execute()) {
        return $emptyPerms;
    }

    $perms = $getPerms->fetch(PDO::FETCH_ASSOC);

    if (!$perms) {
        return $emptyPerms;
    }

    return $perms;
}

function perms_check(int $perms, int $perm): bool
{
    return ($perms & $perm) > 0;
}
