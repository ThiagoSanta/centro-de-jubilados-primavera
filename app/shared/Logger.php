<?php

namespace CJP\Shared;

use Throwable;

class Logger
{
    public static function error(string $correlationId, Throwable $e): void
    {
        $dir = __DIR__ . '/../../storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/errors.log';

        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? 'N/A';
        $timestamp = date('Y-m-d H:i:s');

        // Sanitización de datos sensibles en el Body/POST
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput !== false ? $rawInput : '', true) ?? $_POST;
        $sensitiveKeys = ['password', 'clave', 'contrasena', 'contrasena_hash', 'token', 'secret'];
        foreach ($sensitiveKeys as $key) {
            if (isset($input[$key])) {
                $input[$key] = '********';
            }
        }
        $inputStr = json_encode($input, JSON_UNESCAPED_UNICODE);

        $logEntry = sprintf(
            "[%s] [CorrelationID: %s] [%s %s]\nPayload: %s\nExcepcion: %s: %s en %s:%d\nStack trace:\n%s\n----------------------------------------\n",
            $timestamp,
            $correlationId,
            $method,
            $uri,
            $inputStr,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        error_log($logEntry, 3, $file);
    }
}
