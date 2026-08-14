<?php
namespace CJP\Modules\Auditoria;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Shared\AuthMiddleware;

class AuditoriaController {
    private AuditoriaService $service;

    public function __construct() {
        $this->service = new AuditoriaService();
    }

    public function getAll(array $params): void {
        AuthMiddleware::requireAuth('administrador');
        $filtros = [
            'usuario_id' => $_GET['usuario_id'] ?? null,
            'accion' => $_GET['accion'] ?? null,
            'entidad_afectada' => $_GET['entidad_afectada'] ?? null,
            'fecha_desde' => $_GET['fecha_desde'] ?? null,
            'fecha_hasta' => $_GET['fecha_hasta'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : null,
        ];
        $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
        
        $result = $this->service->getAll($filtros, $pagina);
        ResponseHelper::success([
            'items' => $result['data'],
            'meta'  => ['total' => $result['total'], 'paginas' => $result['paginas']]
        ], 'Registros de auditoría obtenidos correctamente.');
    }

    public function getOne(array $params): void {
        AuthMiddleware::requireAuth('administrador');
        $auditoria = $this->service->getOne($params['id']);
        ResponseHelper::success($auditoria, 'Registro de auditoría obtenido correctamente.');
    }
}
