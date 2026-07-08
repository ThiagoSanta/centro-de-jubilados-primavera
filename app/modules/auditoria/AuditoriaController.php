<?php
namespace CJP\Modules\Auditoria;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Shared\AuthMiddleware;
use Exception;

class AuditoriaController {
    private AuditoriaService $service;

    public function __construct() {
        $this->service = new AuditoriaService();
    }

    public function getAll(array $params): void {
        AuthMiddleware::requireAuth();
        $filtros = [
            'usuario_id' => $_GET['usuario_id'] ?? null,
            'accion' => $_GET['accion'] ?? null,
            'entidad_afectada' => $_GET['entidad_afectada'] ?? null,
            'fecha_desde' => $_GET['fecha_desde'] ?? null,
            'fecha_hasta' => $_GET['fecha_hasta'] ?? null,
        ];
        $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
        
        $result = $this->service->getAll($filtros, $pagina);
        ResponseHelper::json(['success' => true, 'data' => $result['data'], 'meta' => ['total' => $result['total'], 'paginas' => $result['paginas']]]);
    }

    public function getOne(array $params): void {
        AuthMiddleware::requireAuth();
        try {
            $auditoria = $this->service->getOne($params['id']);
            ResponseHelper::json(['success' => true, 'data' => $auditoria]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }
}
