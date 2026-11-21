<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class Rice extends Controller
{
    public function varieties()
    {
        $this->call->model('Rice_management_model');
        (new Api_response())->success($this->Rice_management_model->varieties());
    }

    public function stages()
    {
        $this->call->model('Rice_management_model');
        (new Api_response())->success($this->Rice_management_model->stages());
    }

    public function plantings()
    {
        $page = $this->query_integer('page', 1, 1, 100000);
        $perPage = $this->query_integer('perPage', 20, 1, 100);
        $this->call->model('Rice_management_model');
        (new Api_response())->success(
            $this->Rice_management_model->plantings(Auth_context::user(), $page, $perPage)
        );
    }

    public function create_planting()
    {
        $body = $this->json_body();
        $data = [
            'farmerId' => $this->positive_integer($body['farmerId'] ?? null),
            'farmId' => $this->positive_integer($body['farmId'] ?? null),
            'riceVarietyId' => $this->positive_integer($body['riceVarietyId'] ?? null),
            'barangayId' => $this->positive_integer($body['barangayId'] ?? null),
            'plantingDate' => $this->date_field($body['plantingDate'] ?? null, 'plantingDate'),
            'areaHectares' => $this->decimal_field($body['areaHectares'] ?? null, 'areaHectares'),
            'season' => $this->optional_text($body['season'] ?? null, 80, 'season'),
            'notes' => $this->optional_text($body['notes'] ?? null, 5000, 'notes'),
        ];
        foreach (['farmerId', 'farmId', 'riceVarietyId'] as $required) {
            if (!$data[$required]) {
                (new Api_response())->error('invalid_' . $required, ucfirst($required) . ' is required.', 422);
            }
        }
        if (Auth_context::user()['role_slug'] !== 'barangay_personnel' && !$data['barangayId']) {
            (new Api_response())->error('invalid_barangayId', 'A barangay is required.', 422);
        }

        $this->call->model('Rice_management_model');
        try {
            $result = $this->Rice_management_model->create_planting(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_planting', $exception->getMessage(), 422);
        }
        (new Api_response())->success($result, 201);
    }

    public function monitoring()
    {
        $page = $this->query_integer('page', 1, 1, 100000);
        $perPage = $this->query_integer('perPage', 20, 1, 100);
        $this->call->model('Rice_management_model');
        (new Api_response())->success(
            $this->Rice_management_model->monitoring(Auth_context::user(), $page, $perPage)
        );
    }

    public function submit_monitoring()
    {
        $body = $this->json_body();
        $data = [
            'plantingId' => $this->positive_integer($body['plantingId'] ?? null),
            'stageId' => $this->positive_integer($body['stageId'] ?? null),
            'observedOn' => $this->date_field($body['observedOn'] ?? null, 'observedOn'),
            'notes' => $this->optional_text($body['notes'] ?? null, 5000, 'notes'),
        ];
        if (!$data['plantingId'] || !$data['stageId']) {
            (new Api_response())->error('invalid_request', 'A planting and configured growth stage are required.', 422);
        }

        $this->call->model('Rice_management_model');
        try {
            $id = $this->Rice_management_model->submit_monitoring(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_monitoring', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id, 'status' => 'pending'], 201);
    }

    public function review_monitoring($id)
    {
        $submissionId = $this->positive_integer($id);
        if (!$submissionId) {
            (new Api_response())->error('invalid_id', 'The monitoring submission ID is invalid.', 422);
        }
        $body = $this->json_body();
        $status = isset($body['status']) && is_string($body['status']) ? trim($body['status']) : '';
        $reason = isset($body['rejectionReason']) && is_string($body['rejectionReason'])
            ? trim($body['rejectionReason'])
            : '';
        if (!in_array($status, ['approved', 'rejected'], true) || strlen($reason) > 1000) {
            (new Api_response())->error('invalid_review', 'Choose approved or rejected and provide a valid rejection reason.', 422);
        }

        $this->call->model('Rice_management_model');
        try {
            $updated = $this->Rice_management_model->review_monitoring(
                Auth_context::user(),
                $submissionId,
                $status,
                $reason
            );
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_review', $exception->getMessage(), 422);
        }
        if (!$updated) {
            (new Api_response())->error('not_found', 'The monitoring submission was not found.', 404);
        }
        (new Api_response())->success(['id' => $submissionId, 'status' => $status]);
    }

    private function json_body()
    {
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide a JSON object.', 400);
        }
        return $body;
    }

    private function date_field($value, $field)
    {
        if (!is_string($value)) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a date in YYYY-MM-DD format.', 422);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value > gmdate('Y-m-d')) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a valid date not in the future.', 422);
        }
        return $value;
    }

    private function optional_text($value, $maximum, $field)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen(trim($value)) > $maximum) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is invalid or too long.', 422);
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function decimal_field($value, $field)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 1000000) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a positive number.', 422);
        }
        return (float) $value;
    }

    private function query_integer($key, $default, $minimum, $maximum)
    {
        if (!isset($_GET[$key])) {
            return $default;
        }
        $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            (new Api_response())->error('invalid_' . $key, 'The ' . $key . ' parameter is invalid.', 422);
        }
        return $value;
    }

    private function positive_integer($value)
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id !== false && $id > 0 ? $id : null;
    }
}
