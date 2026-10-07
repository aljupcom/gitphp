-- GitPHP Database Schema
-- MariaDB / MySQL — InnoDB, utf8mb4_unicode_ci

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Repositories ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `repositories` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `slug`           VARCHAR(100)    NOT NULL,
    `name`           VARCHAR(255)    NOT NULL,
    `description`    TEXT            DEFAULT NULL,
    `visibility`     ENUM('public','private') NOT NULL DEFAULT 'public',
    `default_branch` VARCHAR(255)    NOT NULL DEFAULT 'main',
    `source_url`     VARCHAR(500)    DEFAULT NULL,
    `last_synced_at` DATETIME        DEFAULT NULL,
    `stars_count`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `homepage`       VARCHAR(500)    DEFAULT NULL,
    `topics`         VARCHAR(500)    DEFAULT NULL,
    `owner_user_id`  INT UNSIGNED    DEFAULT NULL,
    `forked_from_id` INT UNSIGNED    DEFAULT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_repositories_slug` (`slug`),
    KEY `idx_repositories_visibility` (`visibility`),
    KEY `idx_repositories_owner_user` (`owner_user_id`),
    CONSTRAINT `fk_repositories_owner_user` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_repositories_fork_parent` FOREIGN KEY (`forked_from_id`) REFERENCES `repositories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SSH Keys ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ssh_keys` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)    NOT NULL,
    `public_key`  TEXT            NOT NULL,
    `fingerprint` VARCHAR(100)    NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_ssh_keys_fingerprint` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Settings (key-value store) ────────────────────────────────────

CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key`   VARCHAR(100)    NOT NULL,
    `setting_value` TEXT            DEFAULT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Activity Log ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`         BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `repo_id`    INT UNSIGNED      DEFAULT NULL,
    `action`     VARCHAR(50)       NOT NULL,
    `details`    TEXT              DEFAULT NULL,
    `created_at` DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity_log_repo_id` (`repo_id`),
    KEY `idx_activity_log_action`  (`action`),
    KEY `idx_activity_log_created` (`created_at`),
    CONSTRAINT `fk_activity_log_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Users (public registration + shared repositories) ────────────

CREATE TABLE IF NOT EXISTS `users` (
    `id`                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`           VARCHAR(50)     NOT NULL,
    `email`              VARCHAR(255)    NOT NULL,
    `password_hash`      VARCHAR(255)    NOT NULL,
    `role`               ENUM('user','admin') NOT NULL DEFAULT 'user',
    `email_verified_at`  DATETIME        DEFAULT NULL,
    `verification_token` VARCHAR(64)     DEFAULT NULL,
    `totp_secret`        VARCHAR(64)     DEFAULT NULL,
    `totp_enabled`       TINYINT(1)      NOT NULL DEFAULT 0,
    `bio`                VARCHAR(500)    DEFAULT NULL,
    `location`           VARCHAR(255)    DEFAULT NULL,
    `website`            VARCHAR(500)    DEFAULT NULL,
    `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_users_username` (`username`),
    UNIQUE KEY `idx_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bug reports (per-repository issue tracking) ───────────────────

CREATE TABLE IF NOT EXISTS `bug_reports` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `repo_id`             INT UNSIGNED    NOT NULL,
    `user_id`             INT UNSIGNED    DEFAULT NULL,
    `title`               VARCHAR(255)    NOT NULL,
    `description`         TEXT            NOT NULL,
    `environment`         TEXT            DEFAULT NULL,
    `steps_to_reproduce`  TEXT            DEFAULT NULL,
    `status`              ENUM('open','resolved','closed') NOT NULL DEFAULT 'open',
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bug_reports_repo` (`repo_id`),
    KEY `idx_bug_reports_status` (`status`),
    CONSTRAINT `fk_bug_reports_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bug_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Wiki pages (Markdown, per repository) ─────────────────────────

CREATE TABLE IF NOT EXISTS `wiki_pages` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `repo_id`     INT UNSIGNED    NOT NULL,
    `slug`        VARCHAR(255)    NOT NULL,
    `title`       VARCHAR(255)    NOT NULL,
    `content`     MEDIUMTEXT      DEFAULT NULL,
    `updated_by`  INT UNSIGNED    DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_wiki_pages_repo_slug` (`repo_id`, `slug`),
    KEY `idx_wiki_pages_updated` (`updated_at`),
    CONSTRAINT `fk_wiki_pages_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wiki_pages_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Repository likes (stars) ──────────────────────────────────────

CREATE TABLE IF NOT EXISTS `repo_likes` (
    `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `repo_id`    INT UNSIGNED     NOT NULL,
    `user_id`    INT UNSIGNED     NOT NULL DEFAULT 0,
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_repo_likes_pair` (`repo_id`, `user_id`),
    CONSTRAINT `fk_repo_likes_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Repository subscriptions (watch → update notifications) ──────

