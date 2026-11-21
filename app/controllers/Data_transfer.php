<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class Data_transfer extends Controller
{
    private $farmerHeaders = [
        'barangayId', 'registryNumber', 'firstName', 'middleName', 'lastName',
        'suffix', 'sex', 'birthDate', 'phone', 'email', 'address', 'primaryLivelihood',
    ];

    public function export($module)
    {
        $this->call->model('Data_transfer_model');
        try {
            $rows = $this->Data_transfer_model->export_rows(Auth_context::user(), $module);
        } catch (LengthException $exception) {
            (new Api_response())->error('export_too_large', $exception->getMessage(), 422);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_export', $exception->getMessage(), 403);
        }
        (new Api_response())->success(['module' => $module, 'rows' => $rows]);
    }

    public function history()
    {
        $this->call->model('Data_transfer_model');
        (new Api_response())->success($this->Data_transfer_model->import_history(Auth_context::user()));
    }

    public function import_farmers()
    {
        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($length > 2200000) {
            (new Api_response())->error('request_too_large', 'CSV import must be smaller than 2 MB.', 413);
        }
        $body = $this->request->json();
        if (!is_array($body) || !isset($body['csv']) || !is_string($body['csv'])) {
            (new Api_response())->error('invalid_request', 'Provide CSV text and a filename.', 400);
        }
        if ($body['csv'] === '' || strlen($body['csv']) > 2000000) {
            (new Api_response())->error('invalid_csv', 'CSV must contain data and be smaller than 2 MB.', 422);
        }
        $filename = isset($body['filename']) && is_string($body['filename'])
            ? basename(str_replace('\\', '/', trim($body['filename'])))
            : 'farmer-import.csv';
        $filename = substr($filename ?: 'farmer-import.csv', 0, 255);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare CSV import.');
        }
        fwrite($stream, $body['csv']);
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '\\');
        if (!$headers) {
            fclose($stream);
            (new Api_response())->error('invalid_csv', 'CSV must include the template header row.', 422);
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(static function ($header) {
            return trim((string) $header);
        }, $headers);
        if (count($headers) !== count($this->farmerHeaders) || count(array_unique($headers)) !== count($headers)
            || array_diff($this->farmerHeaders, $headers) || array_diff($headers, $this->farmerHeaders)) {
            fclose($stream);
            (new Api_response())->error(
                'invalid_csv_headers',
                'Use the downloaded farmer CSV template without changing its column names.',
                422
            );
        }

        $indexes = array_flip($headers);
        $rows = [];
        $lineNumber = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $lineNumber++;
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }
            if (count($rows) >= 500) {
                fclose($stream);
                (new Api_response())->error('too_many_rows', 'A single import is limited to 500 records.', 422);
            }
            if (count($values) !== count($headers)) {
                $rows[] = ['line' => $lineNumber, 'values' => null];
                continue;
            }
            $mapped = [];
            foreach ($this->farmerHeaders as $header) {
                $mapped[$header] = trim((string) ($values[$indexes[$header]] ?? ''));
            }
            $rows[] = ['line' => $lineNumber, 'values' => $mapped];
        }
        fclose($stream);

        if (!$rows) {
            (new Api_response())->error('empty_csv', 'The CSV file does not contain any farmer rows.', 422);
        }

        $user = Auth_context::user();
        $this->call->model('Farmer_management_model');
        $this->call->model('Data_transfer_model');
        $errors = [];
        $imported = 0;
        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];
            if ($values === null) {
                $errors[] = ['line' => $line, 'message' => 'The row does not have the same number of columns as the header.'];
                continue;
            }
            $validated = $this->validate_farmer_row($values, $user);
            if (isset($validated['error'])) {
                $errors[] = ['line' => $line, 'message' => $validated['error']];
                continue;
            }
            try {
                $this->Farmer_management_model->create_record($user, $validated['data']);
                $imported++;
            } catch (InvalidArgumentException $exception) {
                $errors[] = ['line' => $line, 'message' => $exception->getMessage()];
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
                $errors[] = ['line' => $line, 'message' => 'A registry number or other unique field already exists.'];
            }
        }

        $logId = $this->Data_transfer_model->record_import($user, $filename, count($rows), $imported, $errors);
        (new Api_response())->success([
            'importId' => $logId,
            'totalRows' => count($rows),
            'importedRows' => $imported,
            'errorCount' => count($errors),
            'errors' => $errors,
        ]);
    }

    public function import_records($module)
    {
        $schemas = [
            'farms' => ['farmerRegistryNumber', 'farmName', 'locationDescription', 'ownershipType', 'latitude', 'longitude', 'areaHectares'],
            'harvests' => ['farmerRegistryNumber', 'farmName', 'harvestedOn', 'quantity', 'unit', 'qualityNotes', 'cropPlantingId', 'ricePlantingId', 'productionId'],
            'livestock' => ['farmerRegistryNumber', 'farmName', 'category', 'quantity', 'acquiredOn', 'notes'],
        ];
        if (!isset($schemas[$module])) {
            (new Api_response())->error('invalid_import_module', 'Choose farms, harvests, or livestock for this import.', 422);
        }

        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($length > 2200000) {
            (new Api_response())->error('request_too_large', 'Import must be smaller than 2 MB.', 413);
        }
        $body = $this->request->json();
        if (!is_array($body) || !isset($body['csv']) || !is_string($body['csv'])) {
            (new Api_response())->error('invalid_request', 'Provide CSV text and a filename.', 400);
        }
        if ($body['csv'] === '' || strlen($body['csv']) > 2000000) {
            (new Api_response())->error('invalid_csv', 'Import data must contain rows and be smaller than 2 MB.', 422);
        }

        $filename = isset($body['filename']) && is_string($body['filename'])
            ? basename(str_replace('\\', '/', trim($body['filename'])))
            : $module . '-import.csv';
        $filename = substr($filename ?: $module . '-import.csv', 0, 255);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Unable to prepare import data.');
        }
        fwrite($stream, $body['csv']);
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '\\');
        if (!$headers) {
            fclose($stream);
            (new Api_response())->error('invalid_csv', 'Import data must include the template header row.', 422);
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(static function ($header) {
            return trim((string) $header);
        }, $headers);
        if (
            count($headers) !== count($schemas[$module]) ||
            count(array_unique($headers)) !== count($headers) ||
            array_diff($schemas[$module], $headers) ||
            array_diff($headers, $schemas[$module])
        ) {
            fclose($stream);
            (new Api_response())->error('invalid_csv_headers', 'Use the downloaded module template without changing its column names.', 422);
        }

        $indexes = array_flip($headers);
        $rows = [];
        $lineNumber = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $lineNumber++;
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) continue;
            if (count($rows) >= 500) {
                fclose($stream);
                (new Api_response())->error('too_many_rows', 'A single import is limited to 500 records.', 422);
            }
            if (count($values) !== count($headers)) {
                $rows[] = ['line' => $lineNumber, 'values' => null];
                continue;
            }
            $mapped = [];
            foreach ($schemas[$module] as $header) {
                $mapped[$header] = trim((string) ($values[$indexes[$header]] ?? ''));
            }
            $rows[] = ['line' => $lineNumber, 'values' => $mapped];
        }
        fclose($stream);
        if (!$rows) {
            (new Api_response())->error('empty_csv', 'The import file does not contain any data rows.', 422);
        }

        $user = Auth_context::user();
        $this->call->model('Data_transfer_model');
        $this->call->model('Farm_management_model');
        $this->call->model('Production_harvest_model');
        $this->call->model('Crop_livestock_model');
        $imported = 0;
        $errors = [];
        foreach ($rows as $row) {
            try {
                if ($row['values'] === null) {
                    throw new InvalidArgumentException('The row does not have the same number of columns as the header.');
                }
                $values = $row['values'];
                $registry = $this->import_text($values['farmerRegistryNumber'], 80, 'Farmer registry number', true);
                $farmers = $this->Data_transfer_model->find_farmer_for_import($user, $registry);
                if (count($farmers) !== 1) {
                    throw new InvalidArgumentException('Farmer registry number must identify one active farmer in your authorized barangay scope.');
                }
                $farmer = $farmers[0];

                if ($module === 'farms') {
                    $latitude = $this->import_decimal($values['latitude'], -90, 90, 'Latitude');
                    $longitude = $this->import_decimal($values['longitude'], -180, 180, 'Longitude');
                    if (($latitude === null) !== ($longitude === null)) {
                        throw new InvalidArgumentException('Latitude and longitude must both be provided or both be empty.');
                    }
                    $area = $this->import_decimal($values['areaHectares'], 0.0000001, 1000000, 'Area');
                    $this->Farm_management_model->create_farm($user, [
                        'farmerId' => (int) $farmer['id'],
                        'farmName' => $this->import_text($values['farmName'], 150, 'Farm name', false),
                        'locationDescription' => $this->import_text($values['locationDescription'], 500, 'Location description', false),
                        'ownershipType' => $this->import_text($values['ownershipType'], 80, 'Ownership type', false),
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'areaHectares' => $area,
                    ]);
                } elseif ($module === 'harvests') {
                    $farmName = $this->import_text($values['farmName'], 150, 'Farm name', true);
                    $farms = $this->Data_transfer_model->find_farm_for_import((int) $farmer['id'], $farmName);
                    if (count($farms) !== 1) {
                        throw new InvalidArgumentException('Farm name must identify one active farm belonging to the selected farmer.');
                    }
                    $this->Production_harvest_model->create_harvest($user, [
                        'farmerId' => (int) $farmer['id'],
                        'farmId' => (int) $farms[0]['id'],
                        'cropPlantingId' => $this->import_optional_id($values['cropPlantingId'], 'Crop planting ID'),
                        'ricePlantingId' => $this->import_optional_id($values['ricePlantingId'], 'Rice planting ID'),
                        'productionId' => $this->import_optional_id($values['productionId'], 'Production ID'),
                        'harvestedOn' => $this->import_date($values['harvestedOn'], 'Harvest date'),
                        'quantity' => $this->import_decimal($values['quantity'], 0.0000001, 1000000000000, 'Quantity', true),
                        'unit' => $this->import_text($values['unit'], 40, 'Unit', true),
                        'qualityNotes' => $this->import_text($values['qualityNotes'], 5000, 'Quality notes', false),
                    ]);
                } else {
                    $farmName = $this->import_text($values['farmName'], 150, 'Farm name', false);
                    $farmId = null;
                    if ($farmName !== null) {
                        $farms = $this->Data_transfer_model->find_farm_for_import((int) $farmer['id'], $farmName);
                        if (count($farms) !== 1) {
                            throw new InvalidArgumentException('Farm name must identify one active farm belonging to the selected farmer.');
                        }
                        $farmId = (int) $farms[0]['id'];
                    }
                    $categories = $this->Data_transfer_model->find_livestock_category_for_import(
                        $this->import_text($values['category'], 120, 'Livestock category', true)
                    );
                    if (count($categories) !== 1) {
                        throw new InvalidArgumentException('Livestock category must match one active, verified category.');
                    }
                    $quantity = filter_var($values['quantity'], FILTER_VALIDATE_INT);
                    if ($quantity === false || $quantity < 1 || $quantity > 1000000000) {
                        throw new InvalidArgumentException('Quantity must be a positive whole number.');
                    }
                    $this->Crop_livestock_model->create_livestock($user, [
                        'farmerId' => (int) $farmer['id'],
                        'farmId' => $farmId,
                        'categoryId' => (int) $categories[0]['id'],
                        'quantity' => $quantity,
                        'acquiredOn' => $this->import_date($values['acquiredOn'], 'Acquired date'),
                        'notes' => $this->import_text($values['notes'], 5000, 'Notes', false),
                    ]);
                }
                $imported++;
            } catch (InvalidArgumentException $exception) {
                $errors[] = ['line' => $row['line'], 'message' => $exception->getMessage()];
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() !== '23000') throw $exception;
                $errors[] = ['line' => $row['line'], 'message' => 'The row conflicts with an existing record or reference.'];
            }
        }

        $logId = $this->Data_transfer_model->record_import($user, $filename, count($rows), $imported, $errors, $module);
        (new Api_response())->success([
            'importId' => $logId,
            'module' => $module,
            'totalRows' => count($rows),
            'importedRows' => $imported,
            'errorCount' => count($errors),
            'errors' => $errors,
        ]);
    }

    private function validate_farmer_row(array $row, array $user)
    {
        $limits = [
            'registryNumber' => 80, 'firstName' => 100, 'middleName' => 100,
            'lastName' => 100, 'suffix' => 30, 'sex' => 30, 'phone' => 40,
            'email' => 255, 'address' => 500, 'primaryLivelihood' => 150,
        ];
        foreach (['firstName', 'lastName'] as $required) {
            if ($row[$required] === '') {
                return ['error' => ucfirst($required) . ' is required.'];
            }
        }
        foreach ($limits as $field => $maximum) {
            if (strlen($row[$field]) > $maximum) {
                return ['error' => ucfirst($field) . ' exceeds ' . $maximum . ' characters.'];
            }
        }
        $email = strtolower($row['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Email address is invalid.'];
        }
        $birthDate = $row['birthDate'];
        if ($birthDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if (!$date || $date->format('Y-m-d') !== $birthDate || $birthDate > gmdate('Y-m-d')) {
                return ['error' => 'Birth date must be a valid YYYY-MM-DD date that is not in the future.'];
            }
        }
        $barangayId = filter_var($row['barangayId'], FILTER_VALIDATE_INT);
        if ($user['role_slug'] === 'barangay_personnel') {
            $assignedBarangayId = (int) $user['barangay_id'];
            if ($row['barangayId'] !== '' && ($barangayId === false || $barangayId !== $assignedBarangayId)) {
                return ['error' => 'Barangay ID must match your assigned barangay.'];
            }
            $barangayId = $assignedBarangayId;
        } elseif ($barangayId === false || $barangayId < 1) {
            return ['error' => 'A valid Barangay ID is required.'];
        }
        if (!$this->Data_transfer_model->has_verified_active_barangay($barangayId)) {
            return ['error' => 'Barangay ID must refer to an active, verified CAO barangay.'];
        }

        return [
            'data' => [
                'barangayId' => (int) $barangayId,
                'registryNumber' => $row['registryNumber'] ?: null,
                'firstName' => $row['firstName'],
                'middleName' => $row['middleName'] ?: null,
                'lastName' => $row['lastName'],
                'suffix' => $row['suffix'] ?: null,
                'sex' => $row['sex'] ?: null,
                'birthDate' => $birthDate ?: null,
                'phone' => $row['phone'] ?: null,
                'email' => $email ?: null,
                'address' => $row['address'] ?: null,
                'primaryLivelihood' => $row['primaryLivelihood'] ?: null,
            ],
        ];
    }

    private function import_text($value, $maximum, $label, $required)
    {
        $value = trim((string) $value);
        if ($required && $value === '') {
            throw new InvalidArgumentException($label . ' is required.');
        }
        if (strlen($value) > $maximum) {
            throw new InvalidArgumentException($label . ' exceeds ' . $maximum . ' characters.');
        }
        return $value === '' ? null : $value;
    }

    private function import_decimal($value, $minimum, $maximum, $label, $required = false)
    {
        $value = trim((string) $value);
        if ($value === '') {
            if ($required) throw new InvalidArgumentException($label . ' is required.');
            return null;
        }
        if (!is_numeric($value) || (float) $value < $minimum || (float) $value > $maximum) {
            throw new InvalidArgumentException($label . ' is outside its valid range.');
        }
        return (float) $value;
    }

    private function import_date($value, $label)
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value > gmdate('Y-m-d')) {
            throw new InvalidArgumentException($label . ' must be a valid YYYY-MM-DD date that is not in the future.');
        }
        return $value;
    }

    private function import_optional_id($value, $label)
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new InvalidArgumentException($label . ' must be a positive whole number.');
        }
        return $id;
    }
}
