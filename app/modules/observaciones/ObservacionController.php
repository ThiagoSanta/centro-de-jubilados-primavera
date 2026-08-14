<?php
namespace CJP\Modules\Observaciones;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Shared\AuthMiddleware;
use CJP\Shared\Exceptions\AppException;

class ObservacionController {
    private ObservacionService $service;

    public function __construct() {
        $this->service = new ObservacionService();
    }

    public function getBySocio(array $params): void {
        AuthMiddleware::requireAuth();
        $observaciones = $this->service->getBySocio($params['socioId']);
        ResponseHelper::success($observaciones, 'Observaciones del socio obtenidas correctamente.');
    }

    public function agregar(array $params): void {
        $user = AuthMiddleware::requireAuth();
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['socio_id']) || !isset($data['contenido'])) {
            throw new AppException("Faltan datos requeridos.", 400);
        }
        $observacion = $this->service->agregar($data['socio_id'], $data['contenido'], $user['id']);
        ResponseHelper::success($observacion, 'Observación registrada correctamente.', 201);
    }
}
