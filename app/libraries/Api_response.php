<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Api_response
{
    public function success($data, $status = 200)
    {
        $this->send(['data' => $data], $status);
    }

    public function error($code, $message, $status)
    {
        $this->send([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    public function send(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
