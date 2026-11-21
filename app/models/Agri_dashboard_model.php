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

        $metrics = $this->dashboard_metrics($barangayId);

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

    private function dashboard_metrics($barangayId)
    {
        $definitions = [
            'farmers' => ['farmers', 'COUNT(*)', 'deleted_at IS NULL', true, []],
            'farms' => ['farms', 'COUNT(*)', 'deleted_at IS NULL', true, []],
            'ricePlantings' => ['rice_plantings', 'COUNT(*)', 'deleted_at IS NULL', true, []],
            'cropPlantings' => ['crop_plantings', 'COUNT(*)', 'deleted_at IS NULL', true, []],
            'livestockHeads' => ['livestock', 'COALESCE(SUM(quantity), 0)', 'deleted_at IS NULL', true, []],
            'harvestRecords' => ['harvests', 'COUNT(*)', 'deleted_at IS NULL', true, []],
            'pendingVerifications' => ['rice_monitoring_submissions', 'COUNT(*)', 'status = ?', true, ['pending']],
        ];
        if ($barangayId === null) {
            $definitions['barangays'] = ['barangays', 'COUNT(*)', 'is_active = 1', false, []];
        }

        $expressions = [];
        $params = [];

        foreach ($definitions as $alias => [$table, $aggregate, $condition, $scopeToBarangay, $conditionParams]) {
            $conditions = [];
            if ($barangayId !== null && $scopeToBarangay) {
                $conditions[] = 'barangay_id = ?';
                $params[] = $barangayId;
            }
            if ($condition !== '') {
                $conditions[] = $condition;
            }
            $params = array_merge($params, $conditionParams);
            $expressions[] = '(SELECT ' . $aggregate . ' FROM `' . $table . '` WHERE '
                . implode(' AND ', $conditions) . ') AS `' . $alias . '`';
        }

        $row = $this->db->raw('SELECT ' . implode(', ', $expressions), $params)->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Dashboard metrics could not be calculated.');
        }

        return array_map('intval', $row);
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
