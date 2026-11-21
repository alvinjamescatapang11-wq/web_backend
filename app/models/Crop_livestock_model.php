<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Crop_livestock_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function reference_data()
    {
        return [
            'cropTypes' => $this->db->raw(
                'SELECT id, name, category, description, is_sample
                 FROM crop_types WHERE is_active = 1 ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC),
            'livestockCategories' => $this->db->raw(
                'SELECT id, name, description, is_sample
                 FROM livestock_categories WHERE is_active = 1 ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function crop_plantings(array $user)
    {
        [$scope, $params] = $this->scope($user, 'farmers');
        return $this->db->raw(
            'SELECT crop_plantings.id, crop_plantings.barangay_id, crop_plantings.farmer_id,
                    crop_plantings.farm_id, crop_plantings.crop_type_id,
                    crop_plantings.planting_date, crop_plantings.expected_harvest_date,
                    crop_plantings.area_hectares, crop_plantings.season,
                    crop_plantings.status, crop_plantings.notes,
                    crop_types.name AS crop_name, farms.farm_name,
                    barangays.name AS barangay_name,
                    CONCAT(farmers.first_name, " ", farmers.last_name) AS farmer_name
             FROM crop_plantings
             INNER JOIN farmers ON farmers.id = crop_plantings.farmer_id
                AND farmers.barangay_id = crop_plantings.barangay_id
             INNER JOIN crop_types ON crop_types.id = crop_plantings.crop_type_id
             INNER JOIN farms ON farms.id = crop_plantings.farm_id
                AND farms.barangay_id = crop_plantings.barangay_id
             INNER JOIN barangays ON barangays.id = crop_plantings.barangay_id
             WHERE crop_plantings.deleted_at IS NULL
               AND farmers.deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . '
             ORDER BY crop_plantings.planting_date DESC, crop_plantings.id DESC',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function livestock_records(array $user)
    {
        [$scope, $params] = $this->scope($user, 'farmers');
        return $this->db->raw(
            'SELECT livestock.id, livestock.barangay_id, livestock.farmer_id,
                    livestock.farm_id, livestock.livestock_category_id,
                    livestock.quantity, livestock.acquired_on, livestock.notes,
                    livestock_categories.name AS category_name, farms.farm_name,
                    barangays.name AS barangay_name,
                    CONCAT(farmers.first_name, " ", farmers.last_name) AS farmer_name
             FROM livestock
             INNER JOIN farmers ON farmers.id = livestock.farmer_id
                AND farmers.barangay_id = livestock.barangay_id
             INNER JOIN livestock_categories ON livestock_categories.id = livestock.livestock_category_id
             INNER JOIN barangays ON barangays.id = livestock.barangay_id
             LEFT JOIN farms ON farms.id = livestock.farm_id
                AND farms.barangay_id = livestock.barangay_id
             WHERE livestock.deleted_at IS NULL AND farmers.deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . '
             ORDER BY livestock_categories.name, farmers.last_name, livestock.id DESC',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function health_records(array $user)
    {
        [$scope, $params] = $this->scope($user, 'livestock');
        return $this->db->raw(
            'SELECT health.id, health.livestock_id, health.record_type, health.description,
                    health.administered_on, health.next_due_on, health.provider,
                    livestock.livestock_category_id, livestock_categories.name AS category_name,
                    livestock.quantity, livestock.farmer_id, livestock.barangay_id,
                    barangays.name AS barangay_name,
                    CONCAT(farmers.first_name, " ", farmers.last_name) AS farmer_name
             FROM livestock_health_records AS health
             INNER JOIN livestock ON livestock.id = health.livestock_id
                AND livestock.barangay_id = health.barangay_id
             INNER JOIN farmers ON farmers.id = livestock.farmer_id
                AND farmers.barangay_id = livestock.barangay_id
             INNER JOIN livestock_categories ON livestock_categories.id = livestock.livestock_category_id
             INNER JOIN barangays ON barangays.id = health.barangay_id
             WHERE farmers.deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . '
             ORDER BY health.next_due_on IS NULL, health.next_due_on, health.administered_on DESC',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create_crop_planting(array $user, array $data)
    {
        $farmer = $this->active_farmer($user, $data['farmerId']);
        $farm = $farmer ? $this->active_farm($farmer, $data['farmId']) : null;
        $crop = $this->db->raw(
            'SELECT id FROM crop_types WHERE id = ? AND is_active = 1 AND is_sample = 0 LIMIT 1',
            [$data['cropTypeId']]
        )->fetchColumn();
        if (!$farmer || !$farm || !$crop) {
            throw new InvalidArgumentException('Choose an active farmer and farm in your scope and a configured crop type.');
        }

        return $this->insert_audited(
            $user,
            (int) $farmer['barangay_id'],
            'crop_plantings',
            'INSERT INTO crop_plantings
                (barangay_id, farmer_id, farm_id, crop_type_id, planting_date,
                 expected_harvest_date, area_hectares, season, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $farmer['barangay_id'], (int) $farmer['id'], (int) $farm['id'],
                (int) $data['cropTypeId'], $data['plantingDate'], $data['expectedHarvestDate'],
                $data['areaHectares'], $data['season'], $data['notes'], (int) $user['id'],
            ],
            ['farmer_id' => (int) $farmer['id'], 'crop_type_id' => (int) $data['cropTypeId']]
        );
    }

    public function create_livestock(array $user, array $data)
    {
        $farmer = $this->active_farmer($user, $data['farmerId']);
        $farm = $data['farmId'] === null ? null : ($farmer ? $this->active_farm($farmer, $data['farmId']) : null);
        $category = $this->db->raw(
            'SELECT id FROM livestock_categories
             WHERE id = ? AND is_active = 1 AND is_sample = 0 LIMIT 1',
            [$data['categoryId']]
        )->fetchColumn();
        if (!$farmer || ($data['farmId'] !== null && !$farm) || !$category) {
            throw new InvalidArgumentException('Choose an active farmer, optional associated farm, and configured livestock category.');
        }

        return $this->insert_audited(
            $user,
            (int) $farmer['barangay_id'],
            'livestock',
            'INSERT INTO livestock
                (barangay_id, farmer_id, farm_id, livestock_category_id,
                 quantity, acquired_on, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $farmer['barangay_id'], (int) $farmer['id'],
                $farm ? (int) $farm['id'] : null, (int) $data['categoryId'],
                (int) $data['quantity'], $data['acquiredOn'], $data['notes'],
                (int) $user['id'],
            ],
            ['farmer_id' => (int) $farmer['id'], 'category_id' => (int) $data['categoryId'], 'quantity' => (int) $data['quantity']]
        );
    }

    public function create_health_record(array $user, array $data)
    {
        [$scope, $params] = $this->scope($user, 'livestock');
        $livestock = $this->db->raw(
            'SELECT livestock.id, livestock.barangay_id
             FROM livestock
             WHERE livestock.id = ? AND livestock.deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . ' LIMIT 1',
            array_merge([(int) $data['livestockId']], $params)
        )->fetch(PDO::FETCH_ASSOC);
        if (!$livestock) {
            throw new InvalidArgumentException('Choose a livestock record in your authorized data scope.');
        }

        return $this->insert_audited(
            $user,
            (int) $livestock['barangay_id'],
            'livestock_health_records',
            'INSERT INTO livestock_health_records
                (barangay_id, livestock_id, record_type, description,
                 administered_on, next_due_on, provider, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $livestock['barangay_id'], (int) $livestock['id'],
                $data['recordType'], $data['description'], $data['administeredOn'],
                $data['nextDueOn'], $data['provider'], (int) $user['id'],
            ],
            ['livestock_id' => (int) $livestock['id'], 'record_type' => $data['recordType']]
        );
    }

    public function archive(array $user, $table, $id)
    {
        $allowed = ['crop_plantings', 'livestock'];
        if (!in_array($table, $allowed, true)) {
            throw new InvalidArgumentException('This record type cannot be archived.');
        }
        [$scope, $params] = $this->scope($user, $table);
        $record = $this->db->raw(
            'SELECT id, barangay_id FROM `' . $table . '`
             WHERE id = ? AND deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . ' LIMIT 1',
            array_merge([(int) $id], $params)
        )->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            return false;
        }
        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE `' . $table . '` SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND deleted_at IS NULL',
                [(int) $id]
            );
            (new Audit_logger($this->db))->record(
                (int) $user['id'], $user['role_slug'], (int) $record['barangay_id'],
                'archive', $table, (int) $id
            );
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    private function active_farmer(array $user, $farmerId)
    {
        [$scope, $params] = $this->scope($user, 'farmers');
        return $this->db->raw(
            'SELECT id, barangay_id FROM farmers
             WHERE id = ? AND is_active = 1 AND deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . ' LIMIT 1',
            array_merge([(int) $farmerId], $params)
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function active_farm(array $farmer, $farmId)
    {
        return $this->db->raw(
            'SELECT id FROM farms
             WHERE id = ? AND farmer_id = ? AND barangay_id = ? AND deleted_at IS NULL LIMIT 1',
            [(int) $farmId, (int) $farmer['id'], (int) $farmer['barangay_id']]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function insert_audited(array $user, $barangayId, $module, $sql, array $params, array $auditValues)
    {
        $this->db->transaction();
        try {
            $this->db->raw($sql, $params);
            $id = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            (new Audit_logger($this->db))->record(
                (int) $user['id'], $user['role_slug'], (int) $barangayId,
                'create', $module, $id, null, $auditValues
            );
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    private function scope(array $user, $alias)
    {
        if ($user['role_slug'] === 'barangay_personnel') {
            return [[$alias . '.barangay_id = ?'], [(int) $user['barangay_id']]];
        }
        if ($user['role_slug'] === 'farmer') {
            return [[$alias . '.farmer_id = ?'], [(int) $user['farmer_id']]];
        }
        return [[], []];
    }
}
