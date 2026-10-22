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
        // dirname(__DIR__, 3): desde app/modules/socios sube tres niveles hasta la raiz del proyecto
        $importsDir = dirname(__DIR__, 3) . '/storage/imports';
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

        // dirname(__DIR__, 3): scripts/ esta en la raiz del proyecto, tres niveles arriba de app/modules/socios/
        $scriptPath = realpath(dirname(__DIR__, 3) . '/scripts/importar_csv_worker.php');

        // Validar que el ejecutable PHP existe en disco antes de intentar usarlo.
        // Si PHP_CLI_PATH apunta a un binario distinto al de Apache (caso windowsXamppPhp),
        // file_exists detecta de inmediato si la ruta es incorrecta o el archivo fue movido.
        if (!file_exists($phpCli)) {
            $motivo = 'El ejecutable PHP CLI configurado no existe en disco: ' . $phpCli;
            error_log('[worker-importacion] ' . $motivo . ' (importacion_id=' . $importacionId . ')');
            $this->socioService->marcarImportacionError($importacionId, $motivo);
            ResponseHelper::error('No se pudo iniciar la importacion: el ejecutable PHP no fue encontrado.', 500);
            return;
        }

        // Validar que el script worker existe en disco.
        // realpath() devuelve false si la ruta no existe; capturarlo aqui evita armar un
        // comando con la cadena literal "false" como ruta.
        if ($scriptPath === false || !file_exists($scriptPath)) {
            $motivo = 'El script worker no existe en la ruta esperada: scripts/importar_csv_worker.php';
            error_log('[worker-importacion] ' . $motivo . ' (importacion_id=' . $importacionId . ')');
            $this->socioService->marcarImportacionError($importacionId, $motivo);
            ResponseHelper::error('No se pudo iniciar la importacion: el script worker no fue encontrado.', 500);
            return;
        }

        // Determinar el php.ini que debe usar el worker.
        // El binario CLI configurado en PHP_CLI_PATH puede diferir del PHP de Apache y no
        // leer automaticamente el php.ini de XAMPP. Sin ese ini, extensiones como pdo_mysql
        // no se cargan y el worker falla silenciosamente al intentar conectar a la BD.
        // Estrategia de resolucion (en orden de prioridad):
        //   1. php_ini_loaded_file(): devuelve la ruta del ini que cargo el proceso HTTP actual.
        //      Si Apache cargo C:\xampp\php\php.ini, ese mismo ini es el correcto para el worker.
        //   2. Si lo anterior devuelve false, buscar php.ini en el directorio padre del ejecutable CLI
        //      (ej. C:\xampp\php\php.ini cuando el binario esta en C:\xampp\php\windowsXamppPhp\php.exe).
        //   3. Si ninguno de los anteriores existe, lanzar sin -c y registrar una advertencia.
        $phpIniArg = '';
        $iniDetectado = php_ini_loaded_file();
        if ($iniDetectado !== false && file_exists($iniDetectado)) {
            // Caso 1: usar el mismo ini que cargo el proceso Apache actual
            $phpIniArg = ' -c "' . $iniDetectado . '"';
        } else {
            // Caso 2: buscar php.ini en el directorio padre del ejecutable CLI
            $iniCandidato = dirname(dirname($phpCli)) . DIRECTORY_SEPARATOR . 'php.ini';
            if (file_exists($iniCandidato)) {
                $phpIniArg = ' -c "' . $iniCandidato . '"';
            } else {
                // Caso 3: no hay ini conocido; el worker puede arrancar sin pdo_mysql
                error_log(
                    '[worker-importacion] Advertencia: no se pudo determinar php.ini para el worker CLI. '
                    . 'El worker arrancara sin -c; es posible que falten extensiones (pdo_mysql, curl). '
                    . 'Importacion ID: ' . $importacionId
                );
            }
        }

        // Preparar directorio y ruta del archivo de log del worker.
        // La salida (stdout + stderr) se acumula en worker.log en lugar de descartarse a NUL,
        // lo que permite diagnosticar errores fatales del proceso hijo (extensiones faltantes,
        // errores de conexion, etc.) que de otro modo serian invisibles.
        // dirname(__DIR__, 3): storage/ esta en la raiz del proyecto, tres niveles arriba de app/modules/socios/
        $logsDir = dirname(__DIR__, 3) . '/storage/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        $workerLog = $logsDir . DIRECTORY_SEPARATOR . 'worker.log';

        // Disparar worker en segundo plano desacoplado del ciclo de vida HTTP
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // En Windows se usa "start /B" para desacoplar el proceso hijo del proceso padre.
            // El titulo de ventana vacio "" es obligatorio cuando el primer argumento de start
            // es una ruta entre comillas (sin el "", cmd.exe lo interpreta como el titulo).
            // Se usa >> (modo append) para no sobreescribir entradas previas en el log.
            $cmd = 'start /B "" "' . $phpCli . '"' . $phpIniArg . ' "' . $scriptPath . '" '
                 . escapeshellarg($importacionId) . ' >> "' . $workerLog . '" 2>&1';
            $proc = popen($cmd, 'r');
        } else {
            // En Linux/Mac el proceso se desacopla con & al final.
            $cmd = '"' . $phpCli . '"' . $phpIniArg . ' "' . $scriptPath . '" '
                 . escapeshellarg($importacionId) . ' >> "' . $workerLog . '" 2>&1 &';
            $proc = popen($cmd, 'r');
        }

        // Verificar que popen pudo abrir el proceso (fallo a nivel de sistema operativo,
        // por ejemplo si cmd.exe no es accesible o el proceso padre no tiene permisos).
        // Nota: con "start /B" en Windows, popen/pclose retorna 0 inmediatamente porque
        // cmd.exe no espera al proceso hijo; no es posible detectar desde aqui si el worker
        // murio segundos despues. Para ese caso, el log en worker.log es el mecanismo de
        // diagnostico.
        if ($proc === false) {
            $motivo = 'popen() no pudo abrir el proceso para el worker (error del sistema operativo).';
            error_log('[worker-importacion] ' . $motivo . ' Comando intentado: ' . $cmd);
            $this->socioService->marcarImportacionError($importacionId, $motivo);
            ResponseHelper::error('No se pudo iniciar la importacion: error al abrir el proceso en segundo plano.', 500);
            return;
        }

        pclose($proc);

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
