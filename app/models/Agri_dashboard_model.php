<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Agri_dashboard_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function summary(array $user)
    {
        $barangayId = $user['role_slug'] === 'barangay_personnel'
            ? (int) $user['barangay_id']
            : null;

        $metrics = [
            'farmers' => $this->count_records('farmers', $barangayId, true),
            'farms' => $this->count_records('farms', $barangayId, true),
            'ricePlantings' => $this->count_records('rice_plantings', $barangayId, true),
            'cropPlantings' => $this->count_records('crop_plantings', $barangayId, true),
            'livestockHeads' => $this->sum_quantity('livestock', $barangayId),
            'harvestRecords' => $this->count_records('harvests', $barangayId, true),
            'pendingVerifications' => $this->count_records(
                'rice_monitoring_submissions',
                $barangayId,
                false,
                'status = ?',
                ['pending']
            ),
        ];

        if ($barangayId === null) {
            $metrics['barangays'] = $this->count_records('barangays', null, true, 'is_active = 1');
        }

        return [
            'scope' => [
                'role' => $user['role_slug'],
                'barangayId' => $barangayId,
                'barangayName' => $user['barangay_name'],
            ],
            'metrics' => $metrics,
            'productionTrend' => $this->production_trend($barangayId),
            'upcomingActivities' => $this->upcoming_activities($barangayId),
            'generatedAt' => gmdate(DATE_ATOM),
        ];
    }

    private function count_records($table, $barangayId, $excludeDeleted, $extraWhere = '', array $extraParams = [])
    {
        $allowedTables = [
            'farmers',
            'farms',
            'rice_plantings',
            'crop_plantings',
            'livestock',
            'harvests',
            'rice_monitoring_submissions',
            'barangays',
        ];
        if (!in_array($table, $allowedTables, true)) {
            throw new InvalidArgumentException('Unsupported dashboard table.');
        }

        $conditions = [];
        $params = [];
        if ($barangayId !== null) {
            $conditions[] = 'barangay_id = ?';
            $params[] = $barangayId;
        }
        if ($excludeDeleted && $table !== 'rice_monitoring_submissions') {
            $conditions[] = 'deleted_at IS NULL';
        }
        if ($extraWhere !== '') {
            $conditions[] = $extraWhere;
            $params = array_merge($params, $extraParams);
        }

        $sql = 'SELECT COUNT(*) FROM `' . $table . '`';
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        return (int) $this->db->raw($sql, $params)->fetchColumn();
    }

    private function sum_quantity($table, $barangayId)
    {
        $sql = 'SELECT COALESCE(SUM(quantity), 0) FROM `' . $table . '` WHERE deleted_at IS NULL';
        $params = [];
        if ($barangayId !== null) {
            $sql .= ' AND barangay_id = ?';
            $params[] = $barangayId;
        }
        return (int) $this->db->raw($sql, $params)->fetchColumn();
    }

    private function production_trend($barangayId)
    {
        $sql = "SELECT DATE_FORMAT(harvested_on, '%Y-%m') AS period,
                       unit,
                       SUM(quantity) AS quantity
                FROM harvests
                WHERE deleted_at IS NULL
                  AND harvested_on >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
        $params = [];
        if ($barangayId !== null) {
            $sql .= ' AND barangay_id = ?';
            $params[] = $barangayId;
        }
        $sql .= ' GROUP BY period, unit ORDER BY period ASC, unit ASC';

        return $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function upcoming_activities($barangayId)
    {
        $riceScope = $barangayId === null ? '' : ' AND rice_plantings.barangay_id = ?';
        $cropScope = $barangayId === null ? '' : ' AND crop_plantings.barangay_id = ?';
        $params = [];
        if ($barangayId !== null) {
            $params[] = $barangayId;
            $params[] = $barangayId;
        }

        $sql = "SELECT activities.id, activities.title, activities.activity_date,
                       activities.type, activities.farmer_name
                FROM (
                    SELECT rice_plantings.id,
                           CONCAT('Rice maturity · ', rice_varieties.name) AS title,
                           rice_plantings.expected_maturity_date AS activity_date,
                           'rice' AS type,
                           CONCAT(farmers.first_name, ' ', farmers.last_name) AS farmer_name
                    FROM rice_plantings
                    INNER JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
                    INNER JOIN farmers ON farmers.id = rice_plantings.farmer_id
                    WHERE rice_plantings.deleted_at IS NULL
                      AND rice_plantings.expected_maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
                . $riceScope .
                " UNION ALL
                    SELECT crop_plantings.id,
                           CONCAT('Crop harvest · ', crop_types.name) AS title,
                           crop_plantings.expected_harvest_date AS activity_date,
                           'crop' AS type,
                           CONCAT(farmers.first_name, ' ', farmers.last_name) AS farmer_name
                    FROM crop_plantings
                    INNER JOIN crop_types ON crop_types.id = crop_plantings.crop_type_id
                    INNER JOIN farmers ON farmers.id = crop_plantings.farmer_id
                    WHERE crop_plantings.deleted_at IS NULL
                      AND crop_plantings.expected_harvest_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
                . $cropScope .
                ') AS activities ORDER BY activity_date ASC LIMIT 8';

        return $this->db->raw($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
}
