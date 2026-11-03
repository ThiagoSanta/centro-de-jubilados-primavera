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
        AuthMiddleware::requireAuth('administrador');

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
            'busqueda'    => $_GET['busqueda'] ?? ($_GET['socio_id'] ?? null),
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
        // 1. Exigir sesión activa de usuario
        AuthMiddleware::requireAuth();

        $id = $params['id'] ?? null;

        // 2. Validación estricta del parámetro: nunca aceptar rutas ni caracteres de escape
        if (empty($id) || !is_string($id) || !preg_match('/^[a-zA-Z0-9\-_]+$/', $id)) {
            ResponseHelper::error('Comprobante no encontrado.', 404);
            return;
        }

        // 3. Verificar existencia del pago en la base de datos
        try {
            $pago = $this->pagoService->getPago($id);
        } catch (\Throwable $e) {
            ResponseHelper::error('Pago no encontrado.', 404);
            return;
        }

        if (!$pago) {
            ResponseHelper::error('Pago no encontrado.', 404);
            return;
        }

        // 4. Control de acceso sin IDOR: cobrador solo puede descargar comprobantes de sus propios cobros
        if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'cobrador') {
            if (($pago['usuario_id'] ?? null) !== ($_SESSION['usuario_id'] ?? null)) {
                ResponseHelper::error('No tienes permiso para acceder a este comprobante.', 403);
                return;
            }
        }

        // 5. Obtener archivo o regenerarlo si falta físicamente en storage
        $filepath = $this->pagoService->obtenerArchivoComprobante($id);

        if (!$filepath || !file_exists($filepath)) {
            ResponseHelper::error('Comprobante no encontrado.', 404);
            return;
        }

        // 6. Servir el archivo PDF como descarga adjunta con nombre normalizado
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="comprobante-' . $id . '.pdf"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    }
}
