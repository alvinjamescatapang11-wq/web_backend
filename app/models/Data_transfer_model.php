<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Data_transfer_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function export_rows(array $user, $module)
    {
        $role = $user['role_slug'];
        $params = [];
        if ($module === 'farmers') {
            if (!in_array($role, ['cao_admin', 'cao_staff', 'barangay_personnel'], true)) {
                throw new InvalidArgumentException('Your role cannot export farmer records.');
            }
            $where = ['farmers.deleted_at IS NULL'];
            if ($role === 'barangay_personnel') {
                $where[] = 'farmers.barangay_id = ?';
                $params[] = (int) $user['barangay_id'];
            }
            $sql = 'SELECT farmers.registry_number, farmers.first_name, farmers.middle_name,
                           farmers.last_name, farmers.suffix, farmers.sex, farmers.birth_date,
                           farmers.phone, farmers.email, farmers.address,
                           farmers.primary_livelihood, barangays.name AS barangay
                    FROM farmers
                    INNER JOIN barangays ON barangays.id = farmers.barangay_id
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY barangays.name, farmers.last_name, farmers.first_name
                    LIMIT 5001';
        } elseif ($module === 'farms') {
            $where = ['farms.deleted_at IS NULL', 'farmers.deleted_at IS NULL'];
            if ($role === 'barangay_personnel') {
                $where[] = 'farms.barangay_id = ?';
                $params[] = (int) $user['barangay_id'];
            } elseif ($role === 'farmer') {
                $where[] = 'farms.farmer_id = ?';
                $params[] = (int) $user['farmer_id'];
            } elseif (!in_array($role, ['cao_admin', 'cao_staff'], true)) {
                throw new InvalidArgumentException('Your role cannot export farm records.');
            }
            $sql = 'SELECT farms.farm_name, farmers.registry_number,
                           CONCAT_WS(CHAR(32), farmers.first_name, farmers.middle_name, farmers.last_name) AS farmer,
                           barangays.name AS barangay, farms.location_description,
                           farms.area_hectares, farms.ownership_type, farms.latitude, farms.longitude
                    FROM farms
                    INNER JOIN farmers ON farmers.id = farms.farmer_id
                    INNER JOIN barangays ON barangays.id = farms.barangay_id
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY barangays.name, farmers.last_name, farms.farm_name
                    LIMIT 5001';
        } elseif ($module === 'harvests') {
            $where = ['harvests.deleted_at IS NULL'];
            if ($role === 'barangay_personnel') {
                $where[] = 'harvests.barangay_id = ?';
                $params[] = (int) $user['barangay_id'];
            } elseif ($role === 'farmer') {
                $where[] = 'harvests.farmer_id = ?';
                $params[] = (int) $user['farmer_id'];
            } elseif (!in_array($role, ['cao_admin', 'cao_staff'], true)) {
                throw new InvalidArgumentException('Your role cannot export harvest records.');
            }
            $sql = 'SELECT harvests.harvested_on,
                           CONCAT_WS(CHAR(32), farmers.first_name, farmers.middle_name, farmers.last_name) AS farmer,
                           barangays.name AS barangay, farms.farm_name, harvests.quantity,
                           harvests.unit, harvests.quality_notes
                    FROM harvests
                    INNER JOIN farmers ON farmers.id = harvests.farmer_id
                    INNER JOIN farms ON farms.id = harvests.farm_id
                    INNER JOIN barangays ON barangays.id = harvests.barangay_id
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY harvests.harvested_on DESC, harvests.id DESC
                    LIMIT 5001';
        } elseif ($module === 'livestock') {
            $where = ['livestock.deleted_at IS NULL', 'farmers.deleted_at IS NULL'];
            if ($role === 'barangay_personnel') {
                $where[] = 'livestock.barangay_id = ?';
                $params[] = (int) $user['barangay_id'];
            } elseif ($role === 'farmer') {
                $where[] = 'livestock.farmer_id = ?';
                $params[] = (int) $user['farmer_id'];
            } elseif (!in_array($role, ['cao_admin', 'cao_staff'], true)) {
                throw new InvalidArgumentException('Your role cannot export livestock records.');
            }
            $sql = 'SELECT farmers.registry_number, livestock_categories.name AS category,
                           CONCAT_WS(CHAR(32), farmers.first_name, farmers.middle_name, farmers.last_name) AS farmer,
                           barangays.name AS barangay, farms.farm_name, livestock.quantity,
                           livestock.acquired_on, livestock.notes
                    FROM livestock
                    INNER JOIN farmers ON farmers.id = livestock.farmer_id
                        AND farmers.barangay_id = livestock.barangay_id
                    INNER JOIN livestock_categories ON livestock_categories.id = livestock.livestock_category_id
                    INNER JOIN barangays ON barangays.id = livestock.barangay_id
                    LEFT JOIN farms ON farms.id = livestock.farm_id
                        AND farms.barangay_id = livestock.barangay_id
                    WHERE ' . implode(' AND ', $where) . '
                    ORDER BY barangays.name, livestock_categories.name, farmers.last_name
                    LIMIT 5001';
        } else {
            throw new InvalidArgumentException('Choose an available export module.');
        }

        $rows = $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 5000) {
            throw new LengthException('This export exceeds 5,000 rows. Narrow the data scope before exporting.');
        }
        return $rows;
    }

    public function record_import(array $user, $filename, $rowCount, $importedCount, array $errors, $module = 'farmers')
    {
        $barangayId = $user['barangay_id'] === null ? null : (int) $user['barangay_id'];
        $status = $importedCount > 0 ? 'completed' : 'failed';
        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO import_logs
                    (barangay_id, user_id, original_filename, module, status,
                     total_rows, imported_rows, error_count, error_report, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [
                    $barangayId,
                    (int) $user['id'],
                    $filename,
                    $module,
                    $status,
                    $rowCount,
                    $importedCount,
                    count($errors),
                    $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                ]
            );
            $id = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                $barangayId,
                'import',
                $module,
                $id,
                null,
                ['filename' => $filename, 'rows' => $rowCount, 'imported' => $importedCount, 'errors' => count($errors)]
            );
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function find_farmer_for_import(array $user, $registryNumber)
    {
        $where = [
            'farmers.registry_number = ?',
            'farmers.is_active = 1',
            'farmers.is_sample = 0',
            'farmers.deleted_at IS NULL',
            'barangays.is_active = 1',
            'barangays.is_sample = 0',
            'barangays.deleted_at IS NULL',
        ];
        $params = [$registryNumber];
        if ($user['role_slug'] === 'barangay_personnel') {
            $where[] = 'farmers.barangay_id = ?';
            $params[] = (int) $user['barangay_id'];
        }
        return $this->db->raw(
            'SELECT farmers.id, farmers.barangay_id
             FROM farmers
             INNER JOIN barangays ON barangays.id = farmers.barangay_id
             WHERE ' . implode(' AND ', $where) . ' LIMIT 2',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find_farm_for_import($farmerId, $farmName)
    {
        return $this->db->raw(
            'SELECT id FROM farms
             WHERE farmer_id = ? AND farm_name = ? AND deleted_at IS NULL
             LIMIT 2',
            [(int) $farmerId, $farmName]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find_livestock_category_for_import($categoryName)
    {
        return $this->db->raw(
            'SELECT id FROM livestock_categories
             WHERE name = ? AND is_active = 1 AND is_sample = 0
             LIMIT 2',
            [$categoryName]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function has_verified_active_barangay($id)
    {
        return (bool) $this->db->raw(
            'SELECT id FROM barangays
             WHERE id = ? AND is_active = 1 AND is_sample = 0 AND deleted_at IS NULL
             LIMIT 1',
            [(int) $id]
        )->fetchColumn();
    }

    public function import_history(array $user)
    {
        $where = [];
        $params = [];
        if ($user['role_slug'] === 'barangay_personnel') {
            $where[] = 'import_logs.barangay_id = ?';
            $params[] = (int) $user['barangay_id'];
        } elseif (!in_array($user['role_slug'], ['cao_admin', 'cao_staff'], true)) {
            throw new InvalidArgumentException('Your role cannot view import history.');
        }
        return $this->db->raw(
            'SELECT import_logs.id, import_logs.barangay_id, import_logs.original_filename,
                    import_logs.module, import_logs.status, import_logs.total_rows,
                    import_logs.imported_rows, import_logs.error_count,
                    import_logs.error_report, import_logs.created_at, import_logs.completed_at,
                    users.full_name AS imported_by, barangays.name AS barangay_name
             FROM import_logs
             INNER JOIN users ON users.id = import_logs.user_id
             LEFT JOIN barangays ON barangays.id = import_logs.barangay_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY import_logs.created_at DESC, import_logs.id DESC
             LIMIT 100',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
