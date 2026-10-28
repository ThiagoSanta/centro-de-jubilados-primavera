<?php

namespace CJP\Modules\Backup;

use CJP\Modules\Auth\AuthService;
use CJP\Shared\AuthMiddleware;
use CJP\Shared\Exceptions\AppException;
use CJP\Shared\Helpers\ResponseHelper;

class RestoreController
{
    private ?RestoreService $restoreService = null;
    private ?AuthService   $authService = null;

    /**
     * Constructor del controlador de restauración de respaldos.
     * Permite la inyección de servicios o utiliza instancias por defecto de forma diferida.
     *
     * @param RestoreService|null $restoreService
     * @param AuthService|null   $authService
     */
    public function __construct(?RestoreService $restoreService = null, ?AuthService $authService = null)
    {
        $this->restoreService = $restoreService;
        $this->authService   = $authService;
    }

    private function getRestoreService(): RestoreService
    {
        return $this->restoreService ??= new RestoreService();
    }

    private function getAuthService(): AuthService
    {
        return $this->authService ??= new AuthService();
    }

    /**
     * POST /api/backup/restaurar — solo rol administrador
     * Procesa la subida de un archivo .sql y delega la restauración en RestoreService.
     *
     * @param array $params Parámetros adicionales de la ruta
     * @return void
     */
    public function restaurar(array $params = []): void
    {
        AuthMiddleware::requireAuth('administrador');

        $session   = $this->getAuthService()->checkSession();
        $usuarioId = $session['usuario_id'] ?? '';

        $archivo      = $_FILES['archivo'] ?? null;
        $confirmacion = $_POST['confirmacion'] ?? null;

        try {
            $resultado = $this->getRestoreService()->restaurar($archivo, $confirmacion, $usuarioId);
            ResponseHelper::success(null, $resultado['message'] ?? 'Base de datos restaurada. Por seguridad se cerro la sesion: vuelva a iniciar sesion.');
        } catch (AppException $e) {
            ResponseHelper::error($e->getMessage(), $e->getStatusCode());
        }
    }
}
