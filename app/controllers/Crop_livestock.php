<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class Crop_livestock extends Controller
{
    public function references()
    {
        $this->call->model('Crop_livestock_model');
        (new Api_response())->success($this->Crop_livestock_model->reference_data());
    }

    public function crops()
    {
        $this->call->model('Crop_livestock_model');
        (new Api_response())->success($this->Crop_livestock_model->crop_plantings(Auth_context::user()));
    }

    public function create_crop()
    {
        $body = $this->json_body();
        $data = [
            'farmerId' => $this->positive_integer($body['farmerId'] ?? null),
            'farmId' => $this->positive_integer($body['farmId'] ?? null),
            'cropTypeId' => $this->positive_integer($body['cropTypeId'] ?? null),
            'plantingDate' => $this->date_field($body['plantingDate'] ?? null, 'plantingDate', false),
            'expectedHarvestDate' => $this->date_field($body['expectedHarvestDate'] ?? null, 'expectedHarvestDate', true),
            'areaHectares' => $this->positive_decimal($body['areaHectares'] ?? null, 'areaHectares'),
            'season' => $this->text_field($body['season'] ?? null, 80, 'season'),
            'notes' => $this->text_field($body['notes'] ?? null, 5000, 'notes'),
        ];
        if (!$data['farmerId'] || !$data['farmId'] || !$data['cropTypeId'] || !$data['plantingDate']) {
            (new Api_response())->error('invalid_crop_planting', 'Farmer, farm, crop type, and planting date are required.', 422);
        }
        if ($data['expectedHarvestDate'] && $data['expectedHarvestDate'] < $data['plantingDate']) {
            (new Api_response())->error('invalid_harvest_date', 'Expected harvest date cannot be before planting date.', 422);
        }
        $this->call->model('Crop_livestock_model');
        try {
            $id = $this->Crop_livestock_model->create_crop_planting(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_crop_planting', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function livestock()
    {
        $this->call->model('Crop_livestock_model');
        (new Api_response())->success($this->Crop_livestock_model->livestock_records(Auth_context::user()));
    }

    public function create_livestock()
    {
        $body = $this->json_body();
        $quantity = filter_var($body['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$quantity || $quantity < 1 || $quantity > 1000000000) {
            (new Api_response())->error('invalid_quantity', 'Quantity must be a positive whole number.', 422);
        }
        $data = [
            'farmerId' => $this->positive_integer($body['farmerId'] ?? null),
            'farmId' => ($body['farmId'] ?? '') === '' ? null : $this->positive_integer($body['farmId']),
            'categoryId' => $this->positive_integer($body['categoryId'] ?? null),
            'quantity' => $quantity,
            'acquiredOn' => $this->date_field($body['acquiredOn'] ?? null, 'acquiredOn', true),
            'notes' => $this->text_field($body['notes'] ?? null, 5000, 'notes'),
        ];
        if (!$data['farmerId'] || !$data['categoryId'] || (($body['farmId'] ?? '') !== '' && !$data['farmId'])) {
            (new Api_response())->error('invalid_livestock', 'A farmer, livestock category, and valid optional farm are required.', 422);
        }
        $this->call->model('Crop_livestock_model');
        try {
            $id = $this->Crop_livestock_model->create_livestock(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_livestock', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function health()
    {
        $this->call->model('Crop_livestock_model');
        (new Api_response())->success($this->Crop_livestock_model->health_records(Auth_context::user()));
    }

    public function create_health()
    {
        $body = $this->json_body();
        $data = [
            'livestockId' => $this->positive_integer($body['livestockId'] ?? null),
            'recordType' => $this->text_field($body['recordType'] ?? null, 80, 'recordType'),
            'description' => $this->text_field($body['description'] ?? null, 5000, 'description'),
            'administeredOn' => $this->date_field($body['administeredOn'] ?? null, 'administeredOn', true),
            'nextDueOn' => $this->date_field($body['nextDueOn'] ?? null, 'nextDueOn', true),
            'provider' => $this->text_field($body['provider'] ?? null, 150, 'provider'),
        ];
        if (!$data['livestockId'] || !$data['recordType'] || !$data['description']) {
            (new Api_response())->error('invalid_health_record', 'Livestock, record type, and description are required.', 422);
        }
        if ($data['administeredOn'] && $data['nextDueOn'] && $data['nextDueOn'] < $data['administeredOn']) {
            (new Api_response())->error('invalid_due_date', 'Next due date cannot be before the administration date.', 422);
        }
        $this->call->model('Crop_livestock_model');
        try {
            $id = $this->Crop_livestock_model->create_health_record(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_health_record', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function archive_crop($id)
    {
        $this->archive_record('crop_plantings', $id);
    }

    public function archive_livestock($id)
    {
        $this->archive_record('livestock', $id);
    }

    private function archive_record($table, $id)
    {
        $recordId = $this->positive_integer($id);
        if (!$recordId) {
            (new Api_response())->error('invalid_record', 'The record or record type is invalid.', 422);
        }
        $this->call->model('Crop_livestock_model');
        if (!$this->Crop_livestock_model->archive(Auth_context::user(), $table, $recordId)) {
            (new Api_response())->error('not_found', 'The record was not found in your authorized scope.', 404);
        }
        (new Api_response())->success(['id' => $recordId, 'archived' => true]);
    }

    private function json_body()
    {
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide a JSON object.', 400);
        }
        return $body;
    }

    private function positive_integer($value)
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        return $number !== false && $number > 0 ? $number : null;
    }

    private function date_field($value, $field, $optional)
    {
        if (($value === null || $value === '') && $optional) {
            return null;
        }
        if (!is_string($value)) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a YYYY-MM-DD date.', 422);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a valid YYYY-MM-DD date.', 422);
        }
        return $value;
    }

    private function text_field($value, $maximum, $field)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen(trim($value)) > $maximum) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is too long or invalid.', 422);
        }
        return trim($value) === '' ? null : trim($value);
    }

    private function positive_decimal($value, $field)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 1000000) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a positive number.', 422);
        }
        return (float) $value;
    }
}
