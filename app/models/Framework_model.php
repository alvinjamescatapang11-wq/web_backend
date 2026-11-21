<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Framework_model extends Model
{
    public function dashboard_data()
    {
        return [
            'framework' => [
                'name' => 'LavaLust',
                'version' => config_item('version'),
            ],
            'runtime' => [
                'php' => PHP_VERSION,
                'environment' => config_item('environment'),
                'server' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
                'sapi' => PHP_SAPI,
            ],
            'architecture' => [
                [
                    'name' => 'Model',
                    'detail' => 'Framework_model prepares the dashboard data.',
                ],
                [
                    'name' => 'Controller',
                    'detail' => 'Dashboard routes the page and JSON API request.',
                ],
                [
                    'name' => 'View',
                    'detail' => 'React renders the interface from public assets, outside app/views.',
                ],
            ],
            'api' => [
                'endpoint' => 'api/dashboard',
                'method' => 'GET',
                'updatedAt' => date(DATE_ATOM),
            ],
        ];
    }
}
