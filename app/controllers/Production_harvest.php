<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class Production_harvest extends Controller
{
    public function references()
    {
        $this->call->model('Production_harvest_model');
        (new Api_response())->success($this->Production_harvest_model->references());
    }

    public function options()
    {
        $this->call->model('Production_harvest_model');
        (new Api_response())->success($this->Production_harvest_model->planting_options(Auth_context::user()));
    }

    public function production()
    {
        $this->call->model('Production_harvest_model');
        (new Api_response())->success($this->Production_harvest_model->productions(Auth_context::user()));
    }

    public function create_production()
    {
        $body = $this->json_body();
        $year = filter_var($body['productionYear'] ?? null, FILTER_VALIDATE_INT);
        $quantity = $this->positive_decimal($body['quantity'] ?? null, 'quantity');
        $unit = $this->text_field($body['unit'] ?? null, 40, 'unit');
        $data = [
            'farmerId' => $this->positive_integer($body['farmerId'] ?? null),
            'farmId' => $this->positive_integer($body['farmId'] ?? null),
            'cropTypeId' => ($body['cropTypeId'] ?? '') === '' ? null : $this->positive_integer($body['cropTypeId']),
            'riceVarietyId' => ($body['riceVarietyId'] ?? '') === '' ? null : $this->positive_integer($body['riceVarietyId']),
            'productionYear' => $year,
            'season' => $this->text_field($body['season'] ?? null, 80, 'season'),
            'quantity' => $quantity,
            'unit' => $unit,
            'notes' => $this->text_field($body['notes'] ?? null, 5000, 'notes'),
        ];
        if (!$data['farmerId'] || !$data['farmId'] || !$data['productionYear'] ||
            $data['productionYear'] < 1900 || $data['productionYear'] > (int) gmdate('Y') ||
            $quantity === null || !$unit
        ) {
            (new Api_response())->error('invalid_production', 'Provide a farmer, farm, valid production year, positive quantity, and unit.', 422);
        }
        if (($body['cropTypeId'] ?? '') !== '' && !$data['cropTypeId']) {
            (new Api_response())->error('invalid_crop_type', 'The crop type ID is invalid.', 422);
        }
        if (($body['riceVarietyId'] ?? '') !== '' && !$data['riceVarietyId']) {
            (new Api_response())->error('invalid_rice_variety', 'The rice variety ID is invalid.', 422);
        }

        $this->call->model('Production_harvest_model');
        try {
            $id = $this->Production_harvest_model->create_production(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_production', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function harvests()
    {
        $this->call->model('Production_harvest_model');
        (new Api_response())->success($this->Production_harvest_model->harvests(Auth_context::user()));
    }

    public function create_harvest()
    {
        $body = $this->json_body();
        $quantity = $this->positive_decimal($body['quantity'] ?? null, 'quantity');
        $data = [
            'farmerId' => $this->positive_integer($body['farmerId'] ?? null),
            'farmId' => $this->positive_integer($body['farmId'] ?? null),
            'cropPlantingId' => ($body['cropPlantingId'] ?? '') === '' ? null : $this->positive_integer($body['cropPlantingId']),
            'ricePlantingId' => ($body['ricePlantingId'] ?? '') === '' ? null : $this->positive_integer($body['ricePlantingId']),
            'productionId' => ($body['productionId'] ?? '') === '' ? null : $this->positive_integer($body['productionId']),
            'harvestedOn' => $this->date_field($body['harvestedOn'] ?? null, 'harvestedOn'),
            'quantity' => $quantity,
            'unit' => $this->text_field($body['unit'] ?? null, 40, 'unit'),
            'qualityNotes' => $this->text_field($body['qualityNotes'] ?? null, 5000, 'qualityNotes'),
        ];
        if (!$data['farmerId'] || !$data['farmId'] || !$data['harvestedOn'] ||
            $data['quantity'] === null || !$data['unit']
        ) {
            (new Api_response())->error('invalid_harvest', 'Provide a farmer, farm, harvest date, positive quantity, and unit.', 422);
        }
        foreach (['cropPlantingId', 'ricePlantingId', 'productionId'] as $field) {
            if (($body[$field] ?? '') !== '' && !$data[$field]) {
                (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is invalid.', 422);
            }
        }

        $this->call->model('Production_harvest_model');
        try {
            $id = $this->Production_harvest_model->create_harvest(Auth_context::user(), $data);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_harvest', $exception->getMessage(), 422);
        }
        (new Api_response())->success(['id' => $id], 201);
    }

    public function report()
    {
        $year = isset($_GET['year']) ? filter_var($_GET['year'], FILTER_VALIDATE_INT) : (int) gmdate('Y');
        if ($year === false || $year < 1900 || $year > (int) gmdate('Y')) {
            (new Api_response())->error('invalid_year', 'Choose a year from 1900 through the current year.', 422);
        }
        $this->call->model('Production_harvest_model');
        (new Api_response())->success([
            'year' => $year,
            'rows' => $this->Production_harvest_model->harvest_report(Auth_context::user(), $year),
        ]);
    }

    public function harvest_analytics()
    {
        $years = isset($_GET['years']) ? filter_var($_GET['years'], FILTER_VALIDATE_INT) : 5;
        if ($years === false || $years < 3 || $years > 10) {
            (new Api_response())->error('invalid_year_range', 'Choose a history range from 3 to 10 years.', 422);
        }
        $this->call->model('Production_harvest_model');
        (new Api_response())->success(
            $this->Production_harvest_model->harvest_trend_forecast(Auth_context::user(), $years)
        );
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

    private function positive_decimal($value, $field)
    {
        if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 1000000000000) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a positive number.', 422);
        }
        return (float) $value;
    }

    private function date_field($value, $field)
    {
        if (!is_string($value)) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a YYYY-MM-DD date.', 422);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value > gmdate('Y-m-d')) {
            (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' must be a valid past or current date.', 422);
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
}
