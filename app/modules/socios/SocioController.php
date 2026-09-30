<?php

namespace CJP\Modules\Socios;

use CJP\Shared\AuthMiddleware;
use CJP\Modules\Auth\AuthService;
use CJP\Shared\Helpers\ResponseHelper;
use CJP\Config\Config;
use Ramsey\Uuid\Uuid;

class SocioController
{
    private SocioService $socioService;
    private AuthService $authService;

    /**
     * Constructor de SocioController.
     * Inyecta las dependencias de socios y autenticación.
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
     * GET /api/socios — Lista socios con soporte de filtros (estado, zona, deuda, búsqueda) y paginación.
     *
     * @param array $params Parámetros de consulta (query params)
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
            'limit'              => isset($_GET['limit']) ? (int)$_GET['limit'] : null,
        ];

        $result = $this->socioService->listar($filtros, $pagina);
        ResponseHelper::success($result, 'Socios obtenidos exitosamente.');
    }

    /**
     * GET /api/socios/{id} — Obtiene la ficha completa de un socio por su identificador UUID.
     *
     * @param array $params Parámetros de ruta con 'id'
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

        $socio = $this->socioService->obtener($id);
        ResponseHelper::success($socio, 'Socio obtenido exitosamente.');
    }

    /**
     * POST /api/socios — Da de alta un nuevo socio en el padrón, asigna zona geográfica y genera su código QR.
     *
     * @param array $params
     * @return void
     */
    public function create(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $socio = $this->socioService->crear($input, $usuarioId);
        ResponseHelper::success($socio, 'Socio creado exitosamente.', 201);
    }

    /**
     * PUT /api/socios/{id} — Actualiza los datos de un socio existente, validando cambios sensibles de DNI y domicilio.
     *
     * @param array $params
     * @return void
     */
    public function update(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $socio = $this->socioService->editar($id, $input, $usuarioId);
        ResponseHelper::success($socio, 'Socio actualizado exitosamente.');
    }

    /**
     * POST /api/socios/{id}/suspender — Suspende transitoriamente a un socio activo.
     *
     * @param array $params
     * @return void
     */
    public function suspend(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        $this->socioService->suspender($id, $usuarioId);
        ResponseHelper::success(null, 'Socio suspendido exitosamente.');
    }

    /**
     * POST /api/socios/{id}/reactivar — Restablece el estado activo de un socio suspendido.
     *
     * @param array $params
     * @return void
     */
    public function reactivate(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        $this->socioService->reactivar($id, $usuarioId);
        ResponseHelper::success(null, 'Socio reactivado exitosamente.');
    }

    /**
     * DELETE /api/socios/{id} — Aplica baja lógica a un socio registrando el motivo y abriendo ventana de reversión de 7 días.
     *
     * @param array $params
     * @return void
     */
    public function delete(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $motivo = $input['motivo'] ?? '';
        if (empty(trim($motivo))) {
            ResponseHelper::error('El motivo de la baja es obligatorio.', 400);
            return;
        }

        $this->socioService->eliminar($id, $motivo, $usuarioId);
        ResponseHelper::success(null, 'Socio dado de baja exitosamente.');
    }

    /**
     * POST /api/socios/{id}/revertir — Revierte la baja lógica de un socio si no han transcurrido más de 7 días.
     *
     * @param array $params
     * @return void
     */
    public function revertDelete(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $id = $params['id'] ?? '';
        if (empty($id)) {
            ResponseHelper::error('El ID del socio es obligatorio.', 400);
            return;
        }

        $this->socioService->revertirEliminacion($id, $usuarioId);
        ResponseHelper::success(null, 'Baja de socio revertida exitosamente.');
    }

    /**
     * POST /api/socios/{id}/geolocalizacion — Permite al administrador corregir manualmente las coordenadas y recalcular la zona asignada.
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

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!isset($input['lat']) || !isset($input['lng'])) {
            ResponseHelper::error('Los campos "lat" y "lng" son obligatorios.', 400);
            return;
        }

        $lat = (float)$input['lat'];
        $lng = (float)$input['lng'];

        $this->socioService->corregirGeolocalizacion($id, $lat, $lng, $usuarioId);
        ResponseHelper::success(null, 'Geolocalización corregida exitosamente.');
    }

    /**
     * POST /api/socios/importar — Procesa la carga masiva de socios desde un archivo CSV con georreferenciación y reporte de inconsistencias.
     *
     * @param array $params
     * @return void
     */
    public function importarCSV(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $session = $this->authService->checkSession();
        $usuarioId = $session['usuario_id'];

        $fileField = isset($_FILES['archivo']) ? 'archivo' : (isset($_FILES['file']) ? 'file' : null);

        if ($fileField === null || $_FILES[$fileField]['error'] !== UPLOAD_ERR_OK) {
            ResponseHelper::error('Se requiere subir un archivo CSV válido en el campo "archivo".', 400);
            return;
        }

        $originalName = $_FILES[$fileField]['name'] ?? '';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            ResponseHelper::error('El archivo debe tener extensión .csv.', 400);
            return;
        }

        // Límite de tamaño: 5 MB
        $maxBytes = 5 * 1024 * 1024;
        if ($_FILES[$fileField]['size'] > $maxBytes) {
            ResponseHelper::error('El archivo excede el tamaño máximo permitido de 5 MB.', 400);
            return;
        }

