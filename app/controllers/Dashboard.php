<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Dashboard extends Controller
{
    public function index()
    {
        $index_file = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR . 'react' . DIRECTORY_SEPARATOR . 'index.html';

        if (!is_file($index_file) || !is_readable($index_file)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'React dashboard assets are missing. Run npm install and npm run build in the frontend directory.';
            exit;
        }

        $html = file_get_contents($index_file);
        if ($html === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'React dashboard assets could not be read.';
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo str_replace('./assets/', 'react/assets/', $html);
        exit;
    }

    public function data()
    {
        require_once APP_DIR . 'libraries/Auth_context.php';
        require_once APP_DIR . 'libraries/Api_response.php';

        $user = Auth_context::user();
        $this->call->model('Agri_dashboard_model');
        $payload = $this->Agri_dashboard_model->summary($user);

        (new Api_response())->success($payload);
    }
}
