<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class User_management extends Controller
{
    public function options()
    {
        lava_instance()->call->database();
        $db = lava_instance()->db;
        $barangays = $db->raw(
            'SELECT id, name FROM barangays
             WHERE is_active = 1 AND deleted_at IS NULL
             ORDER BY name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $farmers = $db->raw(
            'SELECT id, first_name, middle_name, last_name, suffix,
                    barangay_id, is_sample
             FROM farmers
             WHERE user_id IS NULL AND is_active = 1 AND deleted_at IS NULL
             ORDER BY last_name ASC, first_name ASC
             LIMIT 500'
        )->fetchAll(PDO::FETCH_ASSOC);

        (new Api_response())->success([
            'roles' => [
                ['slug' => 'cao_staff', 'name' => 'CAO Staff'],
                ['slug' => 'barangay_personnel', 'name' => 'Barangay Personnel'],
                ['slug' => 'farmer', 'name' => 'Farmer'],
            ],
            'barangays' => $barangays,
            'unlinkedFarmers' => $farmers,
        ]);
    }

    public function index()
    {
        $query = trim((string) ($_GET['q'] ?? ''));
        $role = trim((string) ($_GET['role'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        if (strlen($query) > 100) {
            (new Api_response())->error('invalid_query', 'Search text must be 100 characters or fewer.', 422);
        }
        if ($role !== '' && !in_array($role, ['cao_admin', 'cao_staff', 'barangay_personnel', 'farmer'], true)) {
            (new Api_response())->error('invalid_role', 'The role filter is invalid.', 422);
        }
        if ($status !== '' && !in_array($status, ['active', 'inactive'], true)) {
            (new Api_response())->error('invalid_status', 'The status filter is invalid.', 422);
        }

        $barangayId = null;
        if (isset($_GET['barangayId']) && $_GET['barangayId'] !== '') {
            $barangayId = filter_var($_GET['barangayId'], FILTER_VALIDATE_INT);
            if ($barangayId === false || $barangayId < 1) {
                (new Api_response())->error('invalid_barangay', 'The barangay filter is invalid.', 422);
            }
        }

        $page = $this->query_integer('page', 1, 1, 100000);
        $perPage = $this->query_integer('perPage', 20, 1, 100);
        $this->call->model('User_management_model');
        (new Api_response())->success(
            $this->User_management_model->list_accounts($query, $role, $status, $barangayId, $page, $perPage)
        );
    }

    public function create()
    {
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide a JSON object with account details.', 400);
        }

        $fullName = $this->string_field($body, 'fullName', 150, true);
        $username = $this->string_field($body, 'username', 100, true);
        $email = strtolower($this->string_field($body, 'email', 255, true));
        $password = $this->string_field($body, 'password', 1024, true);
        $role = $this->string_field($body, 'role', 40, true);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            (new Api_response())->error('invalid_email', 'Provide a valid email address.', 422);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            (new Api_response())->error('invalid_username', 'Username must be 3–100 letters, numbers, dots, underscores, or hyphens.', 422);
        }
        if (strlen($password) < 12) {
            (new Api_response())->error('weak_password', 'Password must be at least 12 characters.', 422);
        }
        if (!in_array($role, ['cao_staff', 'barangay_personnel', 'farmer'], true)) {
            (new Api_response())->error('invalid_role', 'Only CAO Staff, Barangay Personnel, or Farmer accounts can be created here.', 422);
        }

        $barangayId = $body['barangayId'] ?? null;
        $farmerId = $body['farmerId'] ?? null;
        if ($role === 'barangay_personnel') {
            $barangayId = $this->positive_integer($barangayId);
            if (!$barangayId) {
                (new Api_response())->error('invalid_barangay', 'Select a barangay for this account.', 422);
            }
        } elseif ($role === 'farmer') {
            $farmerId = $this->positive_integer($farmerId);
            if (!$farmerId) {
                (new Api_response())->error('invalid_farmer', 'Select an existing, unlinked farmer record.', 422);
            }
        } else {
            $barangayId = null;
        }

        $this->call->model('User_management_model');
        try {
            $id = $this->User_management_model->create_account(
                Auth_context::user(),
                compact('fullName', 'username', 'email', 'password', 'role', 'barangayId', 'farmerId')
            );
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_account', $exception->getMessage(), 422);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                (new Api_response())->error('duplicate_account', 'That username, email, or farmer link is already in use.', 409);
            }
            throw $exception;
        }

        (new Api_response())->success(['id' => $id], 201);
    }

    public function update($id)
    {
        $accountId = $this->positive_integer($id);
        if (!$accountId) {
            (new Api_response())->error('invalid_id', 'The account ID is invalid.', 422);
        }
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide account status and/or a new password.', 400);
        }

        $changes = [];
        if (array_key_exists('isActive', $body)) {
            if (!is_bool($body['isActive'])) {
                (new Api_response())->error('invalid_status', 'isActive must be true or false.', 422);
            }
            $changes['isActive'] = $body['isActive'];
        }
        if (array_key_exists('password', $body)) {
            if (!is_string($body['password']) || strlen($body['password']) < 12 || strlen($body['password']) > 1024) {
                (new Api_response())->error('weak_password', 'Password must contain 12–1024 characters.', 422);
            }
            $changes['password'] = $body['password'];
        }
        if (!$changes) {
            (new Api_response())->error('invalid_request', 'Provide account status and/or a new password.', 422);
        }

        $this->call->model('User_management_model');
        try {
            $updated = $this->User_management_model->update_account(
                Auth_context::user(),
                $accountId,
                $changes
            );
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_account_update', $exception->getMessage(), 422);
        }
        if (!$updated) {
            (new Api_response())->error('not_found', 'The account was not found or cannot be managed.', 404);
        }

        (new Api_response())->success(['id' => $accountId, 'updated' => true]);
    }

    private function string_field(array $body, $key, $maximum, $required)
    {
        if (!isset($body[$key]) || !is_string($body[$key])) {
            if ($required) {
                (new Api_response())->error('invalid_' . $key, ucfirst($key) . ' is required.', 422);
            }
            return '';
        }
        $value = trim($body[$key]);
        if (($required && $value === '') || strlen($value) > $maximum) {
            (new Api_response())->error('invalid_' . $key, ucfirst($key) . ' is missing or too long.', 422);
        }
        return $value;
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
