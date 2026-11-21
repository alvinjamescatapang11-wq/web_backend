<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';
require_once APP_DIR . 'libraries/Mail_queue.php';

class User_management_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function list_accounts($query, $role, $status, $barangayId, $page, $perPage)
    {
        $where = ['users.deleted_at IS NULL'];
        $params = [];
        if ($query !== '') {
            $where[] = '(users.username LIKE ? OR users.full_name LIKE ? OR users.email LIKE ?)';
            $search = '%' . $query . '%';
            array_push($params, $search, $search, $search);
        }
        if ($role !== '') {
            $where[] = 'roles.slug = ?';
            $params[] = $role;
        }
        if ($status !== '') {
            $where[] = 'users.is_active = ?';
            $params[] = $status === 'active' ? 1 : 0;
        }
        if ($barangayId !== null) {
            $where[] = 'users.barangay_id = ?';
            $params[] = $barangayId;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->raw(
            'SELECT COUNT(*)
             FROM users
             INNER JOIN roles ON roles.id = users.role_id
             LEFT JOIN barangays ON barangays.id = users.barangay_id
             LEFT JOIN farmers ON farmers.user_id = users.id
             WHERE ' . $whereSql,
            $params
        )->fetchColumn();

        $accounts = $this->db->raw(
            'SELECT users.id, users.username, users.email, users.full_name,
                    roles.slug AS role, users.barangay_id, barangays.name AS barangay_name,
                    farmers.id AS farmer_id, users.is_active, users.created_at
             FROM users
             INNER JOIN roles ON roles.id = users.role_id
             LEFT JOIN barangays ON barangays.id = users.barangay_id
             LEFT JOIN farmers ON farmers.user_id = users.id
             WHERE ' . $whereSql . '
             ORDER BY users.created_at DESC, users.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $accounts,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function create_account(array $actor, array $data)
    {
        $roleId = $this->db->raw(
            'SELECT id FROM roles WHERE slug = ? LIMIT 1',
            [$data['role']]
        )->fetchColumn();
        if (!$roleId) {
            throw new InvalidArgumentException('The selected role is not configured.');
        }

        $barangayId = null;
        if ($data['role'] === 'barangay_personnel') {
            $barangay = $this->db->raw(
                'SELECT id FROM barangays WHERE id = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
                [$data['barangayId']]
            )->fetchColumn();
            if (!$barangay) {
                throw new InvalidArgumentException('The selected barangay is not active.');
            }
            $barangayId = (int) $barangay;
        }

        $farmer = null;
        if ($data['role'] === 'farmer') {
            $farmer = $this->db->raw(
                'SELECT id, barangay_id FROM farmers
                 WHERE id = ? AND user_id IS NULL AND is_active = 1 AND deleted_at IS NULL
                 LIMIT 1',
                [$data['farmerId']]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$farmer) {
                throw new InvalidArgumentException('Choose an active, unlinked farmer record.');
            }
            $barangayId = (int) $farmer['barangay_id'];
        }

        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO users
                    (username, email, password, role, role_id, barangay_id, full_name, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
                [
                    $data['username'],
                    $data['email'],
                    $passwordHash,
                    $data['role'],
                    (int) $roleId,
                    $barangayId,
                    $data['fullName'],
                ]
            );
            $userId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();

            if ($farmer) {
                $linked = $this->db->raw(
                    'UPDATE farmers SET user_id = ? WHERE id = ? AND user_id IS NULL',
                    [$userId, (int) $farmer['id']]
                );
                if ($linked->rowCount() !== 1) {
                    throw new RuntimeException('The selected farmer profile was linked to another account. No account was created.');
                }
            }

            (new Audit_logger($this->db))->record(
                (int) $actor['id'],
                $actor['role_slug'],
                $barangayId,
                'account_created',
                'user_administration',
                $userId,
                null,
                ['role' => $data['role'], 'email' => $data['email']]
            );
            (new Mail_queue($this->db))->enqueue(
                $data['email'],
                'Your AGRI-TRACK account is ready',
                "Hello {$data['fullName']},\n\nAn AGRI-TRACK account has been created for you. Contact your CAO administrator or barangay office for your sign-in details.",
                'account_created',
                $userId,
                $barangayId
            );
            $this->db->commit();
            return $userId;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function update_account(array $actor, $accountId, array $changes)
    {
        $account = $this->db->raw(
            'SELECT users.id, users.is_active, roles.slug AS role
             FROM users INNER JOIN roles ON roles.id = users.role_id
             WHERE users.id = ? AND users.deleted_at IS NULL
             LIMIT 1',
            [$accountId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$account || $account['role'] === 'cao_admin') {
            return false;
        }
        if ((int) $accountId === (int) $actor['id'] && array_key_exists('isActive', $changes) && !$changes['isActive']) {
            throw new InvalidArgumentException('You cannot deactivate your own account.');
        }

        $sets = [];
        $params = [];
        if (array_key_exists('isActive', $changes)) {
            $sets[] = 'is_active = ?';
            $params[] = $changes['isActive'] ? 1 : 0;
        }
        if (array_key_exists('password', $changes)) {
            $hash = password_hash($changes['password'], PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Password hashing failed.');
            }
            $sets[] = 'password = ?';
            $params[] = $hash;
        }
        if (!$sets) {
            throw new InvalidArgumentException('Provide an account status or new password to update.');
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE users SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND deleted_at IS NULL',
                array_merge($params, [$accountId])
            );
            if (
                (array_key_exists('isActive', $changes) && !$changes['isActive']) ||
                array_key_exists('password', $changes)
            ) {
                $this->db->raw(
                    'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                     WHERE user_id = ? AND revoked_at IS NULL',
                    [$accountId]
                );
            }

            (new Audit_logger($this->db))->record(
                (int) $actor['id'],
                $actor['role_slug'],
                null,
                array_key_exists('password', $changes) ? 'password_reset' : 'account_status_changed',
                'user_administration',
                $accountId,
                ['is_active' => (int) $account['is_active']],
                array_key_exists('isActive', $changes) ? ['is_active' => $changes['isActive'] ? 1 : 0] : null
            );
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }
}
