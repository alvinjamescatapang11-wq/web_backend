<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Api_response.php';
require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Audit_logger.php';

class Auth extends Controller
{
    public function login()
    {
        $this->require_database_configuration();

        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > 16384) {
            (new Api_response())->error('request_too_large', 'The request body is too large.', 413);
        }

        $body = $this->request->json();
        $email = is_array($body) && isset($body['email']) && is_string($body['email'])
            ? strtolower(trim($body['email']))
            : '';
        $password = is_array($body) && isset($body['password']) && is_string($body['password'])
            ? $body['password']
            : '';

        $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : 'unknown';
        $identityHash = hash('sha256', $email);

        $this->call->model('Auth_model');
        if ($this->Auth_model->failed_attempts_in_window($identityHash, $ipAddress) >= 10) {
            (new Api_response())->error(
                'too_many_attempts',
                'Too many sign-in attempts. Please wait 15 minutes before trying again.',
                429
            );
        }

        $user = filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 255 && strlen($password) <= 1024
            ? $this->Auth_model->find_by_email($email)
            : false;

        if (!$user || empty($user['is_active']) || !password_verify($password, $user['password'])) {
            $this->Auth_model->record_login_attempt($identityHash, $ipAddress, false);
            (new Audit_logger())->record(
                null,
                null,
                null,
                'login_failed',
                'authentication',
                null,
                null,
                ['identity_hash' => $identityHash]
            );
            (new Api_response())->error('invalid_credentials', 'Email or password is incorrect.', 401);
        }

        if (empty($user['role_id']) || empty($user['role_slug'])) {
            (new Api_response())->error(
                'role_assignment_required',
                'This account does not have a configured system role.',
                403
            );
        }

        if ($user['role_slug'] === 'barangay_personnel' && empty($user['barangay_id'])) {
            (new Api_response())->error(
                'barangay_assignment_required',
                'This account must be assigned to a barangay before it can sign in.',
                403
            );
        }

        if ($user['role_slug'] === 'farmer' && empty($user['farmer_id'])) {
            (new Api_response())->error(
                'farmer_profile_required',
                'This account must be linked to an active farmer profile before it can sign in.',
                403
            );
        }

        $this->call->model('Auth_model');
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + 43200;
        $tokenHash = hash('sha256', $token);
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;

        $this->Auth_model->issue_session(
            (int) $user['id'],
            $tokenHash,
            gmdate('Y-m-d H:i:s', $expiresAt),
            $ipAddress,
            $userAgent
        );
        $this->Auth_model->record_login_attempt($identityHash, $ipAddress, true);

        $audit = new Audit_logger();
        $audit->record(
            (int) $user['id'],
            $user['role_slug'],
            isset($user['barangay_id']) ? (int) $user['barangay_id'] : null,
            'login',
            'authentication'
        );

