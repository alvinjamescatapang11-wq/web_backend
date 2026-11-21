<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';
//hahaS
class Farm_management extends Controller
{
    public function index()
    {
        try {
            $this->call->model('Farm_management_model');
            $farms = $this->Farm_management_model->list_farms(Auth_context::user());
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[farm-list] %s in %s:%d',
                get_class($exception),
                $exception->getFile(),
                $exception->getLine()
            ));
            (new Api_response())->error('farm_list_failed', 'Farm locations are temporarily unavailable.', 500);
        }

        (new Api_response())->success($farms);
    }

    public function options()
    {
        $this->call->model('Farm_management_model');
        (new Api_response())->success($this->Farm_management_model->farm_options(Auth_context::user()));
    }

    public function create()
    {
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide farm details as a JSON object.', 400);
        }

        $farmerId = $this->positive_integer($body['farmerId'] ?? null);
        if (!$farmerId) {
            (new Api_response())->error('invalid_farmer', 'A farmer is required.', 422);
        }
        $data = [
            'farmerId' => $farmerId,
            'farmName' => $this->text($body['farmName'] ?? null, 150, 'farmName', false),
            'locationDescription' => $this->text($body['locationDescription'] ?? null, 500, 'locationDescription', false),
            'ownershipType' => $this->text($body['ownershipType'] ?? null, 80, 'ownershipType', false),
            'latitude' => $this->coordinate($body['latitude'] ?? null, -90, 90, 'latitude'),
            'longitude' => $this->coordinate($body['longitude'] ?? null, -180, 180, 'longitude'),
            'areaHectares' => $this->positive_decimal($body['areaHectares'] ?? null, 'areaHectares'),
        ];

        $this->call->model('Farm_management_model');
        try {
            $id = $this->Farm_management_model->create_farm(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_farm', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function update_location($id)
    {
        $farmId = $this->positive_integer($id);
        if (!$farmId) {
            (new Api_response())->error('invalid_id', 'The farm ID is invalid.', 422);
        }
        $body = $this->request->json();
        if (!is_array($body) || !array_key_exists('latitude', $body) || !array_key_exists('longitude', $body)) {
            (new Api_response())->error('invalid_request', 'Provide both latitude and longitude.', 400);
        }
        $latitude = $this->coordinate($body['latitude'], -90, 90, 'latitude');
        $longitude = $this->coordinate($body['longitude'], -180, 180, 'longitude');
        if (($latitude === null) !== ($longitude === null)) {
            (new Api_response())->error('invalid_coordinates', 'Latitude and longitude must both be provided or both be empty.', 422);
        }

        $this->call->model('Farm_management_model');
        if (!$this->Farm_management_model->update_farm_location(
            Auth_context::user(),
            $farmId,
            $latitude,
            $longitude
        )) {
            (new Api_response())->error('not_found', 'The farm was not found in your authorized scope.', 404);
        }
        (new Api_response())->success([
            'id' => $farmId,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    public function archive($id)
    {
        $farmId = $this->positive_integer($id);
        if (!$farmId) {
            (new Api_response())->error('invalid_id', 'The farm ID is invalid.', 422);
        }
        $this->call->model('Farm_management_model');
        try {
            if (!$this->Farm_management_model->archive_farm(Auth_context::user(), $farmId)) {
                (new Api_response())->error('not_found', 'The farm was not found in your authorized scope.', 404);
            }
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('farm_in_use', $exception->getMessage(), 409);
        }
        (new Api_response())->success(['id' => $farmId, 'archived' => true]);
    }

    private function positive_integer($value)
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id !== false && $id > 0 ? $id : null;
    }

    private function text($value, $max, $field, $required)
    {
        if ($value === null || $value === '') {
            if ($required) {
                (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is required.', 422);
            }
            return null;
        }
        if (!is_string($value) || strlen(trim($value)) > $max) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is too long or invalid.', 422);
        }
        return trim($value) === '' ? null : trim($value);
    }

    private function coordinate($value, $minimum, $maximum, $field)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value < $minimum || (float) $value > $maximum) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is outside its valid range.', 422);
        }
        return (float) $value;
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
