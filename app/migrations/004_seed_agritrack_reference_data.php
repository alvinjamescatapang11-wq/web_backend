<?php

class seed_agritrack_reference_data
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

        $roles = [
            ['cao_staff', 'CAO Staff', 'City Agriculture Office staff with system-wide access.'],
            ['barangay_personnel', 'Barangay Personnel', 'Personnel restricted to their assigned barangay.'],
        ];
        foreach ($roles as $role) {
            $db->raw(
                'INSERT INTO roles (slug, name, description) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)',
                $role
            );
        }

        $db->raw(
            "UPDATE users SET role = 'cao_staff' WHERE role = 'admin'"
        );
        $db->raw(
            "UPDATE users SET role = 'barangay_personnel' WHERE role IN ('moderator', 'user')"
        );
        $db->raw(
            "UPDATE users SET role = 'barangay_personnel'
             WHERE role NOT IN ('cao_staff', 'barangay_personnel')"
        );
        $db->raw(
            'UPDATE users
             INNER JOIN roles ON roles.slug = users.role
             SET users.role_id = roles.id
             WHERE users.role_id IS NULL'
        );

        $missing_role_assignments = (int) $db->raw(
            'SELECT COUNT(*) FROM users WHERE role_id IS NULL'
        )->fetchColumn();
        if ($missing_role_assignments > 0) {
            throw new RuntimeException('Unable to assign a recognized role to every existing user.');
        }

        $db->raw('ALTER TABLE users MODIFY role_id INT UNSIGNED NOT NULL');

        $permissions = [
            'dashboard.view',
            'farmers.view', 'farmers.manage',
            'barangays.view', 'barangays.manage',
            'farms.view', 'farms.manage',
            'rice.view', 'rice.manage', 'rice.monitor', 'rice.verify',
            'crops.view', 'crops.manage',
            'livestock.view', 'livestock.manage',
            'production.view', 'production.manage',
            'reports.view', 'reports.export',
            'map.view',
            'notifications.view', 'notifications.manage',
            'calendar.view', 'calendar.manage',
            'imports.manage',
            'messages.view', 'messages.send',
            'audit.view',
            'users.manage',
            'settings.manage',
        ];

        foreach ($permissions as $permission) {
            $db->raw(
                'INSERT INTO permissions (permission_key, description) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE permission_key = VALUES(permission_key)',
                [$permission, 'AGRI-TRACK permission: ' . $permission]
            );
        }

        $db->raw(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles CROSS JOIN permissions
             WHERE roles.slug = ?',
            ['cao_staff']
        );

        $barangay_permissions = [
            'dashboard.view',
            'farmers.view', 'farmers.manage',
            'barangays.view',
            'farms.view', 'farms.manage',
            'rice.view', 'rice.manage', 'rice.monitor',
            'crops.view', 'crops.manage',
            'livestock.view', 'livestock.manage',
            'production.view', 'production.manage',
            'reports.view', 'reports.export',
            'map.view',
            'notifications.view',
            'calendar.view', 'calendar.manage',
            'imports.manage',
            'messages.view', 'messages.send',
        ];
        foreach ($barangay_permissions as $permission) {
            $db->raw(
                'INSERT IGNORE INTO role_permissions (role_id, permission_id)
                 SELECT roles.id, permissions.id
                 FROM roles
                 INNER JOIN permissions ON permissions.permission_key = ?
                 WHERE roles.slug = ?',
                [$permission, 'barangay_personnel']
            );
        }

        $db->raw(
            "INSERT INTO barangays (code, name, description, is_sample)
             VALUES ('SAMPLE-001', 'SAMPLE ONLY - replace with official CAO barangay list',
                     'Placeholder for development only. Replace with the verified Calapan City CAO barangay dataset; no official coordinates are supplied.',
                     1)
             ON DUPLICATE KEY UPDATE description = VALUES(description), is_sample = 1"
        );

        $stages = [
            ['Seedling', 1],
            ['Tillering', 2],
            ['Booting', 3],
            ['Heading', 4],
            ['Ripening', 5],
            ['Harvest', 6],
        ];
        foreach ($stages as $stage) {
            $db->raw(
                'INSERT INTO rice_stages (name, sequence_number) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE sequence_number = VALUES(sequence_number)',
                $stage
            );
        }

        $crops = ['Ampalaya', 'Talong', 'Sitaw', 'Kalabasa', 'Okra', 'Kamatis'];
        foreach ($crops as $crop) {
            $db->raw(
                'INSERT INTO crop_types (name, category, is_sample) VALUES (?, ?, 0)
                 ON DUPLICATE KEY UPDATE category = VALUES(category)',
                [$crop, 'high_value_crop']
            );
        }

        $db->raw(
            "INSERT INTO rice_varieties (name, growth_duration_days, description, is_sample)
             VALUES ('Sample variety - replace with verified CAO data', NULL,
                     'Development placeholder only. Add the verified variety name and growth duration before using maturity-date calculations.',
                     1)
             ON DUPLICATE KEY UPDATE description = VALUES(description), is_sample = 1"
        );

        $db->raw(
            "INSERT INTO livestock_categories (name, description, is_sample)
             VALUES ('Sample livestock category - replace with CAO list',
                     'Development placeholder only; replace with the CAO-approved livestock categories.',
                     1)
             ON DUPLICATE KEY UPDATE description = VALUES(description), is_sample = 1"
        );

        $settings = [
            ['app_name', '"AGRI-TRACK"', 'Application display name.'],
            ['timezone', '"Asia/Manila"', 'Application reporting timezone.'],
            ['sample_data_mode', 'true', 'Indicates that sample reference records are present.'],
        ];
        foreach ($settings as $setting) {
            $db->raw(
                'INSERT INTO system_settings (setting_key, setting_value, description)
                 VALUES (?, CAST(? AS JSON), ?)
                 ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)',
                $setting
            );
        }
    }

    public function down()
    {
        throw new RuntimeException(
            'Reference data may be in use. Remove or replace individual seed records through an explicit data migration.'
        );
    }
}
