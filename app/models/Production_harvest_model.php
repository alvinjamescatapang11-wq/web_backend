<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Production_harvest_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function references()
    {
        return [
            'cropTypes' => $this->db->raw(
                'SELECT id, name FROM crop_types
                 WHERE is_active = 1 AND is_sample = 0 ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC),
            'riceVarieties' => $this->db->raw(
                'SELECT id, name FROM rice_varieties
                 WHERE is_active = 1 AND is_sample = 0 ORDER BY name'
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function productions(array $user)
    {
        [$scope, $params] = $this->scope($user, 'production');
        return $this->db->raw(
            'SELECT production.id, production.barangay_id, production.farmer_id,
                    production.farm_id, production.production_year, production.season,
                    production.total_quantity, production.unit, production.notes,
                    production.crop_type_id, production.rice_variety_id,
                    crop_types.name AS crop_name, rice_varieties.name AS variety_name,
                    farms.farm_name, barangays.name AS barangay_name,
                    CONCAT(farmers.first_name, " ", farmers.last_name) AS farmer_name
             FROM production
             INNER JOIN farmers ON farmers.id = production.farmer_id
                AND farmers.barangay_id = production.barangay_id
             INNER JOIN farms ON farms.id = production.farm_id
                AND farms.barangay_id = production.barangay_id
             INNER JOIN barangays ON barangays.id = production.barangay_id
             LEFT JOIN crop_types ON crop_types.id = production.crop_type_id
             LEFT JOIN rice_varieties ON rice_varieties.id = production.rice_variety_id
             WHERE farmers.deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . '
             ORDER BY production.production_year DESC, production.id DESC',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function harvests(array $user)
    {
        [$scope, $params] = $this->scope($user, 'harvests');
        return $this->db->raw(
            'SELECT harvests.id, harvests.barangay_id, harvests.farmer_id,
                    harvests.farm_id, harvests.crop_planting_id,
                    harvests.rice_planting_id, harvests.production_id,
                    harvests.harvested_on, harvests.quantity, harvests.unit,
                    harvests.quality_notes, farms.farm_name, barangays.name AS barangay_name,
                    CONCAT(farmers.first_name, " ", farmers.last_name) AS farmer_name,
                    COALESCE(crop_types.name, rice_varieties.name) AS crop_name
             FROM harvests
             INNER JOIN farmers ON farmers.id = harvests.farmer_id
                AND farmers.barangay_id = harvests.barangay_id
             INNER JOIN farms ON farms.id = harvests.farm_id
                AND farms.barangay_id = harvests.barangay_id
             INNER JOIN barangays ON barangays.id = harvests.barangay_id
             LEFT JOIN crop_plantings ON crop_plantings.id = harvests.crop_planting_id
                AND crop_plantings.barangay_id = harvests.barangay_id
             LEFT JOIN crop_types ON crop_types.id = crop_plantings.crop_type_id
             LEFT JOIN rice_plantings ON rice_plantings.id = harvests.rice_planting_id
                AND rice_plantings.barangay_id = harvests.barangay_id
             LEFT JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
             WHERE harvests.deleted_at IS NULL AND farmers.deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . '
             ORDER BY harvests.harvested_on DESC, harvests.id DESC',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function harvest_report(array $user, $year)
    {
        [$scope, $params] = $this->scope($user, 'harvests');
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-01-01', $year + 1);
        $params = array_merge([$yearStart, $yearEnd], $params);
        $rows = $this->db->raw(
            'SELECT barangays.name AS barangay_name,
                    COALESCE(crop_types.name, rice_varieties.name, "Unspecified commodity") AS commodity,
                    harvests.unit, COUNT(*) AS harvest_count,
                    SUM(harvests.quantity) AS total_quantity
             FROM harvests
             INNER JOIN barangays ON barangays.id = harvests.barangay_id
             LEFT JOIN crop_plantings ON crop_plantings.id = harvests.crop_planting_id
                AND crop_plantings.barangay_id = harvests.barangay_id
             LEFT JOIN crop_types ON crop_types.id = crop_plantings.crop_type_id
             LEFT JOIN rice_plantings ON rice_plantings.id = harvests.rice_planting_id
                AND rice_plantings.barangay_id = harvests.barangay_id
             LEFT JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
             WHERE harvests.deleted_at IS NULL
               AND harvests.harvested_on >= ? AND harvests.harvested_on < ?'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . '
             GROUP BY barangays.name, commodity, harvests.unit
             ORDER BY barangays.name, commodity, harvests.unit',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->db->raw(
            'INSERT INTO report_runs (user_id, barangay_id, report_type, filters, output_format, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $user['id'],
                $user['role_slug'] === 'barangay_personnel' ? (int) $user['barangay_id'] : null,
                'annual_harvest_summary',
                json_encode(['year' => (int) $year], JSON_THROW_ON_ERROR),
                'json',
                'completed',
            ]
        );
        return $rows;
    }

    public function planting_options(array $user)
    {
        [$scope, $params] = $this->scope($user, 'farmers');
        $where = $scope ? ' AND ' . implode(' AND ', $scope) : '';
        return [
            'farmers' => $this->db->raw(
                'SELECT farmers.id, farmers.barangay_id, farmers.first_name,
                        farmers.last_name, barangays.name AS barangay_name
                 FROM farmers INNER JOIN barangays ON barangays.id = farmers.barangay_id
                 WHERE farmers.is_active = 1 AND farmers.deleted_at IS NULL
                   AND barangays.is_active = 1 AND barangays.deleted_at IS NULL' . $where . '
                 ORDER BY farmers.last_name, farmers.first_name',
                $params
            )->fetchAll(PDO::FETCH_ASSOC),
            'cropPlantings' => $this->db->raw(
                'SELECT crop_plantings.id, crop_plantings.farmer_id, crop_plantings.farm_id,
                        crop_types.name AS crop_name
                 FROM crop_plantings
                 INNER JOIN farmers ON farmers.id = crop_plantings.farmer_id
                    AND farmers.barangay_id = crop_plantings.barangay_id
                 INNER JOIN crop_types ON crop_types.id = crop_plantings.crop_type_id
                 WHERE crop_plantings.deleted_at IS NULL AND farmers.deleted_at IS NULL' . $where . '
                 ORDER BY crop_plantings.id DESC',
                $params
            )->fetchAll(PDO::FETCH_ASSOC),
            'ricePlantings' => $this->db->raw(
                'SELECT rice_plantings.id, rice_plantings.farmer_id, rice_plantings.farm_id,
                        rice_varieties.name AS variety_name
                 FROM rice_plantings
                 INNER JOIN farmers ON farmers.id = rice_plantings.farmer_id
                    AND farmers.barangay_id = rice_plantings.barangay_id
                 INNER JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
                 WHERE rice_plantings.deleted_at IS NULL AND farmers.deleted_at IS NULL' . $where . '
                 ORDER BY rice_plantings.id DESC',
                $params
            )->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function harvest_trend_forecast(array $user, $years)
    {
        [$scope, $params] = $this->scope($user, 'harvests');
        $currentYear = (int) date('Y');
        $firstYear = $currentYear - $years + 1;
        $startDate = sprintf('%04d-01-01', $firstYear);
        $endDate = sprintf('%04d-01-01', $currentYear + 1);
        $rows = $this->db->raw(
            'SELECT YEAR(harvests.harvested_on) AS year,
                    barangays.name AS barangay_name,
                    COALESCE(crop_types.name, rice_varieties.name, "Unspecified commodity") AS commodity,
                    harvests.unit, COUNT(*) AS harvest_count,
                    SUM(harvests.quantity) AS total_quantity
             FROM harvests
             INNER JOIN barangays ON barangays.id = harvests.barangay_id
             LEFT JOIN crop_plantings ON crop_plantings.id = harvests.crop_planting_id
                AND crop_plantings.barangay_id = harvests.barangay_id
                AND crop_plantings.deleted_at IS NULL
             LEFT JOIN crop_types ON crop_types.id = crop_plantings.crop_type_id
             LEFT JOIN rice_plantings ON rice_plantings.id = harvests.rice_planting_id
                AND rice_plantings.barangay_id = harvests.barangay_id
                AND rice_plantings.deleted_at IS NULL
             LEFT JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
             WHERE harvests.deleted_at IS NULL
               AND harvests.harvested_on >= ? AND harvests.harvested_on < ?'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . '
             GROUP BY YEAR(harvests.harvested_on), barangays.name, commodity, harvests.unit
             ORDER BY barangays.name, commodity, harvests.unit, year',
            array_merge([$startDate, $endDate], $params)
        )->fetchAll(PDO::FETCH_ASSOC);

        $series = [];
        foreach ($rows as $row) {
            $key = implode("\0", [$row['barangay_name'], $row['commodity'], $row['unit']]);
            if (!isset($series[$key])) {
                $series[$key] = [
                    'barangayName' => $row['barangay_name'],
                    'commodity' => $row['commodity'],
                    'unit' => $row['unit'],
                    'observations' => [],
                ];
            }
            $series[$key]['observations'][] = [
                'year' => (int) $row['year'],
                'quantity' => (float) $row['total_quantity'],
                'harvestCount' => (int) $row['harvest_count'],
            ];
        }

        foreach ($series as &$item) {
            $observations = $item['observations'];
            $recentObservations = array_slice($observations, -3);
            $hasRecentConsecutiveYears = count($recentObservations) === 3
                && (int) $recentObservations[2]['year'] === $currentYear
                && (int) $recentObservations[1]['year'] === $currentYear - 1
                && (int) $recentObservations[0]['year'] === $currentYear - 2;
            $item['forecastYear'] = $currentYear + 1;
            $item['forecastQuantity'] = null;
            $item['forecastMethod'] = 'linear trend from yearly recorded harvest totals';
            $item['forecastNote'] = !$hasRecentConsecutiveYears
                ? 'Three consecutive years with recorded harvests through the current year are required.'
                : 'Trend estimate only; it does not account for weather, planted area, pests, or market conditions.';
            if ($hasRecentConsecutiveYears) {
                $meanYear = array_sum(array_column($observations, 'year')) / count($observations);
                $meanQuantity = array_sum(array_column($observations, 'quantity')) / count($observations);
                $numerator = 0.0;
                $denominator = 0.0;
                foreach ($observations as $observation) {
                    $yearDelta = $observation['year'] - $meanYear;
                    $numerator += $yearDelta * ($observation['quantity'] - $meanQuantity);
                    $denominator += $yearDelta * $yearDelta;
                }
                if ($denominator > 0) {
                    $slope = $numerator / $denominator;
                    $prediction = $meanQuantity + $slope * ($item['forecastYear'] - $meanYear);
                    $item['forecastQuantity'] = round(max(0, $prediction), 3);
                }
            }
        }
        unset($item);

        return [
            'scope' => $user['role_slug'] === 'farmer' ? 'farmer' : ($user['role_slug'] === 'barangay_personnel' ? 'barangay' : 'city'),
            'firstYear' => $firstYear,
            'lastYear' => $currentYear,
            'forecastYear' => $currentYear + 1,
            'method' => 'ordinary least-squares linear trend over years with recorded harvests',
            'series' => array_values($series),
        ];
    }

    public function create_production(array $user, array $data)
    {
        $farmer = $this->farmer($user, $data['farmerId']);
        $farm = $farmer ? $this->farm($farmer, $data['farmId']) : null;
        if (!$farmer || !$farm) {
            throw new InvalidArgumentException('Choose an active farmer and associated farm in your authorized scope.');
        }
        $cropTypeId = $data['cropTypeId'];
        $riceVarietyId = $data['riceVarietyId'];
        if (($cropTypeId === null) === ($riceVarietyId === null)) {
            throw new InvalidArgumentException('Choose exactly one crop type or rice variety.');
        }
        if ($cropTypeId !== null) {
            $valid = $this->db->raw('SELECT id FROM crop_types WHERE id = ? AND is_active = 1 AND is_sample = 0 LIMIT 1', [$cropTypeId])->fetchColumn();
        } else {
            $valid = $this->db->raw('SELECT id FROM rice_varieties WHERE id = ? AND is_active = 1 AND is_sample = 0 LIMIT 1', [$riceVarietyId])->fetchColumn();
        }
        if (!$valid) {
            throw new InvalidArgumentException('The crop or rice variety is not active verified reference data.');
        }

        return $this->insert_audited(
            $user,
            (int) $farmer['barangay_id'],
            'production',
            'INSERT INTO production
                (barangay_id, farmer_id, farm_id, crop_type_id, rice_variety_id,
                 production_year, season, total_quantity, unit, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $farmer['barangay_id'], (int) $farmer['id'], (int) $farm['id'],
                $cropTypeId, $riceVarietyId, $data['productionYear'], $data['season'],
                $data['quantity'], $data['unit'], $data['notes'], (int) $user['id'],
            ],
            ['farmer_id' => (int) $farmer['id'], 'crop_type_id' => $cropTypeId, 'rice_variety_id' => $riceVarietyId]
        );
    }

    public function create_harvest(array $user, array $data)
    {
        $farmer = $this->farmer($user, $data['farmerId']);
        $farm = $farmer ? $this->farm($farmer, $data['farmId']) : null;
        if (!$farmer || !$farm) {
            throw new InvalidArgumentException('Choose an active farmer and associated farm in your authorized scope.');
        }
        if ($data['cropPlantingId'] !== null && $data['ricePlantingId'] !== null) {
            throw new InvalidArgumentException('A harvest can link to a rice planting or a crop planting, not both.');
        }
        if ($data['cropPlantingId'] !== null) {
            $planting = $this->db->raw(
                'SELECT id FROM crop_plantings WHERE id = ? AND farmer_id = ?
                 AND farm_id = ? AND barangay_id = ? AND deleted_at IS NULL LIMIT 1',
                [$data['cropPlantingId'], $farmer['id'], $farm['id'], $farmer['barangay_id']]
            )->fetchColumn();
            if (!$planting) {
                throw new InvalidArgumentException('The crop planting must belong to the selected farmer and farm.');
            }
        }
        if ($data['ricePlantingId'] !== null) {
            $planting = $this->db->raw(
                'SELECT id FROM rice_plantings WHERE id = ? AND farmer_id = ?
                 AND farm_id = ? AND barangay_id = ? AND deleted_at IS NULL LIMIT 1',
                [$data['ricePlantingId'], $farmer['id'], $farm['id'], $farmer['barangay_id']]
            )->fetchColumn();
            if (!$planting) {
                throw new InvalidArgumentException('The rice planting must belong to the selected farmer and farm.');
            }
        }
        $productionId = $data['productionId'];
        if ($productionId !== null) {
            $production = $this->db->raw(
                'SELECT id FROM production WHERE id = ? AND farmer_id = ? AND farm_id = ? AND barangay_id = ? LIMIT 1',
                [$productionId, $farmer['id'], $farm['id'], $farmer['barangay_id']]
            )->fetchColumn();
            if (!$production) {
                throw new InvalidArgumentException('The production summary must belong to the selected farmer and farm.');
            }
        }

        return $this->insert_audited(
            $user,
            (int) $farmer['barangay_id'],
            'harvests',
            'INSERT INTO harvests
                (barangay_id, farmer_id, farm_id, crop_planting_id, rice_planting_id,
                 production_id, harvested_on, quantity, unit, quality_notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $farmer['barangay_id'], (int) $farmer['id'], (int) $farm['id'],
                $data['cropPlantingId'], $data['ricePlantingId'], $productionId,
                $data['harvestedOn'], $data['quantity'], $data['unit'],
                $data['qualityNotes'], (int) $user['id'],
            ],
            ['farmer_id' => (int) $farmer['id'], 'quantity' => $data['quantity'], 'unit' => $data['unit']]
        );
    }

    private function farmer(array $user, $farmerId)
    {
        [$scope, $params] = $this->scope($user, 'farmers');
        return $this->db->raw(
            'SELECT id, barangay_id FROM farmers WHERE id = ?
             AND is_active = 1 AND deleted_at IS NULL'
             . ($scope ? ' AND ' . implode(' AND ', $scope) : '') . ' LIMIT 1',
            array_merge([(int) $farmerId], $params)
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function farm(array $farmer, $farmId)
    {
        return $this->db->raw(
            'SELECT id FROM farms WHERE id = ? AND farmer_id = ?
             AND barangay_id = ? AND deleted_at IS NULL LIMIT 1',
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
                (int) $user['id'], $user['role_slug'], $barangayId,
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
