<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Farmer_management_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function list_records(array $user, $query, $page, $perPage)
    {
        [$where, $params] = $this->scope($user);
        $where[] = 'farmers.deleted_at IS NULL';
        $where[] = '(farmers.first_name LIKE ? OR farmers.middle_name LIKE ? OR farmers.last_name LIKE ? OR farmers.registry_number LIKE ? OR farmers.phone LIKE ?)';
        $search = '%' . $query . '%';
        array_push($params, $search, $search, $search, $search, $search);

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->raw(
            'SELECT COUNT(*)
             FROM farmers
             INNER JOIN barangays ON barangays.id = farmers.barangay_id
             WHERE ' . $whereSql,
            $params
        )->fetchColumn();

        $records = $this->db->raw(
            'SELECT farmers.id, farmers.registry_number, farmers.first_name,
                    farmers.middle_name, farmers.last_name, farmers.suffix,
                    farmers.sex, farmers.phone, farmers.email,
                    farmers.primary_livelihood, farmers.barangay_id,
                    barangays.name AS barangay_name, farmers.is_active,
                    farmers.is_sample, farmers.created_at
             FROM farmers
             INNER JOIN barangays ON barangays.id = farmers.barangay_id
             WHERE ' . $whereSql . '
             ORDER BY farmers.last_name ASC, farmers.first_name ASC
             LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $records,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function find_record(array $user, $farmerId)
    {
        [$where, $params] = $this->scope($user);
        $where[] = 'farmers.id = ?';
        $where[] = 'farmers.deleted_at IS NULL';
        $params[] = $farmerId;

        return $this->db->raw(
            'SELECT farmers.*, barangays.name AS barangay_name
             FROM farmers
             INNER JOIN barangays ON barangays.id = farmers.barangay_id
             WHERE ' . implode(' AND ', $where) . '
             LIMIT 1',
            $params
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function create_record(array $user, array $data)
    {
        $barangayId = $user['role_slug'] === 'barangay_personnel'
            ? (int) $user['barangay_id']
            : $data['barangayId'];

        $this->db->transaction();
        try {
            $activeBarangayId = $this->db->raw(
                'SELECT id FROM barangays
                 WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
                 LIMIT 1',
                [$barangayId]
            )->fetchColumn();
            if (!$activeBarangayId) {
                throw new InvalidArgumentException('Choose an active barangay.');
            }

            $this->db->raw(
                'INSERT INTO farmers
                    (barangay_id, registry_number, first_name, middle_name,
                     last_name, suffix, sex, birth_date, phone, email,
                     address, primary_livelihood, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $barangayId,
                    $data['registryNumber'],
                    $data['firstName'],
                    $data['middleName'],
                    $data['lastName'],
                    $data['suffix'],
                    $data['sex'],
                    $data['birthDate'],
                    $data['phone'],
                    $data['email'],
                    $data['address'],
                    $data['primaryLivelihood'],
                    (int) $user['id'],
                ]
            );
            $farmerId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();

            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                $barangayId,
                'create',
                'farmers',
                $farmerId,
                null,
                ['first_name' => $data['firstName'], 'last_name' => $data['lastName']]
            );
            $this->db->commit();
            return $farmerId;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function update_record(array $user, $farmerId, array $data)
    {
        $current = $this->find_record($user, $farmerId);
        if (!$current) {
            return false;
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE farmers
                 SET registry_number = ?, first_name = ?, middle_name = ?,
                     last_name = ?, suffix = ?, sex = ?, birth_date = ?,
                     phone = ?, email = ?, address = ?,
                     primary_livelihood = ?, updated_by = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND deleted_at IS NULL',
                [
                    $data['registryNumber'],
                    $data['firstName'],
                    $data['middleName'],
                    $data['lastName'],
                    $data['suffix'],
                    $data['sex'],
                    $data['birthDate'],
                    $data['phone'],
                    $data['email'],
                    $data['address'],
                    $data['primaryLivelihood'],
                    (int) $user['id'],
                    $farmerId,
                ]
            );

            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                (int) $current['barangay_id'],
                'update',
                'farmers',
                $farmerId,
                ['first_name' => $current['first_name'], 'last_name' => $current['last_name']],
                ['first_name' => $data['firstName'], 'last_name' => $data['lastName']]
            );
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function archive_record(array $user, $farmerId)
    {
        $current = $this->find_record($user, $farmerId);
        if (!$current) {
            return false;
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE farmers
                 SET is_active = 0, deleted_at = UTC_TIMESTAMP(), updated_by = ?
                 WHERE id = ? AND deleted_at IS NULL',
                [(int) $user['id'], $farmerId]
            );
            if (!empty($current['user_id'])) {
                $this->db->raw(
                    'UPDATE users SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                    [(int) $current['user_id']]
                );
                $this->db->raw(
                    'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                     WHERE user_id = ? AND revoked_at IS NULL',
                    [(int) $current['user_id']]
                );
            }

            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                (int) $current['barangay_id'],
                'archive',
                'farmers',
                $farmerId
            );
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    private function scope(array $user)
    {
        if ($user['role_slug'] === 'barangay_personnel') {
            return [['farmers.barangay_id = ?'], [(int) $user['barangay_id']]];
        }

        return [[], []];
    }
}
