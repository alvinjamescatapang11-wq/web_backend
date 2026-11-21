<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class Agriculture extends Controller
{
    public function barangays()
    {
        $user = Auth_context::user();
        lava_instance()->call->database();
        $db = lava_instance()->db;
        if ($user['role_slug'] === 'barangay_personnel') {
            $barangays = $db->raw(
                'SELECT id, name, is_sample FROM barangays
                 WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
                 ORDER BY name ASC',
                [(int) $user['barangay_id']]
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $barangays = $db->raw(
                'SELECT id, name, is_sample FROM barangays
                 WHERE is_active = 1 AND deleted_at IS NULL
                 ORDER BY name ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        (new Api_response())->success($barangays);
    }

    public function farmers()
    {
        $page = $this->query_integer('page', 1, 1, 100000);
        $perPage = $this->query_integer('perPage', 20, 1, 100);
        $query = trim((string) ($_GET['q'] ?? ''));
        if (strlen($query) > 100) {
            (new Api_response())->error('invalid_query', 'Search text must be 100 characters or fewer.', 422);
        }

        $this->call->model('Farmer_management_model');
        (new Api_response())->success(
            $this->Farmer_management_model->list_records(Auth_context::user(), $query, $page, $perPage)
        );
    }

    public function farmer($id)
    {
        $farmerId = $this->path_id($id);
        $this->call->model('Farmer_management_model');
        $farmer = $this->Farmer_management_model->find_record(Auth_context::user(), $farmerId);
        if (!$farmer) {
            (new Api_response())->error('not_found', 'The farmer record was not found.', 404);
        }

        (new Api_response())->success($farmer);
    }

    public function create_farmer()
    {
        $user = Auth_context::user();
        $data = $this->validated_farmer_input();
        if ($user['role_slug'] !== 'barangay_personnel') {
            $data['barangayId'] = $this->positive_integer($data['barangayId'] ?? null);
            if (!$data['barangayId']) {
                (new Api_response())->error('invalid_barangay', 'Select a valid barangay for this farmer.', 422);
            }
        } else {
            $data['barangayId'] = (int) $user['barangay_id'];
        }

        $this->call->model('Farmer_management_model');
        try {
            $id = $this->Farmer_management_model->create_record($user, $data);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                (new Api_response())->error('duplicate_farmer', 'A farmer with that registry number already exists.', 409);
            }
            throw $exception;
        }

        (new Api_response())->success(['id' => $id], 201);
    }

    public function update_farmer($id)
    {
        $user = Auth_context::user();
        $farmerId = $this->path_id($id);
        $data = $this->validated_farmer_input();

        $this->call->model('Farmer_management_model');
        try {
            $updated = $this->Farmer_management_model->update_record($user, $farmerId, $data);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                (new Api_response())->error('duplicate_farmer', 'A farmer with that registry number already exists.', 409);
            }
            throw $exception;
        }
        if (!$updated) {
            (new Api_response())->error('not_found', 'The farmer record was not found.', 404);
        }

        (new Api_response())->success(['id' => $farmerId]);
    }

    public function archive_farmer($id)
    {
        $farmerId = $this->path_id($id);
        $this->call->model('Farmer_management_model');
        if (!$this->Farmer_management_model->archive_record(Auth_context::user(), $farmerId)) {
            (new Api_response())->error('not_found', 'The farmer record was not found.', 404);
        }

        (new Api_response())->success(['id' => $farmerId, 'archived' => true]);
    }

    private function validated_farmer_input()
    {
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide a JSON object with farmer details.', 400);
        }

        $fields = [
            'firstName' => 100,
            'lastName' => 100,
            'middleName' => 100,
            'suffix' => 30,
            'sex' => 30,
            'phone' => 40,
            'address' => 500,
            'primaryLivelihood' => 150,
            'registryNumber' => 80,
        ];
        $data = [];
        foreach ($fields as $field => $maxLength) {
            $value = isset($body[$field]) && is_string($body[$field]) ? trim($body[$field]) : '';
            if (in_array($field, ['firstName', 'lastName'], true) && $value === '') {
                (new Api_response())->error('invalid_' . $field, 'First and last names are required.', 422);
            }
            if (strlen($value) > $maxLength) {
                (new Api_response())->error('invalid_' . $field, ucfirst($field) . ' is too long.', 422);
            }
            $data[$field] = $value === '' ? null : $value;
        }
        $data['firstName'] = trim($body['firstName']);
        $data['lastName'] = trim($body['lastName']);

        $email = isset($body['email']) && is_string($body['email']) ? strtolower(trim($body['email'])) : '';
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255)) {
            (new Api_response())->error('invalid_email', 'Provide a valid email address.', 422);
        }
        $data['email'] = $email === '' ? null : $email;

        $birthDate = isset($body['birthDate']) && is_string($body['birthDate']) ? trim($body['birthDate']) : '';
        if ($birthDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if (!$date || $date->format('Y-m-d') !== $birthDate || $birthDate > gmdate('Y-m-d')) {
                (new Api_response())->error('invalid_birth_date', 'Provide a valid birth date that is not in the future.', 422);
            }
        }
        $data['birthDate'] = $birthDate === '' ? null : $birthDate;
        $data['barangayId'] = $body['barangayId'] ?? null;

        return $data;
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

    private function path_id($value)
    {
        $id = $this->positive_integer($value);
        if (!$id) {
            (new Api_response())->error('invalid_id', 'The record ID is invalid.', 422);
        }
        return $id;
    }

    private function positive_integer($value)
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id !== false && $id > 0 ? $id : null;
    }
}
