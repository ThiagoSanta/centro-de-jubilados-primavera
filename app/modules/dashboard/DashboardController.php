<?php

namespace CJP\Modules\Dashboard;

use CJP\Config\Database;
use CJP\Shared\AuthMiddleware;
use CJP\Shared\Helpers\ResponseHelper;
use PDO;

class DashboardController
{
    /**
     * GET /api/dashboard/metricas
     * Retorna métricas del sistema para el panel de control.
     * Requiere rol administrador.
     *
     * @param array $params
     * @return void
     */
    public function metricas(array $params = []): void
    {
        AuthMiddleware::requireAuth('administrador');

        $db = Database::getInstance();

        // Socios activos
        $stmt = $db->prepare("SELECT COUNT(*) FROM socios WHERE estado = 'activo'");
        $stmt->execute();
        $sociosActivos = (int) $stmt->fetchColumn();

        // Socios con 2 o más deudas pendientes
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT s.id)
             FROM socios s
             JOIN deudas d ON d.socio_id = s.id AND d.estado = 'pendiente'
             WHERE s.estado = 'activo'
             GROUP BY s.id
             HAVING COUNT(d.id) >= 2"
        );
        $stmt->execute();
        $sociosConDeuda = (int) $stmt->rowCount();

        // Re-ejecutar correctamente con subquery para COUNT DISTINCT
        $stmt2 = $db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT s.id
                FROM socios s
                JOIN deudas d ON d.socio_id = s.id AND d.estado = 'pendiente'
                WHERE s.estado = 'activo'
                GROUP BY s.id
                HAVING COUNT(d.id) >= 2
             ) AS sub"
        );
        $stmt2->execute();
        $sociosConDeuda = (int) $stmt2->fetchColumn();

        // Monto total adeudado
        $stmt = $db->prepare("SELECT COALESCE(SUM(monto), 0) FROM deudas WHERE estado = 'pendiente'");
        $stmt->execute();
        $montoAdeudadoTotal = (float) $stmt->fetchColumn();

        // Pagos del mes actual
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM pagos
             WHERE estado = 'registrado'
               AND YEAR(fecha_hora) = YEAR(CURDATE())
               AND MONTH(fecha_hora) = MONTH(CURDATE())"
        );
        $stmt->execute();
        $pagosDelMes = (int) $stmt->fetchColumn();

        // Cobranzas de hoy
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM pagos
             WHERE estado = 'registrado'
               AND DATE(fecha_hora) = CURDATE()"
        );
        $stmt->execute();
        $cobranzasHoy = (int) $stmt->fetchColumn();

        // Notificaciones sin leer
        $stmt = $db->prepare("SELECT COUNT(*) FROM notificaciones WHERE estado = 'no_leida'");
        $stmt->execute();
        $notificacionesSinLeer = (int) $stmt->fetchColumn();

        ResponseHelper::json([
            'success' => true,
            'data'    => [
                'socios_activos'           => $sociosActivos,
                'socios_con_deuda'         => $sociosConDeuda,
                'monto_adeudado_total'     => $montoAdeudadoTotal,
                'pagos_del_mes'            => $pagosDelMes,
                'cobranzas_hoy'            => $cobranzasHoy,
                'notificaciones_sin_leer'  => $notificacionesSinLeer,
            ],
        ]);
    }
}
