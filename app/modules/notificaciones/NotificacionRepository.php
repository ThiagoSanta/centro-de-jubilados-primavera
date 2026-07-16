<?php

namespace CJP\Modules\Notificaciones;

use CJP\Config\Database;
use PDO;

class NotificacionRepository
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findAll(array $filtros): array
    {
        $query = "SELECT * FROM notificaciones WHERE 1=1";
        $params = [];

        if (!empty($filtros['estado'])) {
            $query .= " AND estado = :estado";
            $params[':estado'] = $filtros['estado'];
        }

        $query .= " ORDER BY fecha DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM notificaciones WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function updateEstado(string $id, string $estado): void
    {
        $stmt = $this->db->prepare("UPDATE notificaciones SET estado = :estado WHERE id = :id");
        $stmt->execute([':estado' => $estado, ':id' => $id]);
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
