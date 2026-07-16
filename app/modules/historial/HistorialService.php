<?php

namespace CJP\Modules\Historial;

use CJP\Config\Database;
use PDO;

class HistorialService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function getBySocio(string $socioId): array
    {
        $historial = [];

        // Pagos
        $stmtPagos = $this->db->prepare("SELECT id, fecha_hora as fecha, monto_total, metodo_pago, estado FROM pagos WHERE socio_id = :socio_id");
        $stmtPagos->execute([':socio_id' => $socioId]);
        while ($row = $stmtPagos->fetch(PDO::FETCH_ASSOC)) {
            $historial[] = [
                'tipo' => 'pago',
                'fecha' => $row['fecha'],
                'descripcion' => "Pago registrado por $" . number_format($row['monto_total'], 2) . " via " . $row['metodo_pago'] . " (" . $row['estado'] . ")",
                'datos' => $row
            ];
        }

        // Deudas
        $stmtDeudas = $this->db->prepare("SELECT id, periodo, monto, estado, fecha_generacion as fecha FROM deudas WHERE socio_id = :socio_id");
        $stmtDeudas->execute([':socio_id' => $socioId]);
        while ($row = $stmtDeudas->fetch(PDO::FETCH_ASSOC)) {
            $historial[] = [
                'tipo' => 'deuda',
                'fecha' => $row['fecha'],
                'descripcion' => "Deuda generada para el período " . $row['periodo'] . " por $" . number_format($row['monto'], 2) . " (" . $row['estado'] . ")",
                'datos' => $row
            ];
        }

        // Observaciones
        $stmtObs = $this->db->prepare("SELECT o.id, o.fecha, o.contenido, u.nombre, u.apellido FROM observaciones o LEFT JOIN usuarios u ON o.usuario_id = u.id WHERE o.socio_id = :socio_id");
        $stmtObs->execute([':socio_id' => $socioId]);
        while ($row = $stmtObs->fetch(PDO::FETCH_ASSOC)) {
            $historial[] = [
                'tipo' => 'observacion',
                'fecha' => $row['fecha'],
                'descripcion' => "Observación añadida por " . $row['nombre'] . " " . $row['apellido'] . ": " . $row['contenido'],
                'datos' => $row
            ];
        }

        // Auditoría
        $stmtAud = $this->db->prepare("SELECT id, accion, valor_anterior, valor_nuevo, fecha_hora as fecha FROM auditoria WHERE entidad_afectada = 'socios' AND (valor_anterior LIKE :like_id OR valor_nuevo LIKE :like_id)");
        $likeId = "%" . $socioId . "%";
        $stmtAud->execute([':like_id' => $likeId]);
        while ($row = $stmtAud->fetch(PDO::FETCH_ASSOC)) {
            // Verify if socio_id is indeed in the JSON to avoid false positives (though LIKE is usually enough with UUIDs)
            $historial[] = [
                'tipo' => 'auditoria',
                'fecha' => $row['fecha'],
                'descripcion' => "Evento de auditoría: " . $row['accion'],
                'datos' => $row
            ];
        }

        usort($historial, function ($a, $b) {
            return strtotime($b['fecha']) - strtotime($a['fecha']);
        });

        return $historial;
    }
}
