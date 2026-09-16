<?php

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

use CJP\Shared\Router;
use CJP\Shared\ExceptionHandler;
use Ramsey\Uuid\Uuid;
use CJP\Shared\Helpers\ResponseHelper;
use CJP\Modules\Auth\AuthController;
use CJP\Shared\AuthMiddleware;
use CJP\Modules\Zonas\ZonaController;
use CJP\Modules\Socios\SocioController;
use CJP\Modules\Deuda\DeudaController;
use CJP\Modules\Pagos\PagoController;
use CJP\Modules\Planillas\PlanillaController;
use CJP\Modules\Notificaciones\NotificacionController;
use CJP\Modules\Auditoria\AuditoriaController;
use CJP\Modules\Historial\HistorialController;
use CJP\Modules\Observaciones\ObservacionController;
use CJP\Modules\Dashboard\DashboardController;
use CJP\Modules\Usuarios\UsuarioController;
use CJP\Modules\Backup\BackupController;

// Carga del autoloader de Composer y archivos esenciales de configuración y base de datos
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/Config.php';
require_once __DIR__ . '/../app/config/Database.php';

// Inicialización de Correlation ID por Request y Manejador Global
$correlationId = Uuid::uuid4()->toString();
ExceptionHandler::setCorrelationId($correlationId);
header("X-Correlation-ID: {$correlationId}");
set_exception_handler([ExceptionHandler::class, 'handle']);


// Si la petición apunta a la raíz del sitio, redirigir automáticamente a la pantalla de inicio de sesión
$requestUriRaw = $_SERVER['REQUEST_URI'] ?? '/';
$pathOnly = parse_url($requestUriRaw, PHP_URL_PATH);
$pathOnly = '/' . trim($pathOnly, '/');
if ($pathOnly === '/' || $pathOnly === '/public' || $pathOnly === '/public/') {
    header('Location: /views/auth/login.html');
    exit;
}

$router = new Router();

// Rutas base y de verificación de estado (health check)
$router->get('/api/ping', function () {
    AuthMiddleware::requireAuth();
    ResponseHelper::json([
        'success' => true,
        'message' => 'CJP API funcionando'
    ]);
});

$router->get('/dashboard', function () {
    $file = __DIR__ . '/views/dashboard/index.html';
    if (!file_exists($file)) {
        ResponseHelper::error('Página no encontrada', 404);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
    exit;
});

// Rutas de autenticación y control de sesión
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);

// Rutas del módulo de zonas geográficas
$router->get('/api/zonas', [ZonaController::class, 'index']);
$router->post('/api/zonas/calcular', [ZonaController::class, 'calcular']);

// Rutas de socios (el orden es crítico: registrar rutas estáticas antes de las parametrizadas para evitar colisiones)
$router->post('/api/socios/importar',       [SocioController::class, 'importarCSV']);
$router->get('/api/socios/inconsistencias', [SocioController::class, 'getInconsistencias']);
$router->get('/api/socios',                 [SocioController::class, 'index']);
$router->post('/api/socios', [SocioController::class, 'create']);
$router->get('/api/socios/{id}', [SocioController::class, 'show']);
$router->put('/api/socios/{id}', [SocioController::class, 'update']);
$router->delete('/api/socios/{id}', [SocioController::class, 'delete']);
$router->post('/api/socios/{id}/suspender', [SocioController::class, 'suspend']);
$router->post('/api/socios/{id}/reactivar', [SocioController::class, 'reactivate']);
$router->post('/api/socios/{id}/revertir', [SocioController::class, 'revertDelete']);
$router->post('/api/socios/{id}/geolocalizacion', [SocioController::class, 'corregirGeo']);
$router->get('/api/socios/{id}/qr', [SocioController::class, 'getQR']);

