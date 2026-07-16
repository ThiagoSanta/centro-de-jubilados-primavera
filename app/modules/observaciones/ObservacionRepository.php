<?php

namespace CJP\Modules\Observaciones;

use CJP\Config\Database;
use PDO;

class ObservacionRepository
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findBySocio(string $socioId): array
    {
        $stmt = $this->db->prepare("SELECT o.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido FROM observaciones o LEFT JOIN usuarios u ON o.usuario_id = u.id WHERE o.socio_id = :socio_id ORDER BY o.fecha DESC");
        $stmt->execute([':socio_id' => $socioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $datos): string
    {
        $query = "INSERT INTO observaciones (id, socio_id, fecha, usuario_id, contenido) 
                  VALUES (UUID(), :socio_id, NOW(), :usuario_id, :contenido)";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':socio_id' => $datos['socio_id'],
            ':usuario_id' => $datos['usuario_id'],
            ':contenido' => $datos['contenido']
        ]);

        $stmtId = $this->db->query("SELECT id FROM observaciones WHERE socio_id = '{$datos['socio_id']}' ORDER BY fecha DESC LIMIT 1");
        return $stmtId->fetchColumn();
    }

    public function registerAuditEvent(string $usuarioId, string $accion, string $entidad, ?string $valorAnterior, ?string $valorNuevo, string $motivo): void
    {
        $query = "INSERT INTO auditoria (id, usuario_id, accion, entidad_afectada, valor_anterior, valor_nuevo, fecha_hora, motivo) 
                  VALUES (UUID(), :usuario_id, :accion, :entidad, :valor_anterior, :valor_nuevo, NOW(), :motivo)";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':accion' => $accion,
            ':entidad' => $entidad,
            ':valor_anterior' => $valorAnterior,
            ':valor_nuevo' => $valorNuevo,
            ':motivo' => $motivo
        ]);
    }
}
