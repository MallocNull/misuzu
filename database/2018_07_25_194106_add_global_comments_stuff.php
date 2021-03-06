<?php
namespace Misuzu\DatabaseMigrations\AddGlobalCommentsStuff;

use PDO;

function migrate_up(PDO $conn): void
{
    $conn->exec('
        CREATE TABLE `msz_comments_categories` (
            `category_id`       INT(10) UNSIGNED    NOT NULL    AUTO_INCREMENT,
            `category_name`     VARCHAR(255)        NOT NULL,
            `category_created`  TIMESTAMP           NOT NULL    DEFAULT CURRENT_TIMESTAMP,
            `category_locked`   TIMESTAMP           NULL        DEFAULT NULL,
            PRIMARY KEY (`category_id`),
            UNIQUE INDEX `comments_categories_name_unique` (`category_name`)
        );
    ');

    $conn->exec('
        CREATE TABLE `msz_comments_posts` (
            `comment_id`        INT(10) UNSIGNED    NOT NULL    AUTO_INCREMENT,
            `category_id`       INT(10) UNSIGNED    NOT NULL,
            `user_id`           INT(10) UNSIGNED    NULL        DEFAULT NULL,
            `comment_reply_to`  INT(10) UNSIGNED    NULL        DEFAULT NULL,
            `comment_text`      TEXT                NOT NULL,
            `comment_created`   TIMESTAMP           NOT NULL    DEFAULT CURRENT_TIMESTAMP,
            `comment_pinned`    TIMESTAMP           NULL        DEFAULT NULL,
            `comment_edited`    TIMESTAMP           NULL        DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `comment_deleted`   TIMESTAMP           NULL        DEFAULT NULL,
            PRIMARY KEY (`comment_id`),
            INDEX `comments_posts_category_foreign` (`category_id`),
            INDEX `comments_posts_user_foreign` (`user_id`),
            INDEX `comments_posts_reply_id` (`comment_reply_to`),
            INDEX `comments_posts_dates` (
                `comment_created`,
                `comment_pinned`,
                `comment_edited`,
                `comment_deleted`
            ),
            CONSTRAINT `comments_posts_category_foreign`
                FOREIGN KEY (`category_id`)
                REFERENCES `msz_comments_categories` (`category_id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT `comments_posts_user_foreign`
                FOREIGN KEY (`user_id`)
                REFERENCES `msz_users` (`user_id`)
                ON UPDATE CASCADE
                ON DELETE SET NULL
        );
    ');

    $conn->exec("
        CREATE TABLE `msz_comments_votes` (
            `comment_id`    INT(10) UNSIGNED        NOT NULL,
            `user_id`       INT(10) UNSIGNED        NOT NULL,
            `comment_vote`  ENUM('Like','Dislike')  NULL,
            UNIQUE  INDEX `comments_vote_unique`        (`comment_id`, `user_id`),
                    INDEX `comments_vote_user_foreign`  (`user_id`),
                    INDEX `comments_vote_index`         (`comment_vote`),
            CONSTRAINT `comment_vote_id`
                FOREIGN KEY (`comment_id`)
                REFERENCES `msz_comments_posts` (`comment_id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE,
            CONSTRAINT `comment_vote_user`
                FOREIGN KEY (`user_id`)
                REFERENCES `msz_users` (`user_id`)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        );
    ");

    $conn->exec("
        ALTER TABLE `msz_news_posts`
            ADD COLUMN `comment_section_id` INT UNSIGNED NULL DEFAULT NULL AFTER `deleted_at`,
            ADD INDEX `news_posts_comment_section` (`comment_section_id`),
            ADD CONSTRAINT `news_posts_comment_section`
                FOREIGN KEY (`comment_section_id`)
                REFERENCES `msz_comments_categories` (`category_id`)
                ON UPDATE CASCADE
                ON DELETE SET NULL;
    ");

    // create a comment section for all news posts
    $getNews = $conn->query('SELECT `post_id` FROM `msz_news_posts` WHERE `comment_section_id` IS NULL')
        ->fetchAll(PDO::FETCH_ASSOC);
    $setNews = $conn->prepare('UPDATE `msz_news_posts` SET `comment_section_id` = :c WHERE `post_id` = :p');

    foreach ($getNews as $post) {
        $info = comments_category_create("news-{$post['post_id']}");
        $setNews->execute([
            'p' => $post['post_id'],
            'c' => $info['category_id'],
        ]);
    }
}

function migrate_down(PDO $conn): void
{
    $conn->exec('
        ALTER TABLE `msz_news_posts`
            DROP COLUMN `comment_section_id`,
            DROP INDEX `news_posts_comment_section`,
            DROP FOREIGN KEY `news_posts_comment_section`;
    ');
    $conn->exec('DROP TABLE `msz_comments_votes`');
    $conn->exec('DROP TABLE `msz_comments_posts`');
    $conn->exec('DROP TABLE `msz_comments_categories`');
}
