<?php

class add_farmer_portal
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
        $db = $this->_lava->db;

        $db->raw(
            'INSERT INTO roles (slug, name, description)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)',
            ['farmer', 'Farmer', 'A farmer who can view their own profile and agricultural records.']
        );
        $db->raw(
            'INSERT INTO permissions (permission_key, description)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description)',
            ['farmer.dashboard.view', 'View the signed-in farmer dashboard and personal records.']
        );
        $db->raw(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles
             INNER JOIN permissions ON permissions.permission_key = ?
             WHERE roles.slug = ?',
            ['farmer.dashboard.view', 'farmer']
        );

        $columnExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            ['farmers', 'user_id']
        )->fetchColumn();

        if ($columnExists === 0) {
            $db->raw('ALTER TABLE farmers ADD COLUMN user_id INT UNSIGNED NULL AFTER barangay_id');
        }

        $indexExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            ['farmers', 'uq_farmers_user_id']
        )->fetchColumn();

        if ($indexExists === 0) {
            $db->raw('ALTER TABLE farmers ADD UNIQUE KEY uq_farmers_user_id (user_id)');
        }

        $foreignKeyExists = (int) $db->raw(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?',
            ['farmers', 'fk_farmers_user']
        )->fetchColumn();

        if ($foreignKeyExists === 0) {
            $db->raw(
                'ALTER TABLE farmers
                 ADD CONSTRAINT fk_farmers_user
                 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL'
            );
        }
    }

    public function down()
    {
        throw new RuntimeException(
            'Farmer profiles and accounts may be in use. Preserve existing records and use a reviewed rollback migration.'
        );
    }
}
