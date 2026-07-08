<?php

namespace CJP\Modules\Deuda;

use PDO;
use CJP\Config\Database;
use CJP\Shared\Helpers\DateHelper;
use Ramsey\Uuid\Uuid;
use DateTime;

class DeudaRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function findBySocio(string $socioId): array
    {
        $sql = "SELECT * FROM deudas 
                WHERE socio_id = :socioId 
                ORDER BY 
                    CASE WHEN periodo = 'deuda_anterior' THEN 0 ELSE 1 END ASC, 
                    periodo ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['socioId' => $socioId]);
        
        return $stmt->fetchAll() ?: [];
    }

    public function findBySocioAndPeriodo(string $socioId, string $periodo): ?array
    {
        $sql = "SELECT * FROM deudas WHERE socio_id = :socioId AND periodo = :periodo LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['socioId' => $socioId, 'periodo' => $periodo]);
        $row = $stmt->fetch();
        
        return $row ?: null;
    }

    public function findPendientesBySocio(string $socioId): array
    {
        $sql = "SELECT * FROM deudas 
                WHERE socio_id = :socioId AND estado = 'pendiente' 
                ORDER BY 
                    CASE WHEN periodo = 'deuda_anterior' THEN 0 ELSE 1 END ASC, 
                    periodo ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['socioId' => $socioId]);
        
        return $stmt->fetchAll() ?: [];
    }

    public function findAll(array $filtros, int $pagina): array
    {
        $porPagina = 25;
        $offset = ($pagina - 1) * $porPagina;

        $conditions = [];
        $params = [];

        if (!empty($filtros['socio_id'])) {
            $conditions[] = "socio_id = :socio_id";
            $params['socio_id'] = $filtros['socio_id'];
        }

        if (!empty($filtros['periodo'])) {
            $conditions[] = "periodo = :periodo";
            $params['periodo'] = $filtros['periodo'];
        }

        if (!empty($filtros['estado'])) {
            $conditions[] = "estado = :estado";
            $params['estado'] = $filtros['estado'];
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        // Count total
        $countSql = "SELECT COUNT(*) FROM deudas {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch data
        $sql = "SELECT * FROM deudas 
                {$whereClause} 
                ORDER BY fecha_generacion DESC 
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll();

        return [
            'data'       => $data,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $porPagina
        ];
    }

    public function create(array $datos): string
    {
        $id = Uuid::uuid4()->toString();
        $now = DateHelper::now();
        $estado = $datos['estado'] ?? 'pendiente';

        $sql = "INSERT INTO deudas (id, socio_id, periodo, monto, estado, fecha_generacion) 
                VALUES (:id, :socio_id, :periodo, :monto, :estado, :fecha_generacion)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'socio_id' => $datos['socio_id'],
            'periodo' => $datos['periodo'],
            'monto' => $datos['monto'],
            'estado' => $estado,
            'fecha_generacion' => $now
        ]);

        return $id;
    }

    public function updateEstado(string $id, string $estado): void
    {
        $sql = "UPDATE deudas SET estado = :estado";
        $params = ['id' => $id, 'estado' => $estado];

        if ($estado === 'pagada') {
            $sql .= ", fecha_pago = :now";
            $params['now'] = DateHelper::now();
        }

        $sql .= " WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function exonerar(string $id, DateTime $fecha): void
    {
        $sql = "UPDATE deudas SET estado = 'exonerada', fecha_exoneracion = :fecha WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'fecha' => $fecha->format('Y-m-d H:i:s')
        ]);
    }

    public function existePeriodo(string $socioId, string $periodo): bool
    {
        $sql = "SELECT COUNT(*) FROM deudas WHERE socio_id = :socioId AND periodo = :periodo";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['socioId' => $socioId, 'periodo' => $periodo]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function generarMasivo(array $sociosIds, string $periodo, float $monto, string $usuarioId): array
    {
        $resultado = [
            'generadas' => 0,
            'omitidas' => 0,
            'advertencias' => []
        ];

        if (empty($sociosIds)) {
            return $resultado;
        }

        $now = DateHelper::now();
        $this->db->beginTransaction();

        try {
            $checkSql = "SELECT socio_id FROM deudas WHERE periodo = :periodo AND socio_id IN (" . implode(',', array_fill(0, count($sociosIds), '?')) . ")";
            $checkStmt = $this->db->prepare($checkSql);
            $checkParams = array_merge([$periodo], $sociosIds);
            $checkStmt->execute($checkParams);
            $existentes = $checkStmt->fetchAll(PDO::FETCH_COLUMN);

            $insertSql = "INSERT INTO deudas (id, socio_id, periodo, monto, estado, fecha_generacion) VALUES (?, ?, ?, ?, 'pendiente', ?)";
            $insertStmt = $this->db->prepare($insertSql);

            foreach ($sociosIds as $socioId) {
                if (in_array($socioId, $existentes)) {
                    // Si ya existe, se anota para advertencia
                    $resultado['advertencias'][] = $socioId;
                    $resultado['omitidas']++;
                    continue;
                }

                $id = Uuid::uuid4()->toString();
                $insertStmt->execute([$id, $socioId, $periodo, $monto, $now]);
                $resultado['generadas']++;
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $resultado;
    }

    public function registerAuditEvent(
        string $accion,
        string $entidad,
        ?string $valorAnterior,
        ?string $valorNuevo,
        ?string $usuarioId,
        ?string $motivo
    ): void {
        $id = Uuid::uuid4()->toString();
        $now = DateHelper::now();

        $sql = "INSERT INTO auditoria (
                    id, 
                    usuario_id, 
                    accion, 
                    entidad_afectada, 
                    valor_anterior, 
                    valor_nuevo, 
                    fecha_hora, 
                    motivo
                ) VALUES (
                    :id, 
                    :usuarioId, 
                    :accion, 
                    :entidad, 
                    :valorAnterior, 
                    :valorNuevo, 
                    :now, 
                    :motivo
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id'            => $id,
            'usuarioId'     => $usuarioId,
            'accion'        => $accion,
            'entidad'       => $entidad,
            'valorAnterior' => $valorAnterior,
            'valorNuevo'    => $valorNuevo,
            'now'           => $now,
            'motivo'        => $motivo,
        ]);
    }
}

class ConfiguracionCuotaRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function getVigente(): ?array
    {
        $sql = "SELECT * FROM configuracion_cuota 
                WHERE fecha_vigencia_desde <= CURRENT_DATE() 
                ORDER BY fecha_vigencia_desde DESC LIMIT 1";
        
        $stmt = $this->db->query($sql);
        $row = $stmt->fetch();
        
        return $row ?: null;
    }

    public function getHistorico(): array
    {
        $sql = "SELECT c.*, u.nombre_apellido as usuario_nombre 
                FROM configuracion_cuota c
                LEFT JOIN usuarios u ON c.usuario_id = u.id
                ORDER BY fecha_vigencia_desde DESC";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll() ?: [];
    }

    public function create(array $datos): string
    {
        $id = Uuid::uuid4()->toString();
        $now = DateHelper::now();

        $sql = "INSERT INTO configuracion_cuota (id, monto, fecha_vigencia_desde, usuario_id, fecha_registro) 
                VALUES (:id, :monto, :fecha_vigencia_desde, :usuario_id, :fecha_registro)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'monto' => $datos['monto'],
            'fecha_vigencia_desde' => $datos['fecha_vigencia_desde'],
            'usuario_id' => $datos['usuario_id'],
            'fecha_registro' => $now
        ]);

        return $id;
    }
}
