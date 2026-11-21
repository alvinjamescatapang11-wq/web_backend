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

        $dashboard = $this->dashboard_data($barangayId);

        return [
            'scope' => [
                'role' => $user['role_slug'],
                'barangayId' => $barangayId,
                'barangayName' => $user['barangay_name'],
            ],
            'metrics' => $dashboard['metrics'],
            'productionTrend' => $dashboard['productionTrend'],
            'upcomingActivities' => $dashboard['upcomingActivities'],
            'generatedAt' => gmdate(DATE_ATOM),
        ];
    }

    private function dashboard_data($barangayId)
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

        $trendScope = '';
        if ($barangayId !== null) {
            $trendScope = ' AND barangay_id = ?';
            $params[] = $barangayId;
        }
        $expressions[] = "(SELECT JSON_ARRAYAGG(JSON_OBJECT(
                                'period', trend.period,
                                'unit', trend.unit,
                                'quantity', trend.quantity
                            ))
                          FROM (
                              SELECT DATE_FORMAT(harvested_on, '%Y-%m') AS period,
                                     unit,
                                     SUM(quantity) AS quantity
                              FROM harvests
                              WHERE deleted_at IS NULL
                                AND harvested_on >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)"
            . $trendScope .
            ' GROUP BY period, unit
                          ) AS trend) AS `productionTrend`';

        $riceScope = '';
        $cropScope = '';
        $activityParams = [];
        if ($barangayId !== null) {
            $riceScope = ' AND rice_plantings.barangay_id = ?';
            $cropScope = ' AND crop_plantings.barangay_id = ?';
            $activityParams = [$barangayId, $barangayId];
        }
        $expressions[] = "(SELECT JSON_ARRAYAGG(JSON_OBJECT(
                                'id', activities.id,
                                'title', activities.title,
                                'activity_date', activities.activity_date,
                                'type', activities.type,
                                'farmer_name', activities.farmer_name
                            ))
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
                                AND rice_plantings.expected_maturity_date
                                    BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
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
                                AND crop_plantings.expected_harvest_date
                                    BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
            . $cropScope .
            ' ORDER BY activity_date ASC
                              LIMIT 8
                          ) AS activities) AS `upcomingActivities`';
        $params = array_merge($params, $activityParams);

        $row = $this->db->raw('SELECT ' . implode(', ', $expressions), $params)->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Dashboard data could not be calculated.');
        }

        $metrics = [];
        foreach (array_keys($definitions) as $alias) {
            $metrics[$alias] = (int) $row[$alias];
        }

        $productionTrend = $this->decode_json_array($row['productionTrend'], 'production trend');
        usort($productionTrend, static function ($left, $right) {
            $periodOrder = strcmp((string) $left['period'], (string) $right['period']);
            return $periodOrder !== 0
                ? $periodOrder
                : strcmp((string) ($left['unit'] ?? ''), (string) ($right['unit'] ?? ''));
        });

        $upcomingActivities = $this->decode_json_array($row['upcomingActivities'], 'upcoming activities');
        usort($upcomingActivities, static function ($left, $right) {
            return strcmp((string) $left['activity_date'], (string) $right['activity_date']);
        });

        return [
            'metrics' => $metrics,
            'productionTrend' => $productionTrend,
            'upcomingActivities' => $upcomingActivities,
        ];
    }

    private function decode_json_array($json, $section)
    {
        if ($json === null) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Dashboard ' . $section . ' could not be decoded.', 0, $error);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Dashboard ' . $section . ' could not be decoded.');
        }

        return $decoded;
    }
}