// Rutas de deudas y configuración de cuotas (rutas estáticas antes de las parametrizadas)
$router->get('/api/cuota/vigente', [DeudaController::class, 'getCuotaVigente']);
$router->get('/api/cuota/historico', [DeudaController::class, 'getHistoricoCuotas']);
$router->post('/api/cuota', [DeudaController::class, 'registrarCuota']);
$router->post('/api/deuda/generar', [DeudaController::class, 'generarMensual']);
$router->post('/api/deuda/anterior', [DeudaController::class, 'cargarAnterior']);
$router->get('/api/deuda/socio/{socioId}/pendientes', [DeudaController::class, 'getPendientesBySocio']);
$router->get('/api/deuda/socio/{socioId}', [DeudaController::class, 'getBySocio']);
$router->post('/api/deuda/{id}/exonerar', [DeudaController::class, 'exonerar']);

// Rutas de gestión de pagos y cobranzas (rutas estáticas antes de las parametrizadas)
$router->get('/api/pagos/socio/{socioId}', [PagoController::class, 'getBySocio']);
$router->get('/api/pagos', [PagoController::class, 'getAll']);
$router->post('/api/pagos', [PagoController::class, 'registrar']);
$router->get('/api/pagos/{id}/comprobante', [PagoController::class, 'getComprobante']);
$router->post('/api/pagos/{id}/anular', [PagoController::class, 'anular']);
$router->get('/api/pagos/{id}', [PagoController::class, 'getOne']);

// Rutas para generación y consulta de planillas de cobro
$router->get('/api/planillas/cobradores', [PlanillaController::class, 'getCobradores']);
$router->get('/api/planillas', [PlanillaController::class, 'getAll']);
$router->post('/api/planillas', [PlanillaController::class, 'generar']);
$router->get('/api/planillas/{id}/pdf', [PlanillaController::class, 'getPdf']);
$router->get('/api/planillas/{id}', [PlanillaController::class, 'getOne']);

// Rutas de notificaciones del sistema
$router->get('/api/notificaciones', [NotificacionController::class, 'getAll']);
$router->post('/api/notificaciones/{id}/leida', [NotificacionController::class, 'marcarLeida']);
$router->post('/api/notificaciones/{id}/archivar', [NotificacionController::class, 'archivar']);
$router->post('/api/notificaciones/{id}/revertir', [NotificacionController::class, 'revertir']);

// Rutas de consulta del registro de auditoría
$router->get('/api/auditoria', [AuditoriaController::class, 'getAll']);
$router->get('/api/auditoria/{id}', [AuditoriaController::class, 'getOne']);

// Rutas de consulta de la línea de tiempo e historial de socios
$router->get('/api/historial/socio/{socioId}', [HistorialController::class, 'getBySocio']);

// Rutas de observaciones y notas sobre socios
$router->get('/api/observaciones/socio/{socioId}', [ObservacionController::class, 'getBySocio']);
$router->post('/api/observaciones', [ObservacionController::class, 'agregar']);

// Rutas de métricas y estadísticas para el panel de control
$router->get('/api/dashboard/metricas', [DashboardController::class, 'metricas']);

// Rutas de generación y descarga de copias de seguridad
$router->post('/api/backup/generar', [BackupController::class, 'generar']);
$router->get('/api/backup/ultimo',   [BackupController::class, 'ultimo']);

// Rutas de administración de usuarios (rutas estáticas antes de las parametrizadas)
$router->get('/api/usuarios', [UsuarioController::class, 'getAll']);
$router->post('/api/usuarios', [UsuarioController::class, 'crear']);
$router->post('/api/usuarios/{id}/password', [UsuarioController::class, 'cambiarPassword']);
$router->post('/api/usuarios/{id}/desactivar', [UsuarioController::class, 'desactivar']);
$router->post('/api/usuarios/{id}/reactivar', [UsuarioController::class, 'reactivar']);
$router->put('/api/usuarios/{id}', [UsuarioController::class, 'editar']);
$router->get('/api/usuarios/{id}', [UsuarioController::class, 'getOne']);

// Resolver y despachar la petición HTTP actual según las rutas registradas
$router->dispatch();
