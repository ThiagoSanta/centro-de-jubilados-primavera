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
        ResponseHelper::json(['success' => true, 'data' => $notificaciones]);
    }

    public function marcarLeida(array $params): void {
        AuthMiddleware::requireAuth();
        $this->service->marcarLeida($params['id']);
        ResponseHelper::json(['success' => true]);
    }

    public function archivar(array $params): void {
        AuthMiddleware::requireAuth();
        $this->service->archivar($params['id']);
        ResponseHelper::json(['success' => true]);
    }

    public function revertir(array $params): void {
        $user = AuthMiddleware::requireAuth();
        $this->service->revertir($params['id'], $user['id']);
        ResponseHelper::json(['success' => true]);
    }
}