CREATE TABLE IF NOT EXISTS `repo_subscriptions` (
    `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `repo_id`    INT UNSIGNED     NOT NULL,
    `user_id`    INT UNSIGNED     NOT NULL DEFAULT 0,
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_repo_subscriptions_pair` (`repo_id`, `user_id`),
    CONSTRAINT `fk_repo_subscriptions_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Notifications (push / issue / wiki events for users) ──────────

CREATE TABLE IF NOT EXISTS `notifications` (
    `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED     NOT NULL,
    `repo_id`    INT UNSIGNED     DEFAULT NULL,
    `type`       VARCHAR(30)      NOT NULL DEFAULT 'push',
    `message`    VARCHAR(500)     NOT NULL,
    `link`       VARCHAR(500)     DEFAULT NULL,
    `severity`   ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `category`   VARCHAR(30)      NOT NULL DEFAULT 'repo',
    `meta`       JSON             DEFAULT NULL,
    `read_at`    DATETIME         DEFAULT NULL,
    `actor_id`   INT UNSIGNED     DEFAULT NULL,
    `is_read`    TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notifications_user` (`user_id`, `is_read`),
    KEY `idx_notifications_repo` (`repo_id`),
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Per-user notification delivery preferences ───────────────────

CREATE TABLE IF NOT EXISTS `user_notification_prefs` (
    `user_id`        INT UNSIGNED NOT NULL,
    `channel_in_app` TINYINT(1)   NOT NULL DEFAULT 1,
    `channel_email`  TINYINT(1)   NOT NULL DEFAULT 0,
    `channel_browser`TINYINT(1)   NOT NULL DEFAULT 0,
    `categories`     VARCHAR(500) DEFAULT NULL,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `fk_notification_prefs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Support desk (tickets for guests and registered users) ────────

CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference`   VARCHAR(20)     NOT NULL,
    `user_id`     INT UNSIGNED    DEFAULT NULL,
    `guest_name`  VARCHAR(100)    DEFAULT NULL,
    `guest_email` VARCHAR(255)    DEFAULT NULL,
    `subject`     VARCHAR(200)    NOT NULL,
    `category`    VARCHAR(30)     NOT NULL DEFAULT 'general',
    `priority`    ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    `message`     TEXT            NOT NULL,
    `status`      ENUM('open','pending','answered','closed') NOT NULL DEFAULT 'open',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_support_reference` (`reference`),
    KEY `idx_support_user` (`user_id`, `status`),
    KEY `idx_support_email` (`guest_email`),
    KEY `idx_support_status` (`status`, `created_at`),
    CONSTRAINT `fk_support_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_replies` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id`   BIGINT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED    DEFAULT NULL,
    `author_name` VARCHAR(100)    DEFAULT NULL,
    `is_staff`    TINYINT(1)      NOT NULL DEFAULT 0,
    `body`        TEXT            NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_reply_ticket` (`ticket_id`, `created_at`),
    CONSTRAINT `fk_reply_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Email service: settings, templates, delivery log, queue ───────

CREATE TABLE IF NOT EXISTS `email_settings` (
    `key_name`  VARCHAR(100) NOT NULL,
    `value`     VARCHAR(1000) DEFAULT NULL,
    `value_enc` BLOB          DEFAULT NULL,
    `is_secret` TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_templates` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_key` VARCHAR(80)  NOT NULL,
    `locale`       VARCHAR(8)   NOT NULL DEFAULT 'en',
    `name`         VARCHAR(150) NOT NULL,
    `subject`      VARCHAR(255) NOT NULL,
    `body_html`    MEDIUMTEXT   NOT NULL,
    `body_text`    MEDIUMTEXT   DEFAULT NULL,
    `variables`    JSON         DEFAULT NULL,
    `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
    `updated_by`   INT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_tpl_key_locale` (`template_key`, `locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_log` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `to_email`     VARCHAR(255) NOT NULL,
    `subject`      VARCHAR(255) NOT NULL,
    `template_key` VARCHAR(80)  DEFAULT NULL,
    `event_key`    VARCHAR(80)  DEFAULT NULL,
    `provider`     VARCHAR(30)  DEFAULT NULL,
    `status`       ENUM('sent','failed','logged','bounced','complained','delivered') NOT NULL DEFAULT 'logged',
    `message_id`   VARCHAR(255) DEFAULT NULL,
    `error`        VARCHAR(500) DEFAULT NULL,
    `attempts`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_log_status` (`status`, `created_at`),
    KEY `idx_log_msgid` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_outbox` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `to_email`        VARCHAR(255) NOT NULL,
    `subject`         VARCHAR(255) NOT NULL,
    `body_html`       MEDIUMTEXT   NOT NULL,
    `body_text`       MEDIUMTEXT   DEFAULT NULL,
    `template_key`    VARCHAR(80)  DEFAULT NULL,
    `event_key`       VARCHAR(80)  DEFAULT NULL,
    `status`          ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at` DATETIME     DEFAULT NULL,
    `idempotency_key` VARCHAR(80)  DEFAULT NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_outbox_idem` (`idempotency_key`),
    KEY `idx_outbox_status` (`status`, `next_attempt_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_events` (
    `event_key`        VARCHAR(80) NOT NULL,
    `template_key`     VARCHAR(80) NOT NULL,
    `category`         VARCHAR(30) NOT NULL DEFAULT 'system',
    `severity`         ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `recipients_rule`  VARCHAR(50) NOT NULL DEFAULT 'actor',
    `throttle_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `enabled`          TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_suppressions` (
    `email`      VARCHAR(255) NOT NULL,
    `reason`     VARCHAR(50)  NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Collaborators (repositories shared with registered users) ─────

CREATE TABLE IF NOT EXISTS `repo_collaborators` (
    `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `repo_id`    INT UNSIGNED     NOT NULL,
    `user_id`    INT UNSIGNED     NOT NULL,
    `role`       ENUM('read','write') NOT NULL DEFAULT 'read',
    `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_repo_collaborators_pair` (`repo_id`, `user_id`),
    CONSTRAINT `fk_repo_collaborators_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_repo_collaborators_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Schema migrations (tracker for bin/migrate.php) ───────────────

CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `migration`  VARCHAR(255) NOT NULL,
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── System settings (runtime configuration, editable in admin) ────

CREATE TABLE IF NOT EXISTS `system_settings` (
    `key_name`   VARCHAR(100)    NOT NULL,
    `value`      VARCHAR(500)    DEFAULT NULL,
    PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Audit log (security-relevant actions, AdminSettings viewer) ───

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED    DEFAULT 0,
    `user_name`  VARCHAR(100)    DEFAULT 'owner',
    `action`     VARCHAR(50)     NOT NULL,
    `repo_id`    INT UNSIGNED    DEFAULT NULL,
    `details`    TEXT            DEFAULT NULL,
    `ip_address` VARCHAR(45)     DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_logs_created` (`created_at`),
    KEY `idx_audit_logs_action`  (`action`),
    KEY `idx_audit_logs_user`    (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Releases (tag-backed release records) ─────────────────────────

CREATE TABLE IF NOT EXISTS `repo_releases` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `repo_id`          INT UNSIGNED    NOT NULL,
    `tag_name`         VARCHAR(255)    NOT NULL,
    `target_commitish` VARCHAR(255)    DEFAULT 'main',
    `name`             VARCHAR(255)    DEFAULT NULL,
    `body`             MEDIUMTEXT      DEFAULT NULL,
    `is_draft`         TINYINT(1)      NOT NULL DEFAULT 0,
    `is_prerelease`    TINYINT(1)      NOT NULL DEFAULT 0,
    `created_by`       INT UNSIGNED    DEFAULT 0,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `published_at`     DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_repo_releases_tag` (`repo_id`, `tag_name`),
    CONSTRAINT `fk_repo_releases_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Download center (short-link file host) ────────────────────────

CREATE TABLE IF NOT EXISTS `file_downloads` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `title`          VARCHAR(255)    DEFAULT NULL,
    `original_name`  VARCHAR(500)    NOT NULL,
    `file_path`      VARCHAR(1000)   NOT NULL,
    `file_size`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `mime_type`      VARCHAR(150)    DEFAULT 'application/octet-stream',
    `short_code`     VARCHAR(32)     NOT NULL,
    `password_hash`  VARCHAR(255)    DEFAULT NULL,
    `max_downloads`  INT UNSIGNED    DEFAULT NULL,
    `download_count` INT UNSIGNED    NOT NULL DEFAULT 0,
    `expires_at`     DATETIME        DEFAULT NULL,
    `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
    `created_by`     INT UNSIGNED    DEFAULT 0,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_file_downloads_code` (`short_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pull requests ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `pull_requests` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `repo_id`        INT UNSIGNED    NOT NULL,
    `number`         INT UNSIGNED    NOT NULL,
    `title`          VARCHAR(255)    NOT NULL,
    `body`           MEDIUMTEXT      DEFAULT NULL,
    `source_branch`  VARCHAR(255)    NOT NULL,
    `target_branch`  VARCHAR(255)    NOT NULL,
    `status`         ENUM('open','merged','closed') NOT NULL DEFAULT 'open',
    `created_by`     INT UNSIGNED    DEFAULT 0,
    `author_name`    VARCHAR(100)    DEFAULT 'unknown',
    `approval`       ENUM('pending','approved','changes_requested') NOT NULL DEFAULT 'pending',
    `approved_by`    VARCHAR(100)    DEFAULT NULL,
    `approved_at`    DATETIME        DEFAULT NULL,
    `merged_commit`  VARCHAR(40)     DEFAULT NULL,
    `merged_at`      DATETIME        DEFAULT NULL,
    `closed_at`      DATETIME        DEFAULT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_pull_requests_number` (`repo_id`, `number`),
    KEY `idx_pull_requests_status` (`repo_id`, `status`),
    CONSTRAINT `fk_pull_requests_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pull request conversation comments ────────────────────────────

CREATE TABLE IF NOT EXISTS `pr_comments` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pr_id`       BIGINT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED    DEFAULT 0,
    `author_name` VARCHAR(100)    DEFAULT 'unknown',
    `body`        TEXT            NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pr_comments_pr` (`pr_id`, `created_at`),
    CONSTRAINT `fk_pr_comments_pr` FOREIGN KEY (`pr_id`) REFERENCES `pull_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pull request line-level review comments (code review) ─────────

CREATE TABLE IF NOT EXISTS `pr_review_comments` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pr_id`       BIGINT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED    DEFAULT 0,
    `author_name` VARCHAR(100)    DEFAULT 'unknown',
    `file_path`   VARCHAR(500)    NOT NULL,
    `line_number` INT UNSIGNED    NOT NULL DEFAULT 0,
    `side`        ENUM('old','new') NOT NULL DEFAULT 'new',
    `body`        TEXT            NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pr_review_comments_pr` (`pr_id`, `file_path`),
    CONSTRAINT `fk_pr_review_comments_pr` FOREIGN KEY (`pr_id`) REFERENCES `pull_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Branch protection rules (enforced by hooks/pre-receive) ───────

CREATE TABLE IF NOT EXISTS `branch_protections` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `repo_id`           INT UNSIGNED    NOT NULL,
    `branch_name`       VARCHAR(255)    NOT NULL,
    `prevent_force_push` TINYINT(1)     NOT NULL DEFAULT 1,
    `prevent_delete`     TINYINT(1)      NOT NULL DEFAULT 1,
    `allow_admin`        TINYINT(1)      NOT NULL DEFAULT 1,
    `require_approval`   TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_branch_protections_pair` (`repo_id`, `branch_name`),
    CONSTRAINT `fk_branch_protections_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Webhooks ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `webhooks` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `repo_id`    INT UNSIGNED    NOT NULL,
    `url`        VARCHAR(500)    NOT NULL,
    `secret`     VARCHAR(255)    DEFAULT NULL,
    `events`     VARCHAR(255)    NOT NULL DEFAULT 'push',
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_webhooks_repo` (`repo_id`),
    CONSTRAINT `fk_webhooks_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Webhook delivery attempts ─────────────────────────────────────

CREATE TABLE IF NOT EXISTS `webhook_deliveries` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `webhook_id`    INT UNSIGNED    NOT NULL,
    `event`         VARCHAR(50)     NOT NULL,
    `payload`       MEDIUMTEXT      NOT NULL,
    `response_code` SMALLINT UNSIGNED DEFAULT NULL,
    `success`       TINYINT(1)      DEFAULT NULL,
    `duration_ms`   INT UNSIGNED    DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_webhook_deliveries_hook` (`webhook_id`, `created_at`),
    CONSTRAINT `fk_webhook_deliveries_hook` FOREIGN KEY (`webhook_id`) REFERENCES `webhooks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Personal API access tokens ────────────────────────────────────

CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `name`         VARCHAR(100)    NOT NULL,
    `token_hash`   CHAR(64)        NOT NULL,
    `token_prefix` VARCHAR(12)     NOT NULL DEFAULT '',
    `scopes`       VARCHAR(100)    NOT NULL DEFAULT 'read',
    `last_used_at` DATETIME        DEFAULT NULL,
    `revoked_at`   DATETIME        DEFAULT NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_api_tokens_hash` (`token_hash`),
    KEY `idx_api_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Issue/bug-report conversation comments ────────────────────────

CREATE TABLE IF NOT EXISTS `bug_comments` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bug_id`      INT UNSIGNED    NOT NULL,
    `user_id`     INT UNSIGNED    DEFAULT 0,
    `author_name` VARCHAR(100)    DEFAULT 'anonymous',
    `body`        TEXT            NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bug_comments_bug` (`bug_id`, `created_at`),
    CONSTRAINT `fk_bug_comments_bug` FOREIGN KEY (`bug_id`)
        REFERENCES `bug_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Password reset tokens (email forgot/reset flow) ───────────────

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `token_hash` CHAR(64)        NOT NULL,
    `expires_at` DATETIME        NOT NULL,
    `used_at`    DATETIME        DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_reset_token_hash` (`token_hash`),
    KEY `idx_reset_user` (`user_id`, `used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Remember-me persistent login cookies ──────────────────────────

CREATE TABLE IF NOT EXISTS `remember_tokens` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED    NOT NULL DEFAULT 0,
    `token_hash` CHAR(64)        NOT NULL,
    `expires_at` DATETIME        NOT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_remember_token_hash` (`token_hash`),
    KEY `idx_remember_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Active session registry (list / revoke) ───────────────────────

CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `token`         VARCHAR(64)     NOT NULL,
    `ip_address`    VARCHAR(45)     DEFAULT NULL,
    `user_agent`    VARCHAR(255)    DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sessions_user` (`user_id`),
    KEY `idx_sessions_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sync log (upstream mirror-sync attempts) ──────────────────────

CREATE TABLE IF NOT EXISTS `sync_log` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `repo_id`    INT UNSIGNED    NOT NULL,
    `status`     ENUM('started','success','failed') NOT NULL DEFAULT 'started',
    `message`    VARCHAR(500)    DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sync_log_repo` (`repo_id`, `created_at`),
    CONSTRAINT `fk_sync_log_repo` FOREIGN KEY (`repo_id`) REFERENCES `repositories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
