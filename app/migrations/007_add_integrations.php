<?php

class add_integrations
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $db = $this->_lava->db;

        $googleSubjectColumn = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'google_sub']
        )->fetchColumn();
        if ($googleSubjectColumn === 0) {
            $db->raw('ALTER TABLE users ADD COLUMN google_sub VARCHAR(255) NULL AFTER email');
        }

        $googleSubjectIndex = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['users', 'uq_users_google_sub']
        )->fetchColumn();
        if ($googleSubjectIndex === 0) {
            $db->raw('ALTER TABLE users ADD UNIQUE KEY uq_users_google_sub (google_sub)');
        }

        $db->raw(
            "CREATE TABLE IF NOT EXISTS calendar_feed_tokens (
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at DATETIME NULL,
                PRIMARY KEY (user_id),
                UNIQUE KEY uq_calendar_feed_token_hash (token_hash),
                CONSTRAINT fk_calendar_feed_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->raw(
            "CREATE TABLE IF NOT EXISTS user_email_preferences (
                user_id INT UNSIGNED NOT NULL,
                announcements_enabled TINYINT(1) NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                CONSTRAINT fk_email_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->raw(
            "CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_password_reset_token_hash (token_hash),
                KEY idx_password_reset_user_expiry (user_id, expires_at),
                CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->raw(
            "CREATE TABLE IF NOT EXISTS password_reset_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                identity_hash CHAR(64) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_password_reset_request_identity_time (identity_hash, requested_at),
                KEY idx_password_reset_request_ip_time (ip_address, requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $db->raw(
            "CREATE TABLE IF NOT EXISTS email_outbox (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NULL,
                barangay_id INT UNSIGNED NULL,
                mail_type VARCHAR(40) NOT NULL,
                recipient VARCHAR(255) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body MEDIUMTEXT NOT NULL,
                status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
                attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                locked_at DATETIME NULL,
                last_error VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_email_outbox_ready (status, next_attempt_at),
                KEY idx_email_outbox_user (user_id, created_at),
                CONSTRAINT fk_email_outbox_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_email_outbox_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down()
    {
        throw new RuntimeException(
            'Integration data may be in use. Preserve existing records and use a reviewed rollback migration.'
        );
    }
}
