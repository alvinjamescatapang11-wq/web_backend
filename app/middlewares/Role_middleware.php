<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Api_response.php';
require_once APP_DIR . 'libraries/Auth_context.php';

class Role_middleware
{
    private $allowedRoles;

    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = $allowedRoles;
    }

    public function handle($next)
    {
        $user = Auth_context::user();

        if (!$user) {
            (new Api_response())->error('unauthenticated', 'Authentication is required.', 401);
        }

        if (!in_array($user['role_slug'], $this->allowedRoles, true)) {
            (new Api_response())->error('forbidden', 'You do not have permission to perform this action.', 403);
        }

        return $next();
    }
}
