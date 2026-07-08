<?php
namespace CJP\Modules\Observaciones;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Shared\AuthMiddleware;
use Exception;

class ObservacionController {
    private ObservacionService $service;

    public function __construct() {
        $this->service = new ObservacionService();
    }

    public function getBySocio(array $params): void {
        AuthMiddleware::requireAuth();
        try {
            $observaciones = $this->service->getBySocio($params['socioId']);
            ResponseHelper::json(['success' => true, 'data' => $observaciones]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function agregar(array $params): void {
        $user = AuthMiddleware::requireAuth();
        $data = json_decode(file_get_contents('php://input'), true);
        
        try {
            if (!isset($data['socio_id']) || !isset($data['contenido'])) {
                throw new Exception("Faltan datos requeridos.");
            }
            $observacion = $this->service->agregar($data['socio_id'], $data['contenido'], $user['id']);
            ResponseHelper::json(['success' => true, 'data' => $observacion], 201);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
