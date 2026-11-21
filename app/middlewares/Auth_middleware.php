<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Api_response.php';
require_once APP_DIR . 'libraries/Auth_context.php';

class Auth_middleware
{
    public function handle($next)
    {
        $authorization = isset($_SERVER['HTTP_AUTHORIZATION'])
            ? trim($_SERVER['HTTP_AUTHORIZATION'])
            : (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) : '');

        if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $authorization, $matches)) {
            (new Api_response())->error('unauthenticated', 'Authentication is required.', 401);
        }

        if (
            (getenv('DB_HOST') ?: '') === '' ||
            (getenv('DB_USERNAME') ?: '') === '' ||
            (getenv('DB_NAME') ?: '') === ''
        ) {
            (new Api_response())->error('database_not_configured', 'The backend database is not configured.', 503);
        }

        $lava = lava_instance();
        $lava->call->model('Auth_model');
        $tokenHash = hash('sha256', strtolower($matches[1]));
        $user = $lava->Auth_model->find_user_by_token_hash($tokenHash);

        if (!$user) {
            (new Api_response())->error('unauthenticated', 'The access token is invalid or expired.', 401);
        }

        if ($user['role_slug'] === 'barangay_personnel' && empty($user['barangay_id'])) {
            (new Api_response())->error(
                'barangay_assignment_required',
                'This account must be assigned to a barangay before it can access the system.',
                403
            );
        }

        if ($user['role_slug'] === 'farmer' && empty($user['farmer_id'])) {
            (new Api_response())->error(
                'farmer_profile_required',
                'This account must be linked to an active farmer profile before it can access the system.',
                403
            );
        }

        Auth_context::set_user($user);
        return $next();
    }
}
