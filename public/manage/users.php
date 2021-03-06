<?php
require_once '../../misuzu.php';

$userPerms = perms_get_user(MSZ_PERMS_USER, user_session_current('user_id', 0));
$isPostRequest = $_SERVER['REQUEST_METHOD'] === 'POST';
$queryQffset = (int)($_GET['o'] ?? 0);

tpl_vars([
    'can_manage_users' => $canManageUsers = perms_check($userPerms, MSZ_PERM_USER_MANAGE_USERS),
    'can_manage_roles' => $canManageRoles = perms_check($userPerms, MSZ_PERM_USER_MANAGE_ROLES),
    'can_manage_perms' => $canManagePerms = perms_check($userPerms, MSZ_PERM_USER_MANAGE_PERMS),
]);

switch ($_GET['v'] ?? null) {
    default:
    case 'listing':
        if (!$canManageUsers && !$canManagePerms) {
            echo render_error(403);
            break;
        }

        $usersTake = 30;
        $manageUsersCount = db_query('
            SELECT COUNT(`user_id`)
            FROM `msz_users`
        ')->fetchColumn();

        $getManageUsers = db_prepare('
            SELECT
                u.`user_id`, u.`username`, u.`user_country`, r.`role_id`,
                COALESCE(u.`user_title`, r.`role_title`, r.`role_name`) as `user_title`,
                COALESCE(u.`user_colour`, r.`role_colour`) as `user_colour`
            FROM `msz_users` as u
            LEFT JOIN `msz_roles` as r
            ON u.`display_role` = r.`role_id`
            ORDER BY `user_id`
            LIMIT :offset, :take
        ');
        $getManageUsers->bindValue('offset', $queryQffset);
        $getManageUsers->bindValue('take', $usersTake);
        $manageUsers = $getManageUsers->execute() ? $getManageUsers->fetchAll() : [];

        tpl_vars([
            'manage_users' => $manageUsers,
            'manage_users_count' => $manageUsersCount,
            'manage_users_range' => $usersTake,
            'manage_users_offset' => $queryQffset,
        ]);
        echo tpl_render('manage.users.users');
        break;

    case 'view':
        if (!$canManageUsers && !$canManagePerms) {
            echo render_error(403);
            break;
        }

        $userId = $_GET['u'] ?? null;

        if ($userId === null || ($userId = (int)$userId) < 1) {
            echo 'no';
            break;
        }
        $getUser = db_prepare('
            SELECT
                u.*,
                INET6_NTOA(u.`register_ip`) as `register_ip_decoded`,
                INET6_NTOA(u.`last_ip`) as `last_ip_decoded`,
                COALESCE(u.`user_colour`, r.`role_colour`) as `colour`
            FROM `msz_users` as u
            LEFT JOIN `msz_roles` as r
            ON u.`display_role` = r.`role_id`
            WHERE `user_id` = :user_id
            ORDER BY `user_id`
        ');
        $getUser->bindValue('user_id', $userId);
        $getUser->execute();
        $manageUser = $getUser->execute() ? $getUser->fetch() : [];

        if (!$manageUser) {
            echo 'Could not find that user.';
            break;
        }

        $getHasRoles = db_prepare('
            SELECT `role_id`, `role_name`
            FROM `msz_roles`
            WHERE `role_id` IN (
                SELECT `role_id`
                FROM `msz_user_roles`
                WHERE `user_id` = :user_id
            )
        ');
        $getHasRoles->bindValue('user_id', $manageUser['user_id']);
        $hasRoles = $getHasRoles->execute() ? $getHasRoles->fetchAll() : [];

        $getAvailableRoles = db_prepare('
            SELECT `role_id`, `role_name`
            FROM `msz_roles`
            WHERE `role_id` NOT IN (
                SELECT `role_id`
                FROM `msz_user_roles`
                WHERE `user_id` = :user_id
            )
        ');
        $getAvailableRoles->bindValue('user_id', $manageUser['user_id']);
        $availableRoles = $getAvailableRoles->execute() ? $getAvailableRoles->fetchAll() : [];

        if ($canManagePerms) {
            tpl_var('permissions', $permissions = manage_perms_list(perms_get_user_raw($userId)));
        }

        if ($isPostRequest) {
            if (!csrf_verify('users_edit', $_POST['csrf'] ?? '')) {
                echo 'csrf err';
                break;
            }

            if (!empty($_POST['user']) && is_array($_POST['user'])
                && user_validate_username($_POST['user']['username']) === ''
                && user_validate_email($_POST['user']['email']) === ''
                && strlen($_POST['user']['country']) === 2) {
                $updateUserDetails = db_prepare('
                    UPDATE `msz_users`
                    SET `username` = :username,
                        `email` = LOWER(:email),
                        `user_title` = :title,
                        `user_country` = :country
                    WHERE `user_id` = :user_id
                ');
                $updateUserDetails->bindValue('username', $_POST['user']['username']);
                $updateUserDetails->bindValue('email', $_POST['user']['email']);
                $updateUserDetails->bindValue('country', $_POST['user']['country']);
                $updateUserDetails->bindValue(
                    'title',
                    strlen($_POST['user']['title'])
                    ? $_POST['user']['title']
                    : null
                );
                $updateUserDetails->bindValue('user_id', $userId);
                $updateUserDetails->execute();
            }

            if (!empty($_POST['colour']) && is_array($_POST['colour'])) {
                $userColour = null;

                if (!empty($_POST['colour']['enable'])) {
                    $userColour = colour_create();

                    foreach (['red', 'green', 'blue'] as $key) {
                        $value = (int)($_POST['colour'][$key] ?? -1);
                        $func = 'colour_set_' . ucfirst($key);

                        if ($value < 0 || $value > 0xFF) {
                            echo 'invalid colour value';
                            break 2;
                        }

                        $func($userColour, $value);
                    }
                }

                $updateUserColour = db_prepare('
                    UPDATE `msz_users`
                    SET `user_colour` = :colour
                    WHERE `user_id` = :user_id
                ');
                $updateUserColour->bindValue('colour', $userColour);
                $updateUserColour->bindValue('user_id', $userId);
                $updateUserColour->execute();
            }

            if (!empty($_POST['password'])
                && is_array($_POST['password'])
                && !empty($_POST['password']['new'])
                && !empty($_POST['password']['confirm'])
                && user_validate_password($_POST['password']['new']) === ''
                && $_POST['password']['new'] === $_POST['password']['confirm']) {
                $updatePassword = db_prepare('
                    UPDATE `msz_users`
                    SET `password` = :password
                    WHERE `user_id` = :user_id
                ');
                $updatePassword->bindValue('password', user_password_hash($_POST['password']['new']));
                $updatePassword->bindValue('user_id', $userId);
                $updatePassword->execute();
            }

            if (isset($_POST['add_role'])) {
                user_role_add($manageUser['user_id'], $_POST['add_role']['role']);
            }

            if (isset($_POST['manage_roles'])) {
                switch ($_POST['manage_roles']['mode'] ?? '') {
                    case 'display':
                        user_role_set_display($manageUser['user_id'], $_POST['manage_roles']['role']);
                        break;

                    case 'remove':
                        if ((int)$_POST['manage_roles']['role'] !== MSZ_ROLE_MAIN) {
                            user_role_remove($manageUser['user_id'], $_POST['manage_roles']['role']);
                        }
                        break;
                }
            }

            if (!empty($permissions) && !empty($_POST['perms']) && is_array($_POST['perms'])) {
                $perms = manage_perms_apply($permissions, $_POST['perms']);

                if ($perms !== null) {
                    $permKeys = array_keys($perms);
                    $setPermissions = db_prepare('
                        REPLACE INTO `msz_permissions`
                            (`role_id`, `user_id`, `' . implode('`, `', $permKeys) . '`)
                        VALUES
                            (NULL, :user_id, :' . implode(', :', $permKeys) . ')
                    ');
                    $setPermissions->bindValue('user_id', $userId);

                    foreach ($perms as $key => $value) {
                        $setPermissions->bindValue($key, $value);
                    }

                    $setPermissions->execute();
                } else {
                    $deletePermissions = db_prepare('
                        DELETE FROM `msz_permissions`
                        WHERE `role_id` IS NULL
                        AND `user_id` = :user_id
                    ');
                    $deletePermissions->bindValue('user_id', $userId);
                    $deletePermissions->execute();
                }
            }

            header("Location: ?v=view&u={$manageUser['user_id']}");
            break;
        }

        tpl_vars([
            'available_roles' => $availableRoles,
            'has_roles' => $hasRoles,
            'view_user' => $manageUser,
            'profile_fields' => user_profile_fields_get(),
        ]);
        echo tpl_render('manage.users.user');
        break;

    case 'roles':
        if (!$canManageRoles && !$canManagePerms) {
            echo render_error(403);
            break;
        }

        $rolesTake = 10;
        $manageRolesCount = db_query('
            SELECT COUNT(`role_id`)
            FROM `msz_roles`
        ')->fetchColumn();

        $getManageRoles = db_prepare('
            SELECT
                `role_id`, `role_colour`, `role_name`, `role_title`,
                (
                    SELECT COUNT(`user_id`)
                    FROM `msz_user_roles` as ur
                    WHERE ur.`role_id` = r.`role_id`
                ) as `users`
            FROM `msz_roles` as r
            LIMIT :offset, :take
        ');
        $getManageRoles->bindValue('offset', $queryQffset);
        $getManageRoles->bindValue('take', $rolesTake);
        $manageRoles = $getManageRoles->execute() ? $getManageRoles->fetchAll() : [];

        tpl_vars([
            'manage_roles' => $manageRoles,
            'manage_roles_count' => $manageRolesCount,
            'manage_roles_range' => $rolesTake,
            'manage_roles_offset' => $queryQffset,
        ]);
        echo tpl_render('manage.users.roles');
        break;

    case 'role':
        if (!$canManageRoles && !$canManagePerms) {
            echo render_error(403);
            break;
        }

        $roleId = $_GET['r'] ?? null;

        if ($canManagePerms) {
            tpl_var('permissions', $permissions = manage_perms_list(perms_get_role_raw($roleId ?? 0)));
        }

        if ($isPostRequest) {
            if (!csrf_verify('users_role', $_POST['csrf'] ?? '')) {
                echo 'csrf err';
                break;
            }

            if (!isset($_POST['role'])) {
                echo 'no';
                break;
            }

            $roleName = $_POST['role']['name'] ?? '';
            $roleNameLength = strlen($roleName);

            if ($roleNameLength < 1 || $roleNameLength > 255) {
                echo 'invalid name length';
                break;
            }

            $roleSecret = !empty($_POST['role']['secret']);

            $roleHierarchy = (int)($_POST['role']['hierarchy'] ?? -1);

            if ($roleHierarchy < 1 || $roleHierarchy > 100) {
                echo 'Invalid hierarchy value.';
                break;
            }

            $roleColour = colour_create();

            if (!empty($_POST['role']['colour']['inherit'])) {
                colour_set_inherit($roleColour);
            } else {
                foreach (['red', 'green', 'blue'] as $key) {
                    $value = (int)($_POST['role']['colour'][$key] ?? -1);
                    $func = 'colour_set_' . ucfirst($key);

                    if ($value < 0 || $value > 0xFF) {
                        echo 'invalid colour value';
                        break 2;
                    }

                    $func($roleColour, $value);
                }
            }

            $roleDescription = $_POST['role']['description'] ?? null;
            $roleTitle = $_POST['role']['title'] ?? null;

            if ($roleDescription !== null) {
                $rdLength = strlen($roleDescription);

                if ($rdLength < 1) {
                    $roleDescription = null;
                } elseif ($rdLength > 1000) {
                    echo 'description is too long';
                    break;
                }
            }

            if ($roleTitle !== null) {
                $rtLength = strlen($roleTitle);

                if ($rtLength < 1) {
                    $roleTitle = null;
                } elseif ($rtLength > 64) {
                    echo 'title is too long';
                    break;
                }
            }

            if ($roleId < 1) {
                $updateRole = db_prepare('
                    INSERT INTO `msz_roles`
                        (
                            `role_name`, `role_hierarchy`, `role_hidden`, `role_colour`,
                            `role_description`, `role_title`
                        )
                    VALUES
                        (
                            :role_name, :role_hierarchy, :role_hidden, :role_colour,
                            :role_description, :role_title
                        )
                ');
            } else {
                $updateRole = db_prepare('
                    UPDATE `msz_roles`
                    SET `role_name` = :role_name,
                        `role_hierarchy` = :role_hierarchy,
                        `role_hidden` = :role_hidden,
                        `role_colour` = :role_colour,
                        `role_description` = :role_description,
                        `role_title` = :role_title
                    WHERE `role_id` = :role_id
                ');
                $updateRole->bindValue('role_id', $roleId);
            }

            $updateRole->bindValue('role_name', $roleName);
            $updateRole->bindValue('role_hierarchy', $roleHierarchy);
            $updateRole->bindValue('role_hidden', $roleSecret ? 1 : 0);
            $updateRole->bindValue('role_colour', $roleColour);
            $updateRole->bindValue('role_description', $roleDescription);
            $updateRole->bindValue('role_title', $roleTitle);
            $updateRole->execute();

            if ($roleId < 1) {
                $roleId = (int)db_last_insert_id();
            }

            if (!empty($permissions) && !empty($_POST['perms']) && is_array($_POST['perms'])) {
                $perms = manage_perms_apply($permissions, $_POST['perms']);

                if ($perms !== null) {
                    $permKeys = array_keys($perms);
                    $setPermissions = db_prepare('
                        REPLACE INTO `msz_permissions`
                            (`role_id`, `user_id`, `' . implode('`, `', $permKeys) . '`)
                        VALUES
                            (:role_id, NULL, :' . implode(', :', $permKeys) . ')
                    ');
                    $setPermissions->bindValue('role_id', $roleId);

                    foreach ($perms as $key => $value) {
                        $setPermissions->bindValue($key, $value);
                    }

                    $setPermissions->execute();
                } else {
                    $deletePermissions = db_prepare('
                        DELETE FROM `msz_permissions`
                        WHERE `role_id` = :role_id
                        AND `user_id` IS NULL
                    ');
                    $deletePermissions->bindValue('role_id', $roleId);
                    $deletePermissions->execute();
                }
            }

            header("Location: ?v=role&r={$roleId}");
            break;
        }

        if ($roleId !== null) {
            if ($roleId < 1) {
                echo 'no';
                break;
            }

            $getEditRole = db_prepare('
                SELECT *
                FROM `msz_roles`
                WHERE `role_id` = :role_id
            ');
            $getEditRole->bindValue('role_id', $roleId);
            $editRole = $getEditRole->execute() ? $getEditRole->fetch() : [];

            if (!$editRole) {
                echo 'invalid role';
                break;
            }

            tpl_vars(['edit_role' => $editRole]);
        }

        echo tpl_render('manage.users.role');
        break;
}
