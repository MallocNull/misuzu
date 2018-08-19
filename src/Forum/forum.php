<?php
use Misuzu\Database;

define('MSZ_PERM_FORUM_MANAGE_FORUMS', 1);

define('MSZ_FORUM_PERM_LIST_FORUM', 1); // can see stats, but will get error when trying to view
define('MSZ_FORUM_PERM_VIEW_FORUM', 1 << 1);

// shorthand, never use this to SET!!!!!!!
define('MSZ_FORUM_PERM_CAN_LIST_FORUM', MSZ_FORUM_PERM_LIST_FORUM | MSZ_FORUM_PERM_VIEW_FORUM);

define('MSZ_FORUM_PERM_CREATE_TOPIC', 1 << 10);
define('MSZ_FORUM_PERM_DELETE_TOPIC', 1 << 11);
define('MSZ_FORUM_PERM_MOVE_TOPIC', 1 << 12);
define('MSZ_FORUM_PERM_LOCK_TOPIC', 1 << 13);
define('MSZ_FORUM_PERM_STICKY_TOPIC', 1 << 14);
define('MSZ_FORUM_PERM_ANNOUNCE_TOPIC', 1 << 15);
define('MSZ_FORUM_PERM_GLOBAL_ANNOUNCE_TOPIC', 1 << 16);

define('MSZ_FORUM_PERM_CREATE_POST', 1 << 20);
define('MSZ_FORUM_PERM_EDIT_POST', 1 << 21);
define('MSZ_FORUM_PERM_EDIT_ANY_POST', 1 << 22);
define('MSZ_FORUM_PERM_DELETE_POST', 1 << 23);
define('MSZ_FORUM_PERM_DELETE_ANY_POST', 1 << 24);

define('MSZ_FORUM_TYPE_DISCUSSION', 0);
define('MSZ_FORUM_TYPE_CATEGORY', 1);
define('MSZ_FORUM_TYPE_LINK', 2);
define('MSZ_FORUM_TYPES', [
    MSZ_FORUM_TYPE_DISCUSSION,
    MSZ_FORUM_TYPE_CATEGORY,
    MSZ_FORUM_TYPE_LINK,
]);

define('MSZ_FORUM_MAY_HAVE_CHILDREN', [
    MSZ_FORUM_TYPE_DISCUSSION,
    MSZ_FORUM_TYPE_CATEGORY,
]);

define('MSZ_FORUM_MAY_HAVE_TOPICS', [
    MSZ_FORUM_TYPE_DISCUSSION,
]);

define('MSZ_FORUM_ROOT', 0);
define('MSZ_FORUM_ROOT_DATA', [ // should be compatible with the data fetched in forum_get_root_categories
    'forum_id' => 0,
    'forum_name' => 'Forums',
    'forum_children' => 0,
    'forum_type' => MSZ_FORUM_TYPE_CATEGORY,
]);

function forum_may_have_children(int $forumType): bool
{
    return in_array($forumType, MSZ_FORUM_MAY_HAVE_CHILDREN);
}

function forum_may_have_topics(int $forumType): bool
{
    return in_array($forumType, MSZ_FORUM_MAY_HAVE_TOPICS);
}

