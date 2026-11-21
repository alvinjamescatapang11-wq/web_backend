<?php

class create_agritrack_core_schema
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS roles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_roles_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS permissions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                permission_key VARCHAR(100) NOT NULL,
                description VARCHAR(255) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_permissions_key (permission_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS role_permissions (
                role_id INT UNSIGNED NOT NULL,
                permission_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (role_id, permission_id),
                CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
                CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS barangays (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(30) NULL,
                name VARCHAR(150) NOT NULL,
                description VARCHAR(500) NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                is_sample TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_barangays_code (code),
                UNIQUE KEY uq_barangays_name (name),
                KEY idx_barangays_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS user_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_sessions_token_hash (token_hash),
                KEY idx_user_sessions_user_expiry (user_id, expires_at),
                CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS auth_login_attempts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                identity_hash CHAR(64) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                was_successful TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY idx_login_attempts_identity_time (identity_hash, attempted_at),
                KEY idx_login_attempts_ip_time (ip_address, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS farmers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                registry_number VARCHAR(80) NULL,
                first_name VARCHAR(100) NOT NULL,
                middle_name VARCHAR(100) NULL,
                last_name VARCHAR(100) NOT NULL,
                suffix VARCHAR(30) NULL,
                sex VARCHAR(30) NULL,
                birth_date DATE NULL,
                phone VARCHAR(40) NULL,
                email VARCHAR(255) NULL,
                address VARCHAR(500) NULL,
                primary_livelihood VARCHAR(150) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_sample TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                updated_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_farmers_registry_number (registry_number),
                UNIQUE KEY uq_farmers_id_barangay (id, barangay_id),
                KEY idx_farmers_barangay_name (barangay_id, last_name, first_name),
                KEY idx_farmers_phone (phone),
                CONSTRAINT fk_farmers_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT,
                CONSTRAINT fk_farmers_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_farmers_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS farms (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                farmer_id INT UNSIGNED NOT NULL,
                farm_name VARCHAR(150) NULL,
                location_description VARCHAR(500) NULL,
                latitude DECIMAL(10,7) NULL,
                longitude DECIMAL(10,7) NULL,
                area_hectares DECIMAL(12,4) NULL,
                ownership_type VARCHAR(80) NULL,
                is_sample TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_farms_id_barangay (id, barangay_id),
                KEY idx_farms_barangay_farmer (barangay_id, farmer_id),
                CONSTRAINT fk_farms_farmer_barangay FOREIGN KEY (farmer_id, barangay_id) REFERENCES farmers (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_farms_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rice_varieties (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                growth_duration_days SMALLINT UNSIGNED NULL,
                description VARCHAR(500) NULL,
                is_sample TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rice_varieties_name (name),
                KEY idx_rice_varieties_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rice_stages (
                id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(80) NOT NULL,
                sequence_number TINYINT UNSIGNED NOT NULL,
                description VARCHAR(255) NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rice_stages_name (name),
                UNIQUE KEY uq_rice_stages_sequence (sequence_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rice_plantings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                farmer_id INT UNSIGNED NOT NULL,
                farm_id INT UNSIGNED NOT NULL,
                rice_variety_id INT UNSIGNED NOT NULL,
                planting_date DATE NOT NULL,
                expected_maturity_date DATE NULL,
                area_hectares DECIMAL(12,4) NULL,
                season VARCHAR(80) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rice_plantings_id_barangay (id, barangay_id),
                KEY idx_rice_plantings_barangay_date (barangay_id, planting_date),
                KEY idx_rice_plantings_maturity (barangay_id, expected_maturity_date),
                CONSTRAINT fk_rice_plantings_farmer_barangay FOREIGN KEY (farmer_id, barangay_id) REFERENCES farmers (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_rice_plantings_farm_barangay FOREIGN KEY (farm_id, barangay_id) REFERENCES farms (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_rice_plantings_variety FOREIGN KEY (rice_variety_id) REFERENCES rice_varieties (id) ON DELETE RESTRICT,
                CONSTRAINT fk_rice_plantings_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT,
                CONSTRAINT fk_rice_plantings_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS rice_monitoring_submissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                rice_planting_id BIGINT UNSIGNED NOT NULL,
                rice_stage_id TINYINT UNSIGNED NOT NULL,
                submitted_by INT UNSIGNED NOT NULL,
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                observed_on DATE NOT NULL,
                notes TEXT NULL,
                attachment_path VARCHAR(500) NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                reviewed_by INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                rejection_reason VARCHAR(1000) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_monitoring_barangay_status (barangay_id, status, submitted_at),
                KEY idx_monitoring_planting_history (rice_planting_id, observed_on),
                CONSTRAINT fk_monitoring_planting_barangay FOREIGN KEY (rice_planting_id, barangay_id) REFERENCES rice_plantings (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_monitoring_stage FOREIGN KEY (rice_stage_id) REFERENCES rice_stages (id) ON DELETE RESTRICT,
                CONSTRAINT fk_monitoring_submitter FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_monitoring_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_monitoring_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS crop_types (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                category VARCHAR(80) NOT NULL DEFAULT 'high_value_crop',
                description VARCHAR(500) NULL,
                is_sample TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_crop_types_name (name),
                KEY idx_crop_types_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS crop_plantings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                farmer_id INT UNSIGNED NOT NULL,
                farm_id INT UNSIGNED NOT NULL,
                crop_type_id INT UNSIGNED NOT NULL,
                planting_date DATE NOT NULL,
                expected_harvest_date DATE NULL,
                area_hectares DECIMAL(12,4) NULL,
                season VARCHAR(80) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                notes TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_crop_plantings_id_barangay (id, barangay_id),
                KEY idx_crop_plantings_barangay_date (barangay_id, planting_date),
                KEY idx_crop_plantings_expected_harvest (barangay_id, expected_harvest_date),
                CONSTRAINT fk_crop_plantings_farmer_barangay FOREIGN KEY (farmer_id, barangay_id) REFERENCES farmers (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_crop_plantings_farm_barangay FOREIGN KEY (farm_id, barangay_id) REFERENCES farms (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_crop_plantings_type FOREIGN KEY (crop_type_id) REFERENCES crop_types (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crop_plantings_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT,
                CONSTRAINT fk_crop_plantings_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS livestock_categories (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                description VARCHAR(500) NULL,
                is_sample TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_livestock_categories_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS livestock (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                farmer_id INT UNSIGNED NOT NULL,
                farm_id INT UNSIGNED NULL,
                livestock_category_id INT UNSIGNED NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                acquired_on DATE NULL,
                notes TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_livestock_id_barangay (id, barangay_id),
                KEY idx_livestock_barangay_category (barangay_id, livestock_category_id),
                CONSTRAINT fk_livestock_farmer_barangay FOREIGN KEY (farmer_id, barangay_id) REFERENCES farmers (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_livestock_farm_barangay FOREIGN KEY (farm_id, barangay_id) REFERENCES farms (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_livestock_category FOREIGN KEY (livestock_category_id) REFERENCES livestock_categories (id) ON DELETE RESTRICT,
                CONSTRAINT fk_livestock_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT,
                CONSTRAINT fk_livestock_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS livestock_health_records (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                livestock_id BIGINT UNSIGNED NOT NULL,
                record_type VARCHAR(80) NOT NULL,
                description TEXT NOT NULL,
                administered_on DATE NULL,
                next_due_on DATE NULL,
                provider VARCHAR(150) NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_livestock_health_due (barangay_id, next_due_on),
                CONSTRAINT fk_livestock_health_livestock_barangay FOREIGN KEY (livestock_id, barangay_id) REFERENCES livestock (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_livestock_health_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT,
                CONSTRAINT fk_livestock_health_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS production (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                farmer_id INT UNSIGNED NOT NULL,
                farm_id INT UNSIGNED NOT NULL,
                crop_type_id INT UNSIGNED NULL,
                rice_variety_id INT UNSIGNED NULL,
                production_year SMALLINT UNSIGNED NOT NULL,
                season VARCHAR(80) NULL,
                total_quantity DECIMAL(14,3) NOT NULL,
                unit VARCHAR(40) NOT NULL,
                is_sample TINYINT(1) NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_production_barangay_year (barangay_id, production_year),
                KEY idx_production_crop_year (crop_type_id, production_year),
                CONSTRAINT fk_production_farmer_barangay FOREIGN KEY (farmer_id, barangay_id) REFERENCES farmers (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_farm_barangay FOREIGN KEY (farm_id, barangay_id) REFERENCES farms (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_crop_type FOREIGN KEY (crop_type_id) REFERENCES crop_types (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_rice_variety FOREIGN KEY (rice_variety_id) REFERENCES rice_varieties (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT,
                CONSTRAINT fk_production_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS harvests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NOT NULL,
                farmer_id INT UNSIGNED NOT NULL,
                farm_id INT UNSIGNED NOT NULL,
                crop_planting_id BIGINT UNSIGNED NULL,
                rice_planting_id BIGINT UNSIGNED NULL,
                production_id BIGINT UNSIGNED NULL,
                harvested_on DATE NOT NULL,
                quantity DECIMAL(14,3) NOT NULL,
                unit VARCHAR(40) NOT NULL,
                quality_notes TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_harvests_barangay_date (barangay_id, harvested_on),
                CONSTRAINT fk_harvests_farmer_barangay FOREIGN KEY (farmer_id, barangay_id) REFERENCES farmers (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_harvests_farm_barangay FOREIGN KEY (farm_id, barangay_id) REFERENCES farms (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_harvests_crop_planting_barangay FOREIGN KEY (crop_planting_id, barangay_id) REFERENCES crop_plantings (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_harvests_rice_planting_barangay FOREIGN KEY (rice_planting_id, barangay_id) REFERENCES rice_plantings (id, barangay_id) ON DELETE RESTRICT,
                CONSTRAINT fk_harvests_production FOREIGN KEY (production_id) REFERENCES production (id) ON DELETE SET NULL,
                CONSTRAINT fk_harvests_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT,
                CONSTRAINT fk_harvests_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                barangay_id INT UNSIGNED NULL,
                title VARCHAR(180) NOT NULL,
                message TEXT NOT NULL,
                notification_type VARCHAR(80) NOT NULL,
                related_module VARCHAR(80) NULL,
                related_record_id BIGINT UNSIGNED NULL,
                read_at DATETIME NULL,
                delivery_status VARCHAR(30) NOT NULL DEFAULT 'in_app',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_notifications_user_read (user_id, read_at, created_at),
                KEY idx_notifications_barangay (barangay_id, created_at),
                CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_notifications_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS announcements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL,
                content MEDIUMTEXT NOT NULL,
                audience_barangay_id INT UNSIGNED NULL,
                status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
                published_at DATETIME NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_announcements_status_date (status, published_at),
                KEY idx_announcements_audience (audience_barangay_id, status),
                CONSTRAINT fk_announcements_barangay FOREIGN KEY (audience_barangay_id) REFERENCES barangays (id) ON DELETE RESTRICT,
                CONSTRAINT fk_announcements_author FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_key VARCHAR(80) NOT NULL DEFAULT 'general',
                sender_id INT UNSIGNED NOT NULL,
                barangay_id INT UNSIGNED NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_messages_conversation_date (conversation_key, created_at),
                KEY idx_messages_barangay_date (barangay_id, created_at),
                CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_messages_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS calendar_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NULL,
                title VARCHAR(200) NOT NULL,
                description TEXT NULL,
                event_type VARCHAR(80) NOT NULL,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NULL,
                related_module VARCHAR(80) NULL,
                related_record_id BIGINT UNSIGNED NULL,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_calendar_barangay_start (barangay_id, starts_at),
                CONSTRAINT fk_calendar_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE SET NULL,
                CONSTRAINT fk_calendar_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS import_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                barangay_id INT UNSIGNED NULL,
                user_id INT UNSIGNED NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                module VARCHAR(80) NOT NULL,
                status ENUM('previewed','completed','failed') NOT NULL DEFAULT 'previewed',
                total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
                error_count INT UNSIGNED NOT NULL DEFAULT 0,
                error_report JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_import_logs_user_date (user_id, created_at),
                KEY idx_import_logs_barangay_date (barangay_id, created_at),
                CONSTRAINT fk_import_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_import_logs_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS report_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                barangay_id INT UNSIGNED NULL,
                report_type VARCHAR(100) NOT NULL,
                filters JSON NULL,
                output_format VARCHAR(20) NOT NULL,
                status ENUM('queued','completed','failed') NOT NULL DEFAULT 'completed',
                file_path VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_report_runs_user_date (user_id, created_at),
                CONSTRAINT fk_report_runs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_report_runs_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NULL,
                role_slug VARCHAR(50) NULL,
                barangay_id INT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL,
                module VARCHAR(100) NOT NULL,
                record_id VARCHAR(100) NULL,
                old_values JSON NULL,
                new_values JSON NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_audit_logs_user_date (user_id, created_at),
                KEY idx_audit_logs_barangay_date (barangay_id, created_at),
                KEY idx_audit_logs_module_record (module, record_id),
                CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_audit_logs_barangay FOREIGN KEY (barangay_id) REFERENCES barangays (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS system_settings (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key VARCHAR(120) NOT NULL,
                setting_value JSON NOT NULL,
                description VARCHAR(255) NULL,
                updated_by INT UNSIGNED NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_system_settings_key (setting_key),
                CONSTRAINT fk_system_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($statements as $statement) {
            $this->_lava->db->raw($statement);
        }

        $this->add_user_column('full_name', [
            'type' => 'VARCHAR',
            'constraint' => 150,
            'null' => TRUE,
        ]);
        $this->add_user_column('role_id', [
            'type' => 'INT',
            'unsigned' => TRUE,
            'null' => TRUE,
        ]);
        $this->add_user_column('barangay_id', [
            'type' => 'INT',
            'unsigned' => TRUE,
            'null' => TRUE,
        ]);
        $this->add_user_column('deleted_at', [
            'type' => 'DATETIME',
            'null' => TRUE,
        ]);

        $this->_lava->db->raw(
            "ALTER TABLE users MODIFY role VARCHAR(40) NOT NULL DEFAULT 'barangay_personnel'"
        );
        $this->add_user_index('idx_users_role_id', 'role_id');
        $this->add_user_index('idx_users_barangay_id', 'barangay_id');
        $this->add_user_foreign_key(
            'fk_users_role',
            'role_id',
            'roles',
            'id',
            'RESTRICT'
        );
        $this->add_user_foreign_key(
            'fk_users_barangay',
            'barangay_id',
            'barangays',
            'id',
            'RESTRICT'
        );
    }

    public function down()
    {
        throw new RuntimeException(
            'AGRI-TRACK core tables contain operational records. Back up the database and create an explicit rollback migration instead.'
        );
    }

    private function add_user_column($column, array $definition)
    {
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', $column]
        )->fetchColumn();

        if ((int) $exists === 0) {
            $this->_lava->dbforge->add_column('users', [$column => $definition]);
        }
    }

    private function add_user_index($name, $column)
    {
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            ['users', $name]
        )->fetchColumn();

        if ((int) $exists === 0) {
            $this->_lava->db->raw(
                'ALTER TABLE users ADD INDEX `' . $name . '` (`' . $column . '`)'
            );
        }
    }

    private function add_user_foreign_key($name, $column, $reference_table, $reference_column, $on_delete)
    {
        $exists = $this->_lava->db->raw(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            ['users', $name]
        )->fetchColumn();

        if ((int) $exists === 0) {
            $this->_lava->db->raw(
                'ALTER TABLE users ADD CONSTRAINT `' . $name . '` FOREIGN KEY (`' .
                $column . '`) REFERENCES `' . $reference_table . '` (`' .
                $reference_column . '`) ON DELETE ' . $on_delete
            );
        }
    }
}
