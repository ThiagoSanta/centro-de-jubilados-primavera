<?php

namespace CJP\Shared;

use CJP\Shared\Exceptions\AppException;
use CJP\Shared\Helpers\ResponseHelper;
use Throwable;

class ExceptionHandler
{
    private static ?string $correlationId = null;

    public static function setCorrelationId(string $id): void
    {
        self::$correlationId = $id;
    }

    public static function getCorrelationId(): string
    {
        return self::$correlationId ?? 'N/A';
    }

    public static function handle(Throwable $e): void
    {
        $correlationId = self::getCorrelationId();

        if ($e instanceof AppException) {
            ResponseHelper::json([
                'success' => false,
                'message' => $e->getMessage(),
                'correlation_id' => $correlationId
            ], $e->getStatusCode());
            return;
        }

        // Registrar error de infraestructura en storage/logs/errors.log
        Logger::error($correlationId, $e);

        // Respuesta limpia de cara al cliente sin exponer datos del servidor
        ResponseHelper::json([
            'success' => false,
            'message' => 'Ocurrió un error interno en el servidor. Por favor, intente nuevamente o contacte a soporte.',
            'correlation_id' => $correlationId
        ], 500);
    }
}