function forum_fetch(int $forumId): array
{
    $getForum = Database::prepare('
        SELECT
            `forum_id`, `forum_name`, `forum_type`, `forum_link`, `forum_link_clicks`, `forum_parent`,
            (
                SELECT COUNT(`topic_id`)
                FROM `msz_forum_topics`
                WHERE `forum_id` = f.`forum_id`
            ) as `forum_topic_count`
        FROM `msz_forum_categories` as f
        WHERE `forum_id` = :forum_id
    ');
    $getForum->bindValue('forum_id', $forumId);
    $getForum->execute();
    $forums = $getForum->fetch();

    return $forums ? $forums : [];
}

function forum_get_root_categories(int $userId): array
{
    $categoryPermSql = sprintf(
        '(%s & %d)',
        forum_perms_get_user_sql('forum', 'f.`forum_id`'),
        MSZ_FORUM_PERM_CAN_LIST_FORUM
    );
    $getCategories = Database::prepare("
        SELECT
            f.`forum_id`, f.`forum_name`, f.`forum_type`,
            (
                SELECT COUNT(`forum_id`)
                FROM `msz_forum_categories` as sf
                WHERE sf.`forum_parent` = f.`forum_id`
            ) as `forum_children`
        FROM `msz_forum_categories` as f
        WHERE f.`forum_parent` = 0
        AND f.`forum_type` = 1
        AND f.`forum_hidden` = false
        AND {$categoryPermSql} > 0
        ORDER BY f.`forum_order`
    ");
    $getCategories->bindValue('perm_user_id_1', $userId);
    $getCategories->bindValue('perm_user_id_2', $userId);
    $categories = $getCategories->execute() ? $getCategories->fetchAll(PDO::FETCH_ASSOC) : [];
    $categories = array_merge([MSZ_FORUM_ROOT_DATA], $categories);

    $forumPermSql = sprintf(
        '(%s & %d)',
        forum_perms_get_user_sql('forum', '`forum_id`'),
        MSZ_FORUM_PERM_CAN_LIST_FORUM
    );
    $getRootForumCount = Database::prepare(sprintf("
        SELECT COUNT(`forum_id`)
        FROM `msz_forum_categories`
        WHERE `forum_parent` = %d
        AND `forum_type` != 1
        AND {$forumPermSql} > 0
    ", MSZ_FORUM_ROOT));
    $getRootForumCount->bindValue('perm_user_id_1', $userId);
    $getRootForumCount->bindValue('perm_user_id_2', $userId);
    $getRootForumCount->execute();

    $categories[0]['forum_children'] = (int)$getRootForumCount->fetchColumn();

    return $categories;
}

function forum_get_breadcrumbs(
    int $forumId,
    string $linkFormat = '/forum/forum.php?f=%d',
    array $indexLink = ['Forums' => '/forum/']
): array {
    $breadcrumbs = [];
    $getBreadcrumb = Database::prepare('
        SELECT `forum_id`, `forum_name`, `forum_parent`
        FROM `msz_forum_categories`
        WHERE `forum_id` = :forum_id
    ');

    while ($forumId > MSZ_FORUM_ROOT) {
        $getBreadcrumb->bindValue('forum_id', $forumId);
        $breadcrumb = $getBreadcrumb->execute() ? $getBreadcrumb->fetch() : [];

        if (!$breadcrumb) {
            break;
        }

        $breadcrumbs[$breadcrumb['forum_name']] = sprintf($linkFormat, $breadcrumb['forum_id']);
        $forumId = $breadcrumb['forum_parent'];
    }

    return array_reverse($breadcrumbs + $indexLink);
}

function forum_increment_clicks(int $forumId): void
{
    $incrementLinkClicks = Database::prepare('
        UPDATE `msz_forum_categories`
        SET `forum_link_clicks` = `forum_link_clicks` + 1
        WHERE `forum_id` = :forum_id
        AND `forum_type` = ' . MSZ_FORUM_TYPE_LINK . '
        AND `forum_link_clicks` IS NOT NULL
    ');
    $incrementLinkClicks->bindValue('forum_id', $forumId);
    $incrementLinkClicks->execute();
}

define('MSZ_FORUM_GET_CHILDREN_QUERY_SMALL', '
    SELECT
        :user_id as `target_user_id`,
        f.`forum_id`, f.`forum_name`,
        (
            SELECT
                `target_user_id` > 0
            AND
                t.`topic_id` IS NOT NULL
            AND
                t.`topic_bumped` >= NOW() - INTERVAL 1 MONTH
            AND (
                SELECT COUNT(ti.`topic_id`) < (
                    SELECT COUNT(`topic_id`)
                    FROM `msz_forum_topics`
                    WHERE `forum_id` = f.`forum_id`
                    AND `topic_bumped` >= NOW() - INTERVAL 1 MONTH
                    AND `topic_deleted` IS NULL
                )
                FROM `msz_forum_topics_track` as tt
                RIGHT JOIN `msz_forum_topics` as ti
                ON ti.`topic_id` = tt.`topic_id`
                WHERE ti.`forum_id` = f.`forum_id`
                AND tt.`user_id` = `target_user_id`
                AND  `track_last_read` >= `topic_bumped`
            )
        ) as `forum_unread`
    FROM `msz_forum_categories` as f
    LEFT JOIN `msz_forum_topics` as t
    ON t.`topic_id` = (
        SELECT `topic_id`
        FROM `msz_forum_topics`
        WHERE `forum_id` = f.`forum_id`
        AND `topic_deleted` IS NULL
        ORDER BY `topic_bumped` DESC
        LIMIT 1
    )
    WHERE `forum_parent` = :parent_id
    AND `forum_hidden` = false
    ORDER BY f.`forum_order`
');
define('MSZ_FORUM_GET_CHILDREN_QUERY_STANDARD', '
    SELECT
        :user_id as `target_user_id`,
        f.`forum_id`, f.`forum_name`, f.`forum_description`, f.`forum_type`,
        f.`forum_link`, f.`forum_link_clicks`, f.`forum_archived`,
        t.`topic_id` as `recent_topic_id`, p.`post_id` as `recent_post_id`,
        t.`topic_title` as `recent_topic_title`, t.`topic_bumped` as `recent_topic_bumped`,
        p.`post_created` as `recent_post_created`,
        u.`user_id` as `recent_post_user_id`,
        u.`username` as `recent_post_username`,
        COALESCE(u.`user_colour`, r.`role_colour`) as `recent_post_user_colour`,
        (
            SELECT COUNT(`topic_id`)
            FROM `msz_forum_topics`
            WHERE `forum_id` = f.`forum_id`
        ) as `forum_topic_count`,
        (
            SELECT COUNT(`post_id`)
            FROM `msz_forum_posts`
            WHERE `forum_id` = f.`forum_id`
        ) as `forum_post_count`,
        (
            SELECT
                `target_user_id` > 0
            AND
                `recent_topic_id` IS NOT NULL
            AND
                `recent_topic_bumped` >= NOW() - INTERVAL 1 MONTH
            AND (
                SELECT COUNT(ti.`topic_id`) < (
                    SELECT COUNT(`topic_id`)
                    FROM `msz_forum_topics`
                    WHERE `forum_id` = f.`forum_id`
                    AND `topic_bumped` >= NOW() - INTERVAL 1 MONTH
                    AND `topic_deleted` IS NULL
                )
                FROM `msz_forum_topics_track` as tt
                RIGHT JOIN `msz_forum_topics` as ti
                ON ti.`topic_id` = tt.`topic_id`
                WHERE ti.`forum_id` = f.`forum_id`
                AND tt.`user_id` = `target_user_id`
                AND  `track_last_read` >= `topic_bumped`
            )
        ) as `forum_unread`
    FROM `msz_forum_categories` as f
    LEFT JOIN `msz_forum_topics` as t
    ON t.`topic_id` = (
        SELECT `topic_id`
        FROM `msz_forum_topics`
        WHERE `forum_id` = f.`forum_id`
        AND `topic_deleted` IS NULL
        ORDER BY `topic_bumped` DESC
        LIMIT 1
    )
    LEFT JOIN `msz_forum_posts` as p
    ON p.`post_id` = (
        SELECT `post_id`
        FROM `msz_forum_posts`
        WHERE `topic_id` = t.`topic_id`
        ORDER BY `post_id` DESC
        LIMIT 1
    )
    LEFT JOIN `msz_users` as u
    ON u.`user_id` = p.`user_id`
    LEFT JOIN `msz_roles` as r
    ON r.`role_id` = u.`display_role`
    WHERE f.`forum_parent` = :parent_id
    AND f.`forum_hidden` = false
    AND (
        (f.`forum_parent` = ' . MSZ_FORUM_ROOT . ' AND f.`forum_type` != ' . MSZ_FORUM_TYPE_CATEGORY . ')
        OR f.`forum_parent` != ' . MSZ_FORUM_ROOT . '
    )
    ORDER BY f.`forum_order`
');

function forum_get_children(int $parentId, int $userId, bool $small = false): array
{
    $getListing = Database::prepare(
        $small
        ? MSZ_FORUM_GET_CHILDREN_QUERY_SMALL
        : MSZ_FORUM_GET_CHILDREN_QUERY_STANDARD
    );
    $getListing->bindValue('user_id', $userId);
    $getListing->bindValue('parent_id', $parentId);

    return $getListing->execute() ? $getListing->fetchAll() : [];
}
