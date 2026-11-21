<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Audit_logger
{
    private $db;

    public function __construct($database = null)
    {
        if ($database !== null) {
            $this->db = $database;
            return;
        }

        $lava = lava_instance();
        $this->db = $lava->call->database();
    }

    public function record(
        $userId,
        $roleSlug,
        $barangayId,
        $action,
        $module,
        $recordId = null,
        $oldValues = null,
        $newValues = null
    ) {
        $this->db->raw(
            'INSERT INTO audit_logs
                (user_id, role_slug, barangay_id, action, module, record_id,
                 old_values, new_values, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $roleSlug,
                $barangayId,
                $action,
                $module,
                $recordId === null ? null : (string) $recordId,
                $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR),
                $newValues === null ? null : json_encode($newValues, JSON_THROW_ON_ERROR),
                isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null,
                isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            ]
        );
    }
}
