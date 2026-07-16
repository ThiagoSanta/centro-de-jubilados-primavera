<?php

namespace CJP\Modules\Auditoria;

use CJP\Config\Database;
use PDO;

class AuditoriaRepository
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function findAll(array $filtros, int $pagina): array
    {
        $limit = 25;
        $offset = ($pagina - 1) * $limit;

        $query = "SELECT a.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido 
                  FROM auditoria a 
                  LEFT JOIN usuarios u ON a.usuario_id = u.id 
                  WHERE 1=1";
        $params = [];

        if (!empty($filtros['usuario_id'])) {
            $query .= " AND a.usuario_id = :usuario_id";
            $params[':usuario_id'] = $filtros['usuario_id'];
        }
        if (!empty($filtros['accion'])) {
            $query .= " AND a.accion = :accion";
            $params[':accion'] = $filtros['accion'];
        }
        if (!empty($filtros['entidad_afectada'])) {
            $query .= " AND a.entidad_afectada = :entidad_afectada";
            $params[':entidad_afectada'] = $filtros['entidad_afectada'];
        }
        if (!empty($filtros['fecha_desde'])) {
            $query .= " AND a.fecha_hora >= :fecha_desde";
            $params[':fecha_desde'] = $filtros['fecha_desde'] . ' 00:00:00';
        }
        if (!empty($filtros['fecha_hasta'])) {
            $query .= " AND a.fecha_hora <= :fecha_hasta";
            $params[':fecha_hasta'] = $filtros['fecha_hasta'] . ' 23:59:59';
        }

        // Get total for pagination
        $countQuery = str_replace("SELECT a.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido", "SELECT COUNT(*)", $query);
        $stmtCount = $this->db->prepare($countQuery);
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();

        $query .= " ORDER BY a.fecha_hora DESC LIMIT $limit OFFSET $offset";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'paginas' => ceil($total / $limit)
        ];
    }

    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT a.*, u.nombre as usuario_nombre, u.apellido as usuario_apellido FROM auditoria a LEFT JOIN usuarios u ON a.usuario_id = u.id WHERE a.id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
