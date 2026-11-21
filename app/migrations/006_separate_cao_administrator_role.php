<?php

class separate_cao_administrator_role
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

        $db->raw(
            'INSERT INTO roles (slug, name, description)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)',
            ['cao_admin', 'CAO Administrator', 'System administrator with protected account and settings management privileges.']
        );

        $db->raw(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT roles.id, permissions.id
             FROM roles CROSS JOIN permissions
             WHERE roles.slug = ?',
            ['cao_admin']
        );

        $db->raw(
            'DELETE role_permissions
             FROM role_permissions
             INNER JOIN roles ON roles.id = role_permissions.role_id
             INNER JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE roles.slug = ?
               AND permissions.permission_key IN (?, ?)',
            ['cao_staff', 'users.manage', 'settings.manage']
        );
    }

    public function down()
    {
        throw new RuntimeException(
            'Administrator role assignments may be in use. Preserve existing accounts and use a reviewed rollback migration.'
        );
    }
}
