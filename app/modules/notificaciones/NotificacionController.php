<?php
namespace CJP\Modules\Notificaciones;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Shared\AuthMiddleware;

class NotificacionController {
    private NotificacionService $service;

    public function __construct() {
        $this->service = new NotificacionService();
    }

    public function getAll(array $params): void {
        AuthMiddleware::requireAuth();
        $filtros = [
            'estado' => $_GET['estado'] ?? null
        ];
        $notificaciones = $this->service->getAll($filtros);
        ResponseHelper::success($notificaciones, 'Notificaciones obtenidas correctamente.');
    }

    public function marcarLeida(array $params): void {
        AuthMiddleware::requireAuth();
        $this->service->marcarLeida($params['id']);
        ResponseHelper::success(null, 'Notificación marcada como leída.');
    }

    public function archivar(array $params): void {
        AuthMiddleware::requireAuth();
        $this->service->archivar($params['id']);
        ResponseHelper::success(null, 'Notificación archivada correctamente.');
    }

    public function revertir(array $params): void {
        $user = AuthMiddleware::requireAuth();
        $this->service->revertir($params['id'], $user['id']);
        ResponseHelper::success(null, 'Acción revertida correctamente.');
    }
}
