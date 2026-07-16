<?php

namespace CJP\Modules\Pagos;

use Exception;
use CJP\Shared\Helpers\ResponseHelper;

class PagoController
{
    private PagoService $pagoService;

    public function __construct()
    {
        $this->pagoService = new PagoService();
    }

    public function registrar(array $params = []): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

            $resultado = $this->pagoService->registrar($input, $usuarioId);

            ResponseHelper::json([
                'success' => true,
                'message' => 'Pago registrado correctamente.',
                'data' => $resultado
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function anular(array $params): void
    {
        try {
            $id = $params['id'] ?? null;
            $input = json_decode(file_get_contents('php://input'), true);
            $motivo = $input['motivo'] ?? '';
            $usuarioId = $_SESSION['user_id'] ?? 'sistema';

            $this->pagoService->anular($id, $motivo, $usuarioId);

            ResponseHelper::json([
                'success' => true,
                'message' => 'Pago anulado correctamente.'
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getBySocio(array $params): void
    {
        try {
            $socioId = $params['socioId'] ?? null;
            $pagos = $this->pagoService->getPagosBySocio($socioId);

            ResponseHelper::json([
                'success' => true,
                'data' => $pagos
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getAll(array $params = []): void
    {
        try {
            $filtros = [
                'socio_id' => $_GET['socio_id'] ?? null,
                'estado' => $_GET['estado'] ?? null,
                'fecha_desde' => $_GET['fecha_desde'] ?? null,
                'fecha_hasta' => $_GET['fecha_hasta'] ?? null
            ];
            $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;

            $resultado = $this->pagoService->getPagos($filtros, $pagina);

            ResponseHelper::json([
                'success' => true,
                'data' => $resultado['data'],
                'meta' => [
                    'total' => $resultado['total'],
                    'paginas' => $resultado['paginas'],
                    'pagina_actual' => $pagina
                ]
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getOne(array $params): void
    {
        try {
            $id = $params['id'] ?? null;
            $pago = $this->pagoService->getPago($id);

            ResponseHelper::json([
                'success' => true,
                'data' => $pago
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getComprobante(array $params): void
    {
        try {
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
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
