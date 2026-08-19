<?php

namespace CJP\Modules\Planillas;

use PDO;
use Exception;
use CJP\Config\Database;
use Ramsey\Uuid\Uuid;

class PlanillaRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function create(array $datos): string
    {
        $id = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );

        $sql = "INSERT INTO planillas (id, fecha_generacion, cobrador_id, zona_id, pdf_generado) 
                VALUES (:id, :fecha_generacion, :cobrador_id, :zona_id, :pdf_generado)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':fecha_generacion' => $datos['fecha_generacion'],
            ':cobrador_id' => $datos['cobrador_id'],
            ':zona_id' => $datos['zona_id'],
            ':pdf_generado' => $datos['pdf_generado'] ?? null
        ]);

        return $id;
    }

    public function insertSocios(string $planillaId, array $socios): void
    {
        $sql = "INSERT INTO planilla_socio (planilla_id, socio_id, orden, deuda_snapshot) 
                VALUES (:planilla_id, :socio_id, :orden, :deuda_snapshot)";
        $stmt = $this->db->prepare($sql);

        foreach ($socios as $socio) {
            $stmt->execute([
                ':planilla_id' => $planillaId,
                ':socio_id' => $socio['socio_id'],
                ':orden' => $socio['orden'],
                ':deuda_snapshot' => $socio['deuda_snapshot']
            ]);
        }
    }

    public function findAll(array $filtros, int $pagina): array
    {
        $limite = 25;
        $offset = ($pagina - 1) * $limite;

        $where = ["1 = 1"];
        $params = [];

        if (!empty($filtros['zona_id'])) {
            $where[] = "p.zona_id = :zona_id";
            $params[':zona_id'] = $filtros['zona_id'];
        }

        if (!empty($filtros['cobrador_id'])) {
            $where[] = "p.cobrador_id = :cobrador_id";
            $params[':cobrador_id'] = $filtros['cobrador_id'];
        }

        if (!empty($filtros['fecha_desde'])) {
            $where[] = "DATE(p.fecha_generacion) >= :fecha_desde";
            $params[':fecha_desde'] = $filtros['fecha_desde'];
        }

        if (!empty($filtros['fecha_hasta'])) {
            $where[] = "DATE(p.fecha_generacion) <= :fecha_hasta";
            $params[':fecha_hasta'] = $filtros['fecha_hasta'];
        }

        $whereStr = implode(" AND ", $where);

        $sql = "SELECT p.*, z.nombre as zona_nombre, u.nombre as cobrador_nombre, u.apellido as cobrador_apellido,
                (SELECT COUNT(*) FROM planilla_socio ps WHERE ps.planilla_id = p.id) as cantidad_socios
                FROM planillas p
                LEFT JOIN zonas z ON p.zona_id = z.id
                LEFT JOIN usuarios u ON p.cobrador_id = u.id
                WHERE $whereStr
                ORDER BY p.fecha_generacion DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sqlCount = "SELECT COUNT(*) FROM planillas p WHERE $whereStr";
        $stmtCount = $this->db->prepare($sqlCount);
        foreach ($params as $k => $v) {
            $stmtCount->bindValue($k, $v);
        }
        $stmtCount->execute();
        $total = $stmtCount->fetchColumn();

        return [
            'data' => $items,
            'total' => $total,
            'paginas' => ceil($total / $limite),
            'pagina_actual' => $pagina
        ];
    }

    public function findById(string $id): ?array
    {
        $sql = "SELECT p.*, z.nombre as zona_nombre, u.nombre as cobrador_nombre, u.apellido as cobrador_apellido
                FROM planillas p
                LEFT JOIN zonas z ON p.zona_id = z.id
                LEFT JOIN usuarios u ON p.cobrador_id = u.id
                WHERE p.id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $planilla = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$planilla) {
            return null;
        }

        $sqlSocios = "SELECT ps.*, s.numero_socio, s.nombre_apellido, s.direccion, s.latitud, s.longitud, s.geolocalizacion_pendiente
                      FROM planilla_socio ps
                      JOIN socios s ON ps.socio_id = s.id
                      WHERE ps.planilla_id = :planilla_id
                      ORDER BY ps.orden ASC";
        $stmtSocios = $this->db->prepare($sqlSocios);
        $stmtSocios->execute([':planilla_id' => $id]);
        $planilla['socios'] = $stmtSocios->fetchAll(PDO::FETCH_ASSOC);

        return $planilla;
    }

    public function getSociosConDeudaDomiciliaria(string $zonaId): array
    {
        $sql = "SELECT s.id as socio_id, s.numero_socio, s.nombre_apellido, s.direccion, 
                       s.latitud, s.longitud, s.geolocalizacion_pendiente,
                       d.id as deuda_id, d.periodo, d.monto as deuda_monto
                FROM socios s
                JOIN deudas d ON s.id = d.socio_id
                WHERE s.zona_id = :zona_id 
                  AND s.estado = 'activo'
                  AND s.modalidad_cobranza = 'cobranza_domiciliaria'
                  AND d.estado = 'pendiente'
                ORDER BY s.id, d.periodo ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':zona_id' => $zonaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sociosMap = [];
        foreach ($rows as $row) {
            $socioId = $row['socio_id'];
            if (!isset($sociosMap[$socioId])) {
                $sociosMap[$socioId] = [
                    'socio_id' => $socioId,
                    'numero_socio' => $row['numero_socio'],
                    'nombre_apellido' => $row['nombre_apellido'],
                    'direccion' => $row['direccion'],
                    'latitud' => $row['latitud'],
                    'longitud' => $row['longitud'],
                    'geolocalizacion_pendiente' => $row['geolocalizacion_pendiente'],
                    'deuda_total' => 0.0,
                    'deudas' => []
                ];
            }
            $sociosMap[$socioId]['deuda_total'] += (float)$row['deuda_monto'];
            $sociosMap[$socioId]['deudas'][] = [
                'id' => $row['deuda_id'],
                'periodo' => $row['periodo'],
                'monto' => (float)$row['deuda_monto']
            ];
        }

        $resultado = [];
        foreach ($sociosMap as $socio) {
            $socio['detalle_deudas'] = json_encode($socio['deudas']);
            unset($socio['deudas']);
            $resultado[] = $socio;
        }

        return $resultado;
    }

    public function getCobradores(): array
    {
        $sql = "SELECT id, nombre, apellido, usuario, rol 
                FROM usuarios 
                WHERE rol = 'cobrador' AND estado = 'activo'
                ORDER BY apellido ASC, nombre ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updatePdf(string $id, string $rutaPdf): void
    {
        $sql = "UPDATE planillas SET pdf_generado = :pdf_generado WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pdf_generado' => $rutaPdf,
            ':id' => $id
        ]);
    }

    public function registerAuditEvent(string $usuarioId, string $accion, string $entidad, string $entidadId, array $detalles = []): void
    {
        $sql = "INSERT INTO auditoria (id, usuario_id, accion, entidad_afectada, valor_anterior, valor_nuevo, fecha_hora, motivo) 
                VALUES (:id, :usuario_id, :accion, :entidad_afectada, :valor_anterior, :valor_nuevo, NOW(), :motivo)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => Uuid::uuid4()->toString(),
            ':usuario_id' => $usuarioId,
            ':accion' => $accion,
            ':entidad_afectada' => $entidad,
            ':valor_anterior' => null,
            ':valor_nuevo' => !empty($detalles) ? json_encode($detalles) : null,
            ':motivo' => $detalles['motivo'] ?? null
        ]);
    }
}
