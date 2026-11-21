<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Rice_management_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function varieties()
    {
        return $this->db->raw(
            'SELECT id, name, growth_duration_days, description, is_sample
             FROM rice_varieties
             WHERE is_active = 1
             ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function stages()
    {
        return $this->db->raw(
            'SELECT id, name, sequence_number, description
             FROM rice_stages
             ORDER BY sequence_number ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function plantings(array $user, $page, $perPage)
    {
        [$scopeSql, $scopeParams] = $this->scope($user, 'rice_plantings');
        $where = array_merge(['rice_plantings.deleted_at IS NULL'], $scopeSql);
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->raw(
            'SELECT COUNT(*) FROM rice_plantings
             INNER JOIN farmers ON farmers.id = rice_plantings.farmer_id
             WHERE ' . $whereSql,
            $scopeParams
        )->fetchColumn();

        $data = $this->db->raw(
            'SELECT rice_plantings.id, rice_plantings.barangay_id,
                    rice_plantings.farmer_id, rice_plantings.farm_id,
                    rice_plantings.rice_variety_id, rice_plantings.planting_date,
                    rice_plantings.expected_maturity_date, rice_plantings.area_hectares,
                    rice_plantings.season, rice_plantings.status,
                    rice_varieties.name AS variety_name, barangays.name AS barangay_name,
                    farms.farm_name,
                    CONCAT(farmers.first_name, " ", farmers.last_name) AS farmer_name
             FROM rice_plantings
             INNER JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
             INNER JOIN farmers ON farmers.id = rice_plantings.farmer_id
             INNER JOIN farms ON farms.id = rice_plantings.farm_id
             INNER JOIN barangays ON barangays.id = rice_plantings.barangay_id
             WHERE ' . $whereSql . '
             ORDER BY rice_plantings.planting_date DESC, rice_plantings.id DESC
             LIMIT ? OFFSET ?',
            array_merge($scopeParams, [$perPage, ($page - 1) * $perPage])
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function create_planting(array $user, array $data)
    {
        $barangayId = $user['role_slug'] === 'barangay_personnel'
            ? (int) $user['barangay_id']
            : $data['barangayId'];
        $ownership = $this->db->raw(
            'SELECT farmers.id
             FROM farmers
             INNER JOIN farms ON farms.farmer_id = farmers.id
                AND farms.barangay_id = farmers.barangay_id
             INNER JOIN rice_varieties ON rice_varieties.id = ?
             INNER JOIN barangays ON barangays.id = farmers.barangay_id
             WHERE farmers.id = ? AND farms.id = ? AND farmers.barangay_id = ?
               AND farmers.is_active = 1 AND farmers.deleted_at IS NULL
               AND farms.deleted_at IS NULL
               AND rice_varieties.is_active = 1
               AND rice_varieties.growth_duration_days IS NOT NULL
               AND rice_varieties.is_sample = 0
               AND barangays.is_active = 1 AND barangays.deleted_at IS NULL
             LIMIT 1',
            [$data['riceVarietyId'], $data['farmerId'], $data['farmId'], $barangayId]
        )->fetchColumn();
        if (!$ownership) {
            throw new InvalidArgumentException(
                'The farmer, farm, barangay, and verified rice variety must be active and correctly associated.'
            );
        }

        $duration = (int) $this->db->raw(
            'SELECT growth_duration_days FROM rice_varieties
             WHERE id = ? AND is_active = 1 AND is_sample = 0',
            [$data['riceVarietyId']]
        )->fetchColumn();
        if ($duration < 1) {
            throw new InvalidArgumentException('A verified rice variety growth duration is required.');
        }
        $maturity = (new DateTimeImmutable($data['plantingDate']))
            ->modify('+' . $duration . ' days')
            ->format('Y-m-d');

        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO rice_plantings
                    (barangay_id, farmer_id, farm_id, rice_variety_id, planting_date,
                     expected_maturity_date, area_hectares, season, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $barangayId,
                    $data['farmerId'],
                    $data['farmId'],
                    $data['riceVarietyId'],
                    $data['plantingDate'],
                    $maturity,
                    $data['areaHectares'],
                    $data['season'],
                    $data['notes'],
                    (int) $user['id'],
                ]
            );
            $plantingId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                $barangayId,
                'create',
                'rice_plantings',
                $plantingId,
                null,
                ['farmer_id' => $data['farmerId'], 'rice_variety_id' => $data['riceVarietyId'], 'expected_maturity_date' => $maturity]
            );
            $this->db->commit();
            return ['id' => $plantingId, 'expectedMaturityDate' => $maturity];
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function monitoring(array $user, $page, $perPage)
    {
        [$scopeSql, $scopeParams] = $this->scope($user, 'rice_plantings');
        $where = array_merge(['rice_plantings.deleted_at IS NULL'], $scopeSql);
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->raw(
            'SELECT COUNT(*)
             FROM rice_monitoring_submissions
             INNER JOIN rice_plantings ON rice_plantings.id = rice_monitoring_submissions.rice_planting_id
             WHERE ' . $whereSql,
            $scopeParams
        )->fetchColumn();

        $data = $this->db->raw(
            'SELECT submissions.id, submissions.rice_planting_id,
                    submissions.observed_on, submissions.notes, submissions.status,
                    submissions.submitted_at, submissions.reviewed_at,
                    submissions.rejection_reason, rice_stages.name AS stage,
                    rice_plantings.farmer_id, rice_plantings.barangay_id,
                    rice_varieties.name AS variety_name,
                    CONCAT(farmers.first_name, " ", farmers.last_name) AS farmer_name,
                    submitter.full_name AS submitted_by_name,
                    reviewer.full_name AS reviewed_by_name
             FROM rice_monitoring_submissions AS submissions
             INNER JOIN rice_plantings ON rice_plantings.id = submissions.rice_planting_id
             INNER JOIN farmers ON farmers.id = rice_plantings.farmer_id
             INNER JOIN rice_stages ON rice_stages.id = submissions.rice_stage_id
             INNER JOIN rice_varieties ON rice_varieties.id = rice_plantings.rice_variety_id
             INNER JOIN users AS submitter ON submitter.id = submissions.submitted_by
             LEFT JOIN users AS reviewer ON reviewer.id = submissions.reviewed_by
             WHERE ' . $whereSql . '
             ORDER BY submissions.observed_on DESC, submissions.id DESC
             LIMIT ? OFFSET ?',
            array_merge($scopeParams, [$perPage, ($page - 1) * $perPage])
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function submit_monitoring(array $user, array $data)
    {
        $planting = $this->find_planting_for_user($user, $data['plantingId']);
        if (!$planting) {
            throw new InvalidArgumentException('The rice planting was not found in your authorized data scope.');
        }
        $stage = $this->db->raw(
            'SELECT id FROM rice_stages WHERE id = ? LIMIT 1',
            [$data['stageId']]
        )->fetchColumn();
        if (!$stage) {
            throw new InvalidArgumentException('Choose a configured rice growth stage.');
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO rice_monitoring_submissions
                    (barangay_id, rice_planting_id, rice_stage_id, submitted_by,
                     observed_on, notes, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $planting['barangay_id'],
                    (int) $planting['id'],
                    (int) $stage,
                    (int) $user['id'],
                    $data['observedOn'],
                    $data['notes'],
                    'pending',
                ]
            );
            $submissionId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                (int) $planting['barangay_id'],
                'submit',
                'rice_monitoring',
                $submissionId,
                null,
                ['rice_planting_id' => (int) $planting['id'], 'stage_id' => (int) $stage]
            );
            $this->db->commit();
            return $submissionId;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function review_monitoring(array $user, $submissionId, $status, $reason)
    {
        $this->db->transaction();
        try {
            $submission = $this->db->raw(
                'SELECT id, barangay_id, submitted_by, status
                 FROM rice_monitoring_submissions WHERE id = ? FOR UPDATE',
                [$submissionId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$submission) {
                $this->db->commit();
                return false;
            }
            if ($submission['status'] !== 'pending') {
                throw new InvalidArgumentException('Only pending submissions may be reviewed.');
            }
            if ((int) $submission['submitted_by'] === (int) $user['id']) {
                throw new InvalidArgumentException('You cannot review your own monitoring submission.');
            }
            if ($status === 'rejected' && $reason === '') {
                throw new InvalidArgumentException('A rejection reason is required.');
            }

            $this->db->raw(
                'UPDATE rice_monitoring_submissions
                 SET status = ?, rejection_reason = ?, reviewed_by = ?,
                     reviewed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = ?',
                [$status, $status === 'rejected' ? $reason : null, (int) $user['id'], $submissionId, 'pending']
            );
            $this->db->raw(
                'INSERT INTO notifications
                    (user_id, barangay_id, title, message, notification_type,
                     related_module, related_record_id)
                 SELECT farmers.user_id, ?, ?, ?, ?, ?, ?
                 FROM rice_monitoring_submissions
                 INNER JOIN rice_plantings ON rice_plantings.id = rice_monitoring_submissions.rice_planting_id
                 INNER JOIN farmers ON farmers.id = rice_plantings.farmer_id
                 WHERE rice_monitoring_submissions.id = ? AND farmers.user_id IS NOT NULL',
                [
                    (int) $submission['barangay_id'],
                    $status === 'approved' ? 'Rice update approved' : 'Rice update needs revision',
                    $status === 'approved' ? 'Your rice monitoring update was approved.' : $reason,
                    'rice_monitoring',
                    'rice_monitoring',
                    $submissionId,
                    $submissionId,
                ]
            );
            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                (int) $submission['barangay_id'],
                $status,
                'rice_monitoring',
                $submissionId,
                ['status' => 'pending'],
                ['status' => $status, 'rejection_reason' => $status === 'rejected' ? $reason : null]
            );
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    private function find_planting_for_user(array $user, $plantingId)
    {
        $where = ['rice_plantings.id = ?', 'rice_plantings.deleted_at IS NULL'];
        $params = [$plantingId];
        if ($user['role_slug'] === 'barangay_personnel') {
            $where[] = 'rice_plantings.barangay_id = ?';
            $params[] = (int) $user['barangay_id'];
        } elseif ($user['role_slug'] === 'farmer') {
            $where[] = 'rice_plantings.farmer_id = ?';
            $params[] = (int) $user['farmer_id'];
        }

        return $this->db->raw(
            'SELECT rice_plantings.id, rice_plantings.barangay_id, rice_plantings.farmer_id
             FROM rice_plantings WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            $params
        )->fetch(PDO::FETCH_ASSOC);
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
