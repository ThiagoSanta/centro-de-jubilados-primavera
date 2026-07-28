<?php

namespace CJP\Modules\Planillas;

use Exception;
use CJP\Shared\Helpers\ResponseHelper;
use CJP\Shared\AuthMiddleware;

class PlanillaController
{
    private PlanillaService $planillaService;

    public function __construct()
    {
        $this->planillaService = new PlanillaService();
    }

    public function generar(array $params): void
    {
        AuthMiddleware::requireAuth();
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (empty($input['zona_id']) || empty($input['cobrador_id'])) {
                ResponseHelper::json(['success' => false, 'message' => 'Faltan parámetros requeridos (zona_id, cobrador_id).'], 400);
                return;
            }

            $usuarioId = $_SESSION['usuario_id'];
            $resultado = $this->planillaService->generar($input['zona_id'], $input['cobrador_id'], $usuarioId);
            
            ResponseHelper::json([
                'success' => true,
                'message' => 'Planilla generada con éxito.',
                'data' => $resultado
            ], 201);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getAll(array $params): void
    {
        AuthMiddleware::requireAuth();
        try {
            $filtros = [
                'zona_id' => $_GET['zona_id'] ?? null,
                'cobrador_id' => $_GET['cobrador_id'] ?? null,
                'fecha_desde' => $_GET['fecha_desde'] ?? null,
                'fecha_hasta' => $_GET['fecha_hasta'] ?? null,
            ];
            $pagina = isset($_GET['page']) ? (int)$_GET['page'] : 1;

            $resultado = $this->planillaService->getHistorico($filtros, $pagina);
            ResponseHelper::json(['success' => true] + $resultado);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getOne(array $params): void
    {
        AuthMiddleware::requireAuth();
        try {
            $planilla = $this->planillaService->getPlanilla($params['id']);
            ResponseHelper::json(['success' => true, 'data' => $planilla]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    public function getPdf(array $params): void
    {
        AuthMiddleware::requireAuth();
        try {
            $planilla = $this->planillaService->getPlanilla($params['id']);
            if (empty($planilla['pdf_generado'])) {
                throw new Exception("Esta planilla no tiene PDF generado.");
            }
            $filePath = __DIR__ . '/../../../' . $planilla['pdf_generado'];
            if (!file_exists($filePath)) {
                throw new Exception("El archivo PDF no se encuentra en el servidor.");
            }
            
            header("Content-type: application/pdf");
            header("Content-Disposition: inline; filename=planilla_{$params['id']}.pdf");
            @readfile($filePath);
            exit;
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getCobradores(array $params): void
    {
        AuthMiddleware::requireAuth();
        try {
            $cobradores = $this->planillaService->getCobradores();
            ResponseHelper::json(['success' => true, 'data' => $cobradores]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
