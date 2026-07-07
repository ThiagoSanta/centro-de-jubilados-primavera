<?php

namespace CJP\Modules\Socios;

use Exception;
use CJP\Shared\AuthMiddleware;
use CJP\Modules\Auth\AuthService;
use CJP\Shared\Helpers\ResponseHelper;

class SocioController
{
    private SocioService $socioService;
    private AuthService $authService;

    /**
     * SocioController constructor.
     *
     * @param SocioService|null $socioService
     * @param AuthService|null $authService
     */
    public function __construct(?SocioService $socioService = null, ?AuthService $authService = null)
    {
        $this->socioService = $socioService ?? new SocioService();
        $this->authService = $authService ?? new AuthService();
    }

    /**
     * GET /api/socios — List all partners with filters and pagination.
     *
     * @param array $params
     * @return void
     */
    public function index(array $params): void
    {
        AuthMiddleware::requireAuth();

        $pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
        if ($pagina < 1) {
            $pagina = 1;
        }

        $filtros = [
            'estado'             => $_GET['estado'] ?? null,
            'zona_id'            => $_GET['zona_id'] ?? null,
            'modalidad_cobranza' => $_GET['modalidad_cobranza'] ?? null,
            'con_deuda'          => isset($_GET['con_deuda']) ? $_GET['con_deuda'] : null,
            'busqueda'           => $_GET['busqueda'] ?? null,
        ];

        try {
            $result = $this->socioService->listar($filtros, $pagina);
            ResponseHelper::success($result, 'Socios obtenidos exitosamente.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/socios/{id} — Retrieve partner details.
     *
     * @param array $params
     * @return void
     */
    public function show(array $params): void
    {
        AuthMiddleware::requireAuth();

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        try {
            $socio = $this->socioService->obtener($id);
            ResponseHelper::success($socio, 'Socio obtenido exitosamente.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 404);
        }
    }

    /**
     * POST /api/socios — Create a new partner.
     *
     * @param array $params
     * @return void
     */
    public function create(array $params): void
    {
        AuthMiddleware::requireAuth();
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $socio = $this->socioService->crear($input, $usuarioId);
            ResponseHelper::success($socio, 'Socio creado exitosamente.', 201);
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /api/socios/{id} — Update partner data.
     *
     * @param array $params
     * @return void
     */
    public function update(array $params): void
    {
        AuthMiddleware::requireAuth();
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $socio = $this->socioService->editar($id, $input, $usuarioId);
            ResponseHelper::success($socio, 'Socio actualizado exitosamente.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/socios/{id}/suspender — Suspend a partner.
     *
     * @param array $params
     * @return void
     */
    public function suspend(array $params): void
    {
        AuthMiddleware::requireAuth();
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        try {
            $this->socioService->suspender($id, $usuarioId);
            ResponseHelper::success(null, 'Socio suspendido exitosamente.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/socios/{id}/reactivar — Reactivate a partner.
     *
     * @param array $params
     * @return void
     */
    public function reactivate(array $params): void
    {
        AuthMiddleware::requireAuth();
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        try {
            $this->socioService->reactivar($id, $usuarioId);
            ResponseHelper::success(null, 'Socio reactivado exitosamente.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * DELETE /api/socios/{id} — Soft delete a partner.
     *
     * @param array $params
     * @return void
     */
    public function delete(array $params): void
    {
        AuthMiddleware::requireAuth();
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $motivo = $input['motivo'] ?? '';
            if (empty(trim($motivo))) {
                ResponseHelper::error('El motivo de la baja es obligatorio.', 400);
                return;
            }

            $this->socioService->eliminar($id, $motivo, $usuarioId);
            ResponseHelper::success(null, 'Socio dado de baja exitosamente.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/socios/{id}/revertir — Revert a logical delete.
     *
     * @param array $params
     * @return void
     */
    public function revertDelete(array $params): void
    {
        AuthMiddleware::requireAuth();
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        try {
            $this->socioService->revertirEliminacion($id, $usuarioId);
            ResponseHelper::success(null, 'Baja de socio revertida exitosamente.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/socios/{id}/geolocalizacion — Manually correct partner geolocalisation coordinates.
     *
     * @param array $params
     * @return void
     */
    public function corregirGeo(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            if (!isset($input['lat']) || !isset($input['lng'])) {
                ResponseHelper::error('Los campos "lat" y "lng" son obligatorios.', 400);
                return;
            }

            $lat = (float)$input['lat'];
            $lng = (float)$input['lng'];

            $this->socioService->corregirGeolocalizacion($id, $lat, $lng, $usuarioId);
            ResponseHelper::success(null, 'Geolocalización corregida exitosamente.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/socios/importar — Import partners from CSV file.
     *
     * @param array $params
     * @return void
     */
    public function importarCSV(array $params): void
    {
        AuthMiddleware::requireAuth();
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $fileField = isset($_FILES['archivo']) ? 'archivo' : (isset($_FILES['file']) ? 'file' : null);

        if ($fileField === null || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
            ResponseHelper::error('Se requiere subir un archivo CSV válido en el campo "archivo".', 400);
            return;
        }

        $tmpPath = $_FILES[$fileField]['tmp_name'];

        try {
            $result = $this->socioService->importarCSV($tmpPath, $usuarioId);
            ResponseHelper::success($result, 'Importación CSV procesada con éxito.');
        } catch (Exception $e) {
            ResponseHelper::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/socios/{id}/qr — Retrieve and serve the partner's QR code.
     *
     * @param array $params
     * @return void
     */
    public function getQR(array $params): void
    {
        AuthMiddleware::requireAuth();

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        $dir = dirname(__DIR__, 2) . '/storage/qr';
        $path = $dir . '/' . $id . '.png';

        if (!file_exists($path)) {
            try {
                // Try to generate it if the partner exists
                $this->socioService->obtener($id);
                $this->socioService->generarQR($id);
            } catch (Exception $e) {
                ResponseHelper::error('Socio no encontrado.', 404);
                return;
            }
        }

        if (file_exists($path)) {
            header('Content-Type: image/png');
            readfile($path);
            exit;
        }

        ResponseHelper::error('Código QR no encontrado.', 404);
    }
}