        $tmpPath = $_FILES[$fileField]['tmp_name'];
        if (!is_uploaded_file($tmpPath)) {
            ResponseHelper::error('El archivo cargado no es válido.', 400);
            return;
        }

        // Validar MIME type real del contenido
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        $allowedMimes = [
            'text/plain',
            'text/csv',
            'text/x-csv',
            'application/csv',
            'application/x-csv',
            'application/vnd.ms-excel',
            'application/vnd.msexcel',
            'text/comma-separated-values'
        ];

        $isTextMime = str_starts_with($mime, 'text/') || in_array($mime, $allowedMimes, true);
        // Compatibilidad con Windows/Excel para application/octet-stream si el contenido es texto plano (sin bytes nulos)
        if (!$isTextMime && $mime === 'application/octet-stream') {
            $sample = file_get_contents($tmpPath, false, null, 0, 1024);
            if ($sample !== false && !str_contains($sample, "\0")) {
                $isTextMime = true;
            }
        }

        if (!$isTextMime) {
            ResponseHelper::error('El formato del archivo no corresponde a un archivo de texto CSV.', 400);
            return;
        }

        $importacionId = Uuid::uuid4()->toString();
        $importsDir = dirname(__DIR__, 2) . '/storage/imports';
        if (!is_dir($importsDir)) {
            mkdir($importsDir, 0755, true);
        }

        $destPath = $importsDir . '/' . $importacionId . '.csv';
        if (!move_uploaded_file($tmpPath, $destPath)) {
            ResponseHelper::error('No se pudo guardar el archivo cargado en el servidor.', 500);
            return;
        }

        $totalFilas = $this->socioService->contarFilasCSV($destPath);
        if ($totalFilas === 0) {
            if (file_exists($destPath)) {
                unlink($destPath);
            }
            ResponseHelper::error('El archivo CSV está vacío o no contiene filas con datos válidos.', 400);
            return;
        }

        // Registrar la importación en la base de datos
        $this->socioService->registrarImportacion([
            'id'               => $importacionId,
            'usuario_id'       => $usuarioId,
            'archivo_original' => $originalName,
            'archivo_ruta'     => $destPath,
            'estado'           => 'pendiente',
            'total_filas'      => $totalFilas,
        ]);

        // Determinar ejecutable de PHP y ruta del script worker
        $phpCli = Config::get('PHP_CLI_PATH');
        if (empty($phpCli)) {
            $defaultPhp = 'C:\\xampp\\php\\php.exe';
            $phpCli = file_exists($defaultPhp) ? $defaultPhp : 'php';
        }

        $scriptPath = realpath(dirname(__DIR__, 2) . '/scripts/importar_csv_worker.php');

        // Disparar worker en segundo plano desacoplado del ciclo de vida HTTP
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = 'start /B "" "' . $phpCli . '" "' . $scriptPath . '" ' . escapeshellarg($importacionId) . ' > NUL 2>&1';
            pclose(popen($cmd, 'r'));
        } else {
            $cmd = '"' . $phpCli . '" "' . $scriptPath . '" ' . escapeshellarg($importacionId) . ' > /dev/null 2>&1 &';
            exec($cmd);
        }

        ResponseHelper::success([
            'id'               => $importacionId,
            'estado'           => 'pendiente',
            'total_filas'      => $totalFilas,
            'archivo_original' => $originalName,
        ], 'Importación iniciada en segundo plano.', 202);
    }

    /**
     * GET /api/socios/importar/{id}/estado — Consulta el progreso y estado actual de una importación CSV (admin-only).
     *
     * @param array $params
     * @return void
     */
    public function getEstadoImportacion(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');
        $id = $params['id'] ?? '';
        $estado = $this->socioService->getEstadoImportacion($id);

        if (!$estado) {
            ResponseHelper::error('Importación no encontrada.', 404);
            return;
        }

        ResponseHelper::success($estado, 'Estado de importación obtenido con éxito.');
    }

    /**
     * GET /api/socios/importar/activa — Consulta si existe una importación actualmente en curso (admin-only).
     *
     * @param array $params
     * @return void
     */
    public function getImportacionActiva(array $params = []): void
    {
        AuthMiddleware::requireAuth('administrador');
        $activa = $this->socioService->getImportacionActiva();
        ResponseHelper::success($activa, $activa ? 'Importación activa encontrada.' : 'No hay importaciones en curso.');
    }

    /**
     * GET /api/socios/inconsistencias — Consulta la lista de inconsistencias detectadas en importaciones CSV (con filtro opcional por estado).
     *
     * @param array $params
     * @return void
     */
    public function getInconsistencias(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');

        $filtros = [
            'estado' => $_GET['estado'] ?? null,
        ];

        $items = $this->socioService->getInconsistencias($filtros);
        ResponseHelper::success($items, 'Inconsistencias obtenidas correctamente.');
    }

    /**
     * GET /api/socios/{id}/qr — Sirve la imagen PNG del código QR del socio o la regenera bajo demanda si no existe en storage.
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
            // Intentar generar el código QR bajo demanda si el socio existe pero no tenía imagen previa
            $this->socioService->obtener($id);
            $this->socioService->generarQR($id);
        }

        if (file_exists($path)) {
            header('Content-Type: image/png');
            readfile($path);
            exit;
        }

        ResponseHelper::error('Código QR no encontrado.', 404);
    }
}