        (new Api_response())->success([
            'accessToken' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => gmdate(DATE_ATOM, $expiresAt),
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'name' => $user['full_name'] ?: $user['username'],
                'role' => $user['role_slug'],
                'barangayId' => $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
                'farmerId' => $user['farmer_id'] === null ? null : (int) $user['farmer_id'],
            ],
        ]);
    }

    public function google()
    {
        $this->require_database_configuration();
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > 16384) {
            (new Api_response())->error('request_too_large', 'The request body is too large.', 413);
        }
        $body = $this->request->json();
        $idToken = is_array($body) && isset($body['credential']) && is_string($body['credential'])
            ? trim($body['credential'])
            : '';
        if ($idToken === '' || strlen($idToken) > 8192) {
            (new Api_response())->error('invalid_google_token', 'A valid Google sign-in credential is required.', 401);
        }

        $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : 'unknown';
        $ipIdentityHash = hash('sha256', 'google:' . $ipAddress);
        $this->call->model('Auth_model');
        if ($this->Auth_model->failed_attempts_in_window($ipIdentityHash, $ipAddress) >= 10) {
            (new Api_response())->error('too_many_attempts', 'Too many sign-in attempts. Please wait 15 minutes before trying again.', 429);
        }

        $claims = $this->verify_google_credential($idToken);
        if (!$claims) {
            $this->Auth_model->record_login_attempt($ipIdentityHash, $ipAddress, false);
            (new Audit_logger())->record(null, null, null, 'login_failed', 'google_authentication');
            (new Api_response())->error('invalid_google_token', 'Google sign-in could not be verified.', 401);
        }

        $email = strtolower(trim($claims['email']));
        $identityHash = hash('sha256', $email);
        if ($this->Auth_model->failed_attempts_in_window($identityHash, $ipAddress) >= 10) {
            (new Api_response())->error('too_many_attempts', 'Too many sign-in attempts. Please wait 15 minutes before trying again.', 429);
        }

        $user = $this->Auth_model->find_by_email($email);
        if (
            !$user || empty($user['is_active']) || $user['role_slug'] !== 'farmer' ||
            empty($user['farmer_id']) || empty($user['role_id'])
        ) {
            $this->Auth_model->record_login_attempt($identityHash, $ipAddress, false);
            (new Audit_logger())->record(null, null, null, 'login_failed', 'google_authentication');
            (new Api_response())->error(
                'farmer_account_required',
                'Google sign-in is available only for an active, previously registered farmer account with the same verified email.',
                403
            );
        }

        try {
            if (!$this->Auth_model->attach_google_subject((int) $user['id'], $claims['sub'])) {
                $this->Auth_model->record_login_attempt($identityHash, $ipAddress, false);
                (new Api_response())->error('google_account_mismatch', 'This Google account is already linked to another account.', 409);
            }
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                (new Api_response())->error('google_account_mismatch', 'This Google account is already linked to another account.', 409);
            }
            throw $exception;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + 43200;
        $tokenHash = hash('sha256', $token);
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;
        $this->Auth_model->issue_session(
            (int) $user['id'],
            $tokenHash,
            gmdate('Y-m-d H:i:s', $expiresAt),
            $ipAddress,
            $userAgent
        );
        $this->Auth_model->record_login_attempt($identityHash, $ipAddress, true);
        (new Audit_logger())->record(
            (int) $user['id'],
            $user['role_slug'],
            $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
            'login',
            'google_authentication'
        );
        (new Api_response())->success([
            'accessToken' => $token,
            'tokenType' => 'Bearer',
            'expiresAt' => gmdate(DATE_ATOM, $expiresAt),
            'user' => [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'name' => $user['full_name'] ?: $user['username'],
                'role' => $user['role_slug'],
                'barangayId' => $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
                'farmerId' => $user['farmer_id'] === null ? null : (int) $user['farmer_id'],
            ],
        ]);
    }

    public function request_password_reset()
    {
        $this->require_database_configuration();
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > 16384) {
            (new Api_response())->error('request_too_large', 'The request body is too large.', 413);
        }
        $frontendUrl = rtrim(getenv('FRONTEND_BASE_URL') ?: '', '/');
        if ($frontendUrl === '' || !filter_var($frontendUrl, FILTER_VALIDATE_URL)) {
            (new Api_response())->error('password_reset_not_configured', 'Password reset is not configured on this server.', 503);
        }

        $body = $this->request->json();
        $email = is_array($body) && isset($body['email']) && is_string($body['email'])
            ? strtolower(trim($body['email']))
            : '';
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : 'unknown';
        $identityHash = hash('sha256', filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'invalid');
        $this->call->model('Auth_model');
        if ($this->Auth_model->password_reset_requests_in_window($identityHash, $ipAddress) >= 5) {
            (new Api_response())->success([
                'message' => 'If an active account uses that email, a password reset link will be sent.',
            ]);
        }
        $this->Auth_model->record_password_reset_request($identityHash, $ipAddress);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 255) {
            $rawToken = bin2hex(random_bytes(32));
            $this->Auth_model->request_password_reset(
                $email,
                hash('sha256', $rawToken),
                gmdate('Y-m-d H:i:s', time() + 3600),
                $frontendUrl . '/reset-password#token=' . rawurlencode($rawToken)
            );
        }

        (new Api_response())->success([
            'message' => 'If an active account uses that email, a password reset link will be sent.',
        ]);
    }

    public function complete_password_reset()
    {
        $this->require_database_configuration();
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > 16384) {
            (new Api_response())->error('request_too_large', 'The request body is too large.', 413);
        }
        $body = $this->request->json();
        $token = is_array($body) && isset($body['token']) && is_string($body['token'])
            ? strtolower(trim($body['token']))
            : '';
        $password = is_array($body) && isset($body['password']) && is_string($body['password'])
            ? $body['password']
            : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $token) || strlen($password) < 12 || strlen($password) > 1024) {
            (new Api_response())->error('invalid_reset_request', 'Provide a valid reset link and a 12–1024 character password.', 422);
        }
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Password hashing failed.');
        }
        $this->call->model('Auth_model');
        if (!$this->Auth_model->complete_password_reset(hash('sha256', $token), $passwordHash)) {
            (new Api_response())->error('invalid_reset_token', 'The reset link is invalid, expired, or already used.', 400);
        }
        (new Api_response())->success(['message' => 'Password updated. Sign in with your new password.']);
    }

    public function email_preferences()
    {
        $this->call->model('Auth_model');
        (new Api_response())->success([
            'announcementsEnabled' => $this->Auth_model->announcement_email_preference(Auth_context::user()),
        ]);
    }

    public function update_email_preferences()
    {
        $body = $this->request->json();
        if (!is_array($body) || !array_key_exists('announcementsEnabled', $body) || !is_bool($body['announcementsEnabled'])) {
            (new Api_response())->error('invalid_request', 'Provide announcementsEnabled as true or false.', 422);
        }
        $this->call->model('Auth_model');
        (new Api_response())->success(
            $this->Auth_model->update_announcement_email_preference(Auth_context::user(), $body['announcementsEnabled'])
        );
    }

    public function me()
    {
        $user = Auth_context::user();
        (new Api_response())->success([
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'name' => $user['full_name'] ?: $user['username'],
            'role' => $user['role_slug'],
            'roleName' => $user['role_name'],
            'barangayId' => $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
            'barangayName' => $user['barangay_name'],
            'farmerId' => $user['farmer_id'] === null ? null : (int) $user['farmer_id'],
        ]);
    }

    public function update_profile()
    {
        $body = $this->request->json();
        if (!is_array($body)) {
            (new Api_response())->error('invalid_request', 'Provide profile details as a JSON object.', 400);
        }

        $changes = [];
        if (array_key_exists('fullName', $body)) {
            if (!is_string($body['fullName'])) {
                (new Api_response())->error('invalid_name', 'Name must be text.', 422);
            }
            $fullName = trim($body['fullName']);
            if ($fullName === '' || strlen($fullName) > 150) {
                (new Api_response())->error('invalid_name', 'Name must contain 1–150 characters.', 422);
            }
            $changes['fullName'] = $fullName;
        }
        if (array_key_exists('email', $body)) {
            if (!is_string($body['email'])) {
                (new Api_response())->error('invalid_email', 'Email must be text.', 422);
            }
            $email = strtolower(trim($body['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                (new Api_response())->error('invalid_email', 'Provide a valid email address.', 422);
            }
            $changes['email'] = $email;
        }
        if (array_key_exists('password', $body)) {
            if (!is_string($body['password']) || strlen($body['password']) < 12 || strlen($body['password']) > 1024) {
                (new Api_response())->error('weak_password', 'Password must contain 12–1024 characters.', 422);
            }
            $changes['password'] = $body['password'];
        }
        if (array_key_exists('currentPassword', $body)) {
            if (!is_string($body['currentPassword']) || strlen($body['currentPassword']) > 1024) {
                (new Api_response())->error('invalid_current_password', 'Current password is invalid.', 422);
            }
            $changes['currentPassword'] = $body['currentPassword'];
        }
        if (!$changes || (count($changes) === 1 && array_key_exists('currentPassword', $changes))) {
            (new Api_response())->error('invalid_request', 'Provide a name, email, or password change.', 422);
        }

        $this->call->model('Auth_model');
        try {
            $result = $this->Auth_model->update_profile(Auth_context::user(), $changes);
        } catch (InvalidArgumentException $exception) {
            (new Api_response())->error('invalid_profile_update', $exception->getMessage(), 422);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                (new Api_response())->error('email_in_use', 'That email address is already associated with another account.', 409);
            }
            throw $exception;
        }
        if (!$result) {
            (new Api_response())->error('not_found', 'The account is no longer active.', 404);
        }

        (new Api_response())->success($result);
    }

    public function logout()
    {
        $authorization = isset($_SERVER['HTTP_AUTHORIZATION'])
            ? trim($_SERVER['HTTP_AUTHORIZATION'])
            : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) : '');

        if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $authorization, $matches)) {
            (new Api_response())->error('unauthenticated', 'Authentication is required.', 401);
        }

        $user = Auth_context::user();
        $this->call->model('Auth_model');
        $this->Auth_model->revoke_session(hash('sha256', strtolower($matches[1])));

        $audit = new Audit_logger();
        $audit->record(
            (int) $user['id'],
            $user['role_slug'],
            $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
            'logout',
            'authentication'
        );

        (new Api_response())->success(['message' => 'Signed out successfully.']);
    }

    public function options()
    {
        http_response_code(204);
        exit;
    }

    private function require_database_configuration()
    {
        if (
            (getenv('DB_HOST') ?: '') === '' ||
            (getenv('DB_USERNAME') ?: '') === '' ||
            (getenv('DB_NAME') ?: '') === ''
        ) {
            (new Api_response())->error(
                'database_not_configured',
                'Configure DB_HOST, DB_USERNAME, and DB_NAME in the backend .env before signing in.',
                503
            );
        }
    }

    private function verify_google_credential($idToken)
    {
        $clientId = getenv('GOOGLE_CLIENT_ID') ?: '';
        if ($clientId === '') {
            (new Api_response())->error('google_sign_in_not_configured', 'Google sign-in is not configured on this server.', 503);
        }
        if (!function_exists('curl_init')) {
            (new Api_response())->error('google_verification_unavailable', 'Google sign-in verification is unavailable on this server.', 503);
        }

        $curl = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false || $status >= 500 || $status === 0) {
            (new Api_response())->error(
                'google_verification_unavailable',
                'Google sign-in verification is temporarily unavailable.',
                503
            );
        }
        if ($status !== 200) {
            return false;
        }
        $claims = json_decode($response, true);
        if (
            !is_array($claims) ||
            !isset($claims['aud'], $claims['iss'], $claims['exp'], $claims['email'], $claims['sub']) ||
            !hash_equals($clientId, (string) $claims['aud']) ||
            !in_array($claims['iss'], ['accounts.google.com', 'https://accounts.google.com'], true) ||
            (int) $claims['exp'] <= time() ||
            !in_array($claims['email_verified'] ?? false, [true, 'true', 1, '1'], true) ||
            !filter_var($claims['email'], FILTER_VALIDATE_EMAIL) ||
            !preg_match('/^[A-Za-z0-9_-]{10,255}$/', (string) $claims['sub'])
        ) {
            return false;
        }
        return $claims;
    }
}
