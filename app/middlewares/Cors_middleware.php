<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Api_response.php';

class Cors_middleware
{
    public function handle($next)
    {
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim($_SERVER['HTTP_ORIGIN']) : '';
        $requestScheme = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off'
            ? 'https'
            : 'http';
        $requestHost = isset($_SERVER['HTTP_HOST']) ? trim($_SERVER['HTTP_HOST']) : '';
        $requestOrigin = $requestHost === '' ? '' : $requestScheme . '://' . $requestHost;
        $isSameOrigin = $requestOrigin !== '' && strcasecmp($origin, $requestOrigin) === 0;
        $allowedOrigins = array_filter(array_map(
            'trim',
            explode(
                ',',
                getenv('CORS_ALLOWED_ORIGINS')
                    ?: 'http://localhost:5173,http://127.0.0.1:5173'
            )
        ));

        if ($origin !== '' && !$isSameOrigin) {
            if (!in_array($origin, $allowedOrigins, true)) {
                (new Api_response())->error('origin_not_allowed', 'This origin is not allowed.', 403);
            }

            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Max-Age: 600');
        }

        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        return $next();
    }
}
