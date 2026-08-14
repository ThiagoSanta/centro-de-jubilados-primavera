<?php

namespace CJP\Modules\Pagos;

use CJP\Shared\AuthMiddleware;
use CJP\Shared\Helpers\ResponseHelper;

class PagoController
{
    private PagoService $pagoService;

    public function __construct(?PagoService $pagoService = null)
    {
        $this->pagoService = $pagoService ?? new PagoService();
    }

    public function registrar(array $params = []): void
    {
        AuthMiddleware::requireAuth();

        $input = json_decode(file_get_contents('php://input'), true);
        $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

        $resultado = $this->pagoService->registrar($input, $usuarioId);

        ResponseHelper::success($resultado, 'Pago registrado correctamente.');
    }

    public function anular(array $params): void
    {
        AuthMiddleware::requireAuth();

        $id = $params['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);
        $motivo = $input['motivo'] ?? '';
        $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

        $this->pagoService->anular($id, $motivo, $usuarioId);

        ResponseHelper::success(null, 'Pago anulado correctamente.');
    }

    public function getBySocio(array $params): void
    {
        AuthMiddleware::requireAuth();

        $socioId = $params['socioId'] ?? null;
        $pagos = $this->pagoService->getPagosBySocio($socioId);

        ResponseHelper::success($pagos, 'Pagos del socio obtenidos correctamente.');
    }

    public function getAll(array $params = []): void
    {
        AuthMiddleware::requireAuth();

        $filtros = [
            'socio_id'    => $_GET['socio_id'] ?? null,
            'estado'      => $_GET['estado'] ?? null,
            'fecha_desde' => $_GET['fecha_desde'] ?? null,
            'fecha_hasta' => $_GET['fecha_hasta'] ?? null
        ];
        $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;

        $resultado = $this->pagoService->getPagos($filtros, $pagina);

        ResponseHelper::success([
            'items' => $resultado['data'],
            'meta' => [
                'total'         => $resultado['total'],
                'paginas'       => $resultado['paginas'],
                'pagina_actual' => $pagina
            ]
        ], 'Listado de pagos obtenido correctamente.');
    }

    public function getOne(array $params): void
    {
        AuthMiddleware::requireAuth();

        $id = $params['id'] ?? null;
        $pago = $this->pagoService->getPago($id);

        ResponseHelper::success($pago, 'Pago obtenido correctamente.');
    }

    public function getComprobante(array $params): void
    {
        AuthMiddleware::requireAuth();

        $id = $params['id'] ?? null;

        $filepath = __DIR__ . '/../../../storage/comprobantes/' . $id . '.pdf';

        if (!file_exists($filepath)) {
            ResponseHelper::error('Comprobante no encontrado.', 404);
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="comprobante_' . $id . '.pdf"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}
