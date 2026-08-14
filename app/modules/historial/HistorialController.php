<?php
namespace CJP\Modules\Historial;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Shared\AuthMiddleware;

class HistorialController {
    private HistorialService $service;

    public function __construct() {
        $this->service = new HistorialService();
    }

    public function getBySocio(array $params): void {
        AuthMiddleware::requireAuth();
        $historial = $this->service->getBySocio($params['socioId']);
        ResponseHelper::success($historial, 'Histórico del socio obtenido correctamente.');
    }
}
