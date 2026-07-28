<?php

namespace CJP\Modules\Pagos;

use PDO;
use CJP\Config\Database;
use Exception;
use PDOException;
use Ramsey\Uuid\Uuid;

class PagoRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(array $datos): string
    {
        $id = Uuid::uuid4()->toString();
        
        $sql = "INSERT INTO pagos (id, socio_id, fecha_hora, monto_total, metodo_pago, estado, observacion, usuario_id) 
                VALUES (:id, :socio_id, :fecha_hora, :monto_total, :metodo_pago, 'registrado', :observacion, :usuario_id)";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'socio_id' => $datos['socio_id'],
            'fecha_hora' => $datos['fecha_hora'] ?? date('Y-m-d H:i:s'),
            'monto_total' => $datos['monto_total'],
            'metodo_pago' => $datos['metodo_pago'],
            'observacion' => $datos['observacion'] ?? null,
            'usuario_id' => $datos['usuario_id'] ?? null
        ]);

        return $id;
    }

    public function asociarDeudas(string $pagoId, array $deudaIds): void
    {
        $sql = "INSERT INTO pago_deuda (pago_id, deuda_id) VALUES (:pago_id, :deuda_id)";
        $stmt = $this->db->prepare($sql);
        
        foreach ($deudaIds as $deudaId) {
            $stmt->execute([
                'pago_id' => $pagoId,
                'deuda_id' => $deudaId
            ]);
        }
    }

    public function findById(string $id): ?array
    {
        $sql = "SELECT p.*, s.nombre_apellido as socio_nombre, s.numero_socio 
                FROM pagos p 
                JOIN socios s ON p.socio_id = s.id 
                WHERE p.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: null;
    }

    public function findBySocio(string $socioId): array
    {
        $sql = "SELECT * FROM pagos WHERE socio_id = :socio_id ORDER BY fecha_hora DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['socio_id' => $socioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAll(array $filtros, int $pagina): array
    {
        $limit = 25;
        $offset = ($pagina - 1) * $limit;
        
        $where = [];
        $params = [];
        
        if (!empty($filtros['socio_id'])) {
            $where[] = "p.socio_id = :socio_id";
            $params['socio_id'] = $filtros['socio_id'];
        }
        if (!empty($filtros['estado'])) {
            $where[] = "p.estado = :estado";
            $params['estado'] = $filtros['estado'];
        }
        if (!empty($filtros['fecha_desde'])) {
            $where[] = "DATE(p.fecha_hora) >= :fecha_desde";
            $params['fecha_desde'] = $filtros['fecha_desde'];
        }
        if (!empty($filtros['fecha_hasta'])) {
            $where[] = "DATE(p.fecha_hora) <= :fecha_hasta";
            $params['fecha_hasta'] = $filtros['fecha_hasta'];
        }
        
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        $sql = "SELECT p.*, s.nombre_apellido as socio_nombre, s.numero_socio, u.nombre as cobrador_nombre, u.apellido as cobrador_apellido 
                FROM pagos p 
                JOIN socios s ON p.socio_id = s.id 
                LEFT JOIN usuarios u ON p.usuario_id = u.id
                $whereClause 
                ORDER BY p.fecha_hora DESC 
                LIMIT $limit OFFSET $offset";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sqlCount = "SELECT COUNT(*) FROM pagos p $whereClause";
        $stmtCount = $this->db->prepare($sqlCount);
        $stmtCount->execute($params);
        $total = $stmtCount->fetchColumn();
        
        return [
            'data' => $data,
            'total' => $total,
            'paginas' => ceil($total / $limit)
        ];
    }

    public function anular(string $id): void
    {
        $sql = "UPDATE pagos SET estado = 'anulado' WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
    }

    public function createNotificacion(array $datos): void
    {
        $sql = "INSERT INTO notificaciones (id, tipo, mensaje, fecha, estado, referencia, fecha_expiracion_reversion) 
                VALUES (:id, :tipo, :mensaje, :fecha, 'pendiente', :referencia, :fecha_expiracion_reversion)";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => Uuid::uuid4()->toString(),
            'tipo' => $datos['tipo'],
            'mensaje' => $datos['mensaje'],
            'fecha' => date('Y-m-d H:i:s'),
            'referencia' => isset($datos['referencia']) ? json_encode($datos['referencia']) : null,
            'fecha_expiracion_reversion' => $datos['fecha_expiracion_reversion'] ?? null
        ]);
    }

    public function registerAuditEvent(string $entidad, string $entidadId, string $accion, ?string $usuarioId, array $detalles = []): void
    {
        $sql = "INSERT INTO auditoria (id, usuario_id, accion, entidad_afectada, valor_anterior, valor_nuevo, fecha_hora, motivo) 
                VALUES (:id, :usuario_id, :accion, :entidad_afectada, :valor_anterior, :valor_nuevo, NOW(), :motivo)";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => Uuid::uuid4()->toString(),
            'usuario_id' => $usuarioId,
            'accion' => $accion,
            'entidad_afectada' => $entidad,
            'valor_anterior' => null,
            'valor_nuevo' => !empty($detalles) ? json_encode($detalles) : null,
            'motivo' => $detalles['motivo'] ?? null
        ]);
    }

    public function getDeudaIdsByPago(string $pagoId): array
    {
        $sql = "SELECT deuda_id FROM pago_deuda WHERE pago_id = :pago_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['pago_id' => $pagoId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
