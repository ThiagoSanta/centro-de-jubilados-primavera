<?php
namespace CJP\Modules\Historial;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Shared\AuthMiddleware;
use Exception;

class HistorialController {
    private HistorialService $service;

    public function __construct() {
        $this->service = new HistorialService();
    }

    public function getBySocio(array $params): void {
        AuthMiddleware::requireAuth();
        try {
            $historial = $this->service->getBySocio($params['socioId']);
            ResponseHelper::json(['success' => true, 'data' => $historial]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
