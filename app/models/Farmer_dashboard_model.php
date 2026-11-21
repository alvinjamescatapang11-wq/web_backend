<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Farmer_dashboard_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function summary(array $user)
    {
        $farmerId = isset($user['farmer_id']) ? (int) $user['farmer_id'] : 0;
        if ($farmerId < 1) {
            throw new InvalidArgumentException('An active farmer profile is required.');
        }

        $profile = $this->db->raw(
            'SELECT farmers.id, farmers.registry_number, farmers.first_name,
                    farmers.middle_name, farmers.last_name, farmers.suffix,
                    farmers.sex, farmers.phone, farmers.email, farmers.address,
                    farmers.primary_livelihood, farmers.is_sample,
                    barangays.name AS barangay_name
             FROM farmers
             INNER JOIN barangays ON barangays.id = farmers.barangay_id
             WHERE farmers.id = ?
               AND farmers.user_id = ?
               AND farmers.is_active = 1
               AND farmers.deleted_at IS NULL
             LIMIT 1',
            [$farmerId, (int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            throw new RuntimeException('The signed-in account is not linked to an active farmer profile.');
        }

        $metrics = [
            'farms' => $this->count_records('farms', $farmerId),
            'ricePlantings' => $this->count_records('rice_plantings', $farmerId),
            'cropPlantings' => $this->count_records('crop_plantings', $farmerId),
            'livestockHeads' => (int) $this->db->raw(
                'SELECT COALESCE(SUM(quantity), 0)
                 FROM livestock
                 WHERE farmer_id = ? AND deleted_at IS NULL',
                [$farmerId]
            )->fetchColumn(),
            'harvestRecords' => $this->count_records('harvests', $farmerId),
        ];

        return [
            'profile' => $profile,
            'metrics' => $metrics,
            'farms' => $this->farms($farmerId),
            'upcomingActivities' => $this->upcoming_activities($farmerId),
            'recentHarvests' => $this->recent_harvests($farmerId),
            'generatedAt' => gmdate(DATE_ATOM),
        ];
    }

    private function count_records($table, $farmerId)
    {
        $allowedTables = ['farms', 'rice_plantings', 'crop_plantings', 'harvests'];
        if (!in_array($table, $allowedTables, true)) {
            throw new InvalidArgumentException('Unsupported farmer dashboard table.');
        }

        return (int) $this->db->raw(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE farmer_id = ? AND deleted_at IS NULL',
            [$farmerId]
        )->fetchColumn();
    }

    private function farms($farmerId)
    {
        return $this->db->raw(
            'SELECT farm_name, location_description, area_hectares, ownership_type, is_sample
             FROM farms
             WHERE farmer_id = ? AND deleted_at IS NULL
             ORDER BY created_at DESC
             LIMIT 5',
            [$farmerId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function upcoming_activities($farmerId)
    {
        return $this->db->raw(
            "SELECT activities.id, activities.title, activities.activity_date, activities.type
             FROM (
                 SELECT rice_plantings.id,
                        CONCAT('Rice maturity · ', rice_varieties.name) AS title,
                        rice_plantings.expected_maturity_date AS activity_date,
                        'rice' AS type
                 FROM rice_plantings
                 INNER JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
                 WHERE rice_plantings.farmer_id = ?
                   AND rice_plantings.deleted_at IS NULL
                   AND rice_plantings.expected_maturity_date
                       BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 UNION ALL
                 SELECT crop_plantings.id,
                        CONCAT('Crop harvest · ', crop_types.name) AS title,
                        crop_plantings.expected_harvest_date AS activity_date,
                        'crop' AS type
                 FROM crop_plantings
                 INNER JOIN crop_types ON crop_types.id = crop_plantings.crop_type_id
                 WHERE crop_plantings.farmer_id = ?
                   AND crop_plantings.deleted_at IS NULL
                   AND crop_plantings.expected_harvest_date
                       BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ) AS activities
             ORDER BY activities.activity_date ASC
             LIMIT 8",
            [$farmerId, $farmerId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function recent_harvests($farmerId)
    {
        return $this->db->raw(
            "SELECT harvests.harvested_on, harvests.quantity, harvests.unit,
                    harvests.quality_notes,
                    COALESCE(crop_types.name, rice_varieties.name, 'Harvest') AS crop_name
             FROM harvests
             LEFT JOIN crop_plantings ON crop_plantings.id = harvests.crop_planting_id
             LEFT JOIN crop_types ON crop_types.id = crop_plantings.crop_type_id
             LEFT JOIN rice_plantings ON rice_plantings.id = harvests.rice_planting_id
             LEFT JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
             WHERE harvests.farmer_id = ?
               AND harvests.deleted_at IS NULL
             ORDER BY harvests.harvested_on DESC, harvests.id DESC
             LIMIT 5",
            [$farmerId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
