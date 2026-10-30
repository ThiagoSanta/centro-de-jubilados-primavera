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

        // Métrica 1: Total de socios actualmente en estado 'activo'
        $stmt = $db->prepare("SELECT COUNT(*) FROM socios WHERE estado = 'activo'");
        $stmt->execute();
        $sociosActivos = (int) $stmt->fetchColumn();

        // Métrica 2: Socios activos con al menos 1 cuota en estado 'pendiente'.
        // Se usa COUNT DISTINCT para no duplicar socios con varias deudas.
        // Solo se incluyen socios 'activo' para que la tarjeta y la lista del padrón muestren el mismo número.
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT s.id)
             FROM socios s
             JOIN deudas d ON d.socio_id = s.id AND d.estado = 'pendiente'
             WHERE s.estado = 'activo'"
        );
        $stmt->execute();
        $sociosConDeuda = (int) $stmt->fetchColumn();

        // Métrica 3: Suma el monto adeudado solo de socios activos para que coincida con el criterio de la tarjeta.
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(d.monto), 0)
             FROM deudas d
             JOIN socios s ON d.socio_id = s.id AND s.estado = 'activo'
             WHERE d.estado = 'pendiente'"
        );
        $stmt->execute();
        $montoAdeudadoTotal = (float) $stmt->fetchColumn();

        // Métrica 4: Total recaudado a través de pagos registrados durante el mes calendario en curso
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM pagos
             WHERE estado = 'registrado'
               AND YEAR(fecha_hora) = YEAR(CURDATE())
               AND MONTH(fecha_hora) = MONTH(CURDATE())"
        );
        $stmt->execute();
        $pagosDelMes = (int) $stmt->fetchColumn();

        // Métrica 5: Total recaudado en el día de la fecha (rendición diaria de cobradores y pagos en sede)
        $stmt = $db->prepare(
            "SELECT COUNT(*)
             FROM pagos
             WHERE estado = 'registrado'
               AND DATE(fecha_hora) = CURDATE()"
        );
        $stmt->execute();
        $cobranzasHoy = (int) $stmt->fetchColumn();

        // Métrica 6: Contador de alertas y notificaciones pendientes de lectura para el usuario administrador
        $stmt = $db->prepare("SELECT COUNT(*) FROM notificaciones WHERE estado = 'no_leida'");
        $stmt->execute();
        $notificacionesSinLeer = (int) $stmt->fetchColumn();

        // Métrica 7: Alerta de deuda del mes en curso
        // Se evalúa si ya existe al menos una deuda registrada para el período mensual actual ('YYYY-MM')
        $periodoActual = (new \DateTimeImmutable())->format('Y-m');
        $stmt = $db->prepare("SELECT COUNT(*) FROM deudas WHERE periodo = :periodo LIMIT 1");
        $stmt->execute(['periodo' => $periodoActual]);
        $deudaMesGenerada = ((int) $stmt->fetchColumn()) > 0;

        ResponseHelper::success([
            'socios_activos'           => $sociosActivos,
            'socios_con_deuda'         => $sociosConDeuda,
            'monto_adeudado_total'     => $montoAdeudadoTotal,
            'pagos_del_mes'            => $pagosDelMes,
            'cobranzas_hoy'            => $cobranzasHoy,
            'notificaciones_sin_leer'  => $notificacionesSinLeer,
            'deuda_mes_generada'       => $deudaMesGenerada,
            'deuda_mes_periodo'        => $periodoActual,
        ], 'Métricas del dashboard obtenidas correctamente.');
    }
}
