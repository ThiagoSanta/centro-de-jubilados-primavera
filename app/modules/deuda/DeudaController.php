<?php

namespace CJP\Modules\Deuda;

use Exception;
use CJP\Shared\AuthMiddleware;
use CJP\Shared\Helpers\ResponseHelper;

class DeudaController
{
    private DeudaService $deudaService;

    public function __construct(?DeudaService $deudaService = null)
    {
        $this->deudaService = $deudaService ?? new DeudaService();
    }

    public function generarMensual(array $params): void
    {
        AuthMiddleware::requireAuth();

        $data = json_decode(file_get_contents('php://input'), true);
        $periodo = $data['periodo'] ?? '';
        $confirmarDuplicado = isset($data['confirmar_duplicado']) ? (bool)$data['confirmar_duplicado'] : false;
        $usuarioId = $_SESSION['usuario_id'] ?? null;

        if (!$usuarioId) {
            ResponseHelper::json(['error' => 'Usuario no autenticado'], 401);
        }

        try {
            $resultado = $this->deudaService->generarDeudaMensual($periodo, $confirmarDuplicado, $usuarioId);
            ResponseHelper::json([
                'success' => true,
                'resultado' => $resultado
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['error' => $e->getMessage()], 400);
        }
    }

    public function cargarAnterior(array $params): void
    {
        AuthMiddleware::requireAuth();

        $data = json_decode(file_get_contents('php://input'), true);
        $socioId = $data['socio_id'] ?? '';
        $monto = isset($data['monto']) ? (float)$data['monto'] : 0.0;
        $usuarioId = $_SESSION['usuario_id'] ?? null;

        if (!$usuarioId) {
            ResponseHelper::json(['error' => 'Usuario no autenticado'], 401);
        }

        try {
            $this->deudaService->cargarDeudaAnterior($socioId, $monto, $usuarioId);
            ResponseHelper::json([
                'success' => true,
                'message' => 'Deuda anterior cargada correctamente'
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['error' => $e->getMessage()], 400);
        }
    }

    public function exonerar(array $params): void
    {
        AuthMiddleware::requireAuth();

        $id = $params['id'] ?? '';
        $data = json_decode(file_get_contents('php://input'), true);
        $motivo = $data['motivo'] ?? '';
        $usuarioId = $_SESSION['usuario_id'] ?? null;

        if (!$usuarioId) {
            ResponseHelper::json(['error' => 'Usuario no autenticado'], 401);
        }

        try {
            $this->deudaService->exonerarDeuda($id, $motivo, $usuarioId);
            ResponseHelper::json([
                'success' => true,
                'message' => 'Deuda exonerada correctamente'
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['error' => $e->getMessage()], 400);
        }
    }

    public function getBySocio(array $params): void
    {
        AuthMiddleware::requireAuth();
        $socioId = $params['socioId'] ?? '';

        try {
            $deudas = $this->deudaService->getDeudaSocio($socioId);
            ResponseHelper::json([
                'success' => true,
                'data' => $deudas
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['error' => $e->getMessage()], 400);
        }
    }

    public function getPendientesBySocio(array $params): void
    {
        AuthMiddleware::requireAuth();
        $socioId = $params['socioId'] ?? '';

        try {
            $deudas = $this->deudaService->getDeudaPendienteSocio($socioId);
            ResponseHelper::json([
                'success' => true,
                'data' => $deudas
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['error' => $e->getMessage()], 400);
        }
    }

    public function registrarCuota(array $params): void
    {
        AuthMiddleware::requireAuth();

        $data = json_decode(file_get_contents('php://input'), true);
        $usuarioId = $_SESSION['usuario_id'] ?? null;

        if (!$usuarioId) {
            ResponseHelper::json(['error' => 'Usuario no autenticado'], 401);
        }

        try {
            $resultado = $this->deudaService->registrarCuota($data, $usuarioId);
            ResponseHelper::json([
                'success' => true,
                'id' => $resultado['id'],
                'message' => 'Cuota registrada correctamente'
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['error' => $e->getMessage()], 400);
        }
    }

    public function getCuotaVigente(array $params): void
    {
        AuthMiddleware::requireAuth();

        try {
            $cuota = $this->deudaService->getCuotaVigente();
            ResponseHelper::json([
                'success' => true,
                'data' => $cuota
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['error' => $e->getMessage()], 400);
        }
    }

    public function getHistoricoCuotas(array $params): void
    {
        AuthMiddleware::requireAuth();

        try {
            $historico = $this->deudaService->getHistoricoCuotas();
            ResponseHelper::json([
                'success' => true,
                'data' => $historico
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['error' => $e->getMessage()], 400);
        }
    }
}
