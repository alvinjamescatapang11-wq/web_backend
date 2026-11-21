<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Auth_context.php';
require_once APP_DIR . 'libraries/Api_response.php';

class Farmer extends Controller
{
    public function dashboard()
    {
        $user = Auth_context::user();
        $this->call->model('Farmer_dashboard_model');
        $payload = $this->Farmer_dashboard_model->summary($user);

        (new Api_response())->success($payload);
    }
}
