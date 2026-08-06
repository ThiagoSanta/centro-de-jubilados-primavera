<?php

namespace CJP\Modules\Pagos;

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
        $input = json_decode(file_get_contents('php://input'), true);
        $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

        $resultado = $this->pagoService->registrar($input, $usuarioId);

        ResponseHelper::json([
            'success' => true,
            'message' => 'Pago registrado correctamente.',
            'data' => $resultado
        ]);
    }

    public function anular(array $params): void
    {
        $id = $params['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);
        $motivo = $input['motivo'] ?? '';
        $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

        $this->pagoService->anular($id, $motivo, $usuarioId);

        ResponseHelper::json([
            'success' => true,
            'message' => 'Pago anulado correctamente.'
        ]);
    }

    public function getBySocio(array $params): void
    {
        $socioId = $params['socioId'] ?? null;
        $pagos = $this->pagoService->getPagosBySocio($socioId);

        ResponseHelper::json([
            'success' => true,
            'data' => $pagos
        ]);
    }

    public function getAll(array $params = []): void
    {
        $filtros = [
            'socio_id'    => $_GET['socio_id'] ?? null,
            'estado'      => $_GET['estado'] ?? null,
            'fecha_desde' => $_GET['fecha_desde'] ?? null,
            'fecha_hasta' => $_GET['fecha_hasta'] ?? null
        ];
        $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;

        $resultado = $this->pagoService->getPagos($filtros, $pagina);

        ResponseHelper::json([
            'success' => true,
            'data' => $resultado['data'],
            'meta' => [
                'total'        => $resultado['total'],
                'paginas'      => $resultado['paginas'],
                'pagina_actual' => $pagina
            ]
        ]);
    }

    public function getOne(array $params): void
    {
        $id = $params['id'] ?? null;
        $pago = $this->pagoService->getPago($id);

        ResponseHelper::json([
            'success' => true,
            'data' => $pago
        ]);
    }

    public function getComprobante(array $params): void
    {
        $id = $params['id'] ?? null;

        $filepath = __DIR__ . '/../../../storage/comprobantes/' . $id . '.pdf';

        if (!file_exists($filepath)) {
            ResponseHelper::json(['success' => false, 'message' => 'Comprobante no encontrado.'], 404);
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="comprobante_' . $id . '.pdf"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}
