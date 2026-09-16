<?php

namespace CJP\Shared\Helpers;

class ResponseHelper
{
    /**
     * Emite una respuesta HTTP en formato JSON con el código de estado indicado y finaliza la ejecución del script.
     *
     * @param array $data Estructura de datos a serializar en JSON
     * @param int $status Código de estado HTTP (por defecto 200)
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
     * Emite una respuesta JSON estándar para operaciones exitosas.
     *
     * @param mixed $data Carga útil de datos de la respuesta
     * @param string $message Mensaje descriptivo del resultado
     * @param int $status Código HTTP de éxito
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
     * Emite una respuesta JSON estandarizada para informar errores al cliente.
     *
     * @param string $message Mensaje explicativo del error
     * @param int $status Código de estado HTTP representativo del error
     * @param mixed $errors Detalle adicional o lista de errores de validación
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
