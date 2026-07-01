<?php

namespace CJP\Shared\Helpers;

class ResponseHelper
{
    /**
     * Send a JSON response with the given status code and terminate execution.
     *
     * @param array $data
     * @param int $status
     * @return void
     */
    public static function json(array $data, int $status = 200): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send a standard success JSON response.
     *
     * @param mixed $data
     * @param string $message
     * @param int $status
     * @return void
     */
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): void
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * Send a standard error JSON response.
     *
     * @param string $message
     * @param int $status
     * @param mixed $errors
     * @return void
     */
    public static function error(string $message, int $status = 400, mixed $errors = null): void
    {
        self::json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }
}
