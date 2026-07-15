<?php

namespace CJP\Modules\Socios;

use PDO;
use CJP\Config\Database;
use CJP\Shared\Helpers\DateHelper;
use Ramsey\Uuid\Uuid;

class SocioRepository
{
    private PDO $db;

    private const ALLOWED_COLUMNS = [
        'id',
        'numero_socio',
        'nombre_apellido',
        'dni',
        'fecha_nacimiento',
        'telefono',
        'mutual',
        'direccion',
        'latitud',
        'longitud',
        'zona_id',
        'estado',
        'motivo_baja',
        'fecha_baja',
        'modalidad_cobranza',
        'geolocalizacion_pendiente',
        'qr_url',
        'fecha_alta',
        'fecha_actualizacion'
    ];

    /**
     * SocioRepository constructor.
     *
     * @param PDO|null $db
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Find all partners with filters and pagination.
     *
     * @param array $filtros
     * @param int $pagina
     * @return array
     */
    public function findAll(array $filtros, int $pagina): array
    {
        $porPagina = 25;
        $offset = ($pagina - 1) * $porPagina;

        $conditions = [];
        $params = [];

        // 1. Filter: estado
        if (!empty($filtros['estado'])) {
            $conditions[] = "s.estado = :estado";
            $params['estado'] = $filtros['estado'];
        }

        // 2. Filter: zona_id
        if (!empty($filtros['zona_id'])) {
            $conditions[] = "s.zona_id = :zona_id";
            $params['zona_id'] = $filtros['zona_id'];
        }

        // 3. Filter: modalidad_cobranza
        if (!empty($filtros['modalidad_cobranza'])) {
            $conditions[] = "s.modalidad_cobranza = :modalidad_cobranza";
            $params['modalidad_cobranza'] = $filtros['modalidad_cobranza'];
        }

        // 4. Filter: con_deuda (calculated boolean)
        if (isset($filtros['con_deuda']) && $filtros['con_deuda'] !== '' && $filtros['con_deuda'] !== null) {
            $conDeuda = filter_var($filtros['con_deuda'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($conDeuda !== null) {
                $subquery = "COALESCE((SELECT COUNT(*) FROM deudas d WHERE d.socio_id = s.id AND d.estado = 'pendiente'), 0)";
                if ($conDeuda) {
                    $conditions[] = "{$subquery} >= 2";
                } else {
                    $conditions[] = "{$subquery} < 2";
                }
            }
        }

        // 5. Filter: busqueda (nombre, dni, numero_socio)
        if (!empty($filtros['busqueda'])) {
            $conditions[] = "(s.nombre_apellido LIKE :busqueda1 OR s.dni LIKE :busqueda2 OR CAST(s.numero_socio AS CHAR) LIKE :busqueda3)";
            $params['busqueda1'] = '%' . $filtros['busqueda'] . '%';
            $params['busqueda2'] = '%' . $filtros['busqueda'] . '%';
            $params['busqueda3'] = '%' . $filtros['busqueda'] . '%';
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        // Count total
        $countSql = "SELECT COUNT(*) FROM socios s {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch data
        $sql = "SELECT s.*, 
                       (COALESCE((SELECT COUNT(*) FROM deudas d WHERE d.socio_id = s.id AND d.estado = 'pendiente'), 0) >= 2) AS con_deuda
                FROM socios s 
                {$whereClause} 
                ORDER BY s.numero_socio ASC 
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);

        // Bind parameters manually to handle integer types for LIMIT and OFFSET
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll();

        // Map database fields to correct types
        foreach ($data as &$row) {
            $row['numero_socio'] = (int)$row['numero_socio'];
            $row['latitud'] = $row['latitud'] !== null ? (float)$row['latitud'] : null;
            $row['longitud'] = $row['longitud'] !== null ? (float)$row['longitud'] : null;
            $row['geolocalizacion_pendiente'] = (bool)$row['geolocalizacion_pendiente'];
            $row['con_deuda'] = (bool)$row['con_deuda'];
        }

        return [
            'data'       => $data,
            'total'      => $total,
            'pagina'     => $pagina,
            'por_pagina' => $porPagina
        ];
    }

    /**
     * Find a partner by ID.
     *
     * @param string $id
     * @return array|null
     */
    public function findById(string $id): ?array
    {
        $sql = "SELECT s.*, 
                       (COALESCE((SELECT COUNT(*) FROM deudas d WHERE d.socio_id = s.id AND d.estado = 'pendiente'), 0) >= 2) AS con_deuda
                FROM socios s 
                WHERE s.id = :id 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row) {
            $row['numero_socio'] = (int)$row['numero_socio'];
            $row['latitud'] = $row['latitud'] !== null ? (float)$row['latitud'] : null;
            $row['longitud'] = $row['longitud'] !== null ? (float)$row['longitud'] : null;
            $row['geolocalizacion_pendiente'] = (bool)$row['geolocalizacion_pendiente'];
            $row['con_deuda'] = (bool)$row['con_deuda'];
            return $row;
        }

        return null;
    }

    /**
     * Find a partner by DNI.
     *
     * @param string $dni
     * @return array|null
     */
    public function findByDni(string $dni): ?array
    {
        $sql = "SELECT s.*, 
                       (COALESCE((SELECT COUNT(*) FROM deudas d WHERE d.socio_id = s.id AND d.estado = 'pendiente'), 0) >= 2) AS con_deuda
                FROM socios s 
                WHERE s.dni = :dni 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['dni' => $dni]);
        $row = $stmt->fetch();

        if ($row) {
            $row['numero_socio'] = (int)$row['numero_socio'];
            $row['latitud'] = $row['latitud'] !== null ? (float)$row['latitud'] : null;
            $row['longitud'] = $row['longitud'] !== null ? (float)$row['longitud'] : null;
            $row['geolocalizacion_pendiente'] = (bool)$row['geolocalizacion_pendiente'];
            $row['con_deuda'] = (bool)$row['con_deuda'];
            return $row;
        }

        return null;
    }

    /**
     * Get the next sequential partner number.
     *
     * @return int
     */
    public function getNextNumeroSocio(): int
    {
        $sql = "SELECT COALESCE(MAX(numero_socio), 0) + 1 FROM socios";
        $stmt = $this->db->query($sql);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Insert a new partner.
     *
     * @param array $datos
     * @return string Generated UUID
     */
    public function create(array $datos): string
    {
        $id = $datos['id'] ?? Uuid::uuid4()->toString();
        $now = DateHelper::now();

        $columns = ['id', 'fecha_alta'];
        $placeholders = [':id', ':fecha_alta'];
        $params = [
            'id'         => $id,
            'fecha_alta' => $now
        ];

        foreach ($datos as $key => $val) {
            if (in_array($key, self::ALLOWED_COLUMNS, true) && $key !== 'id' && $key !== 'fecha_alta') {
                $columns[] = "`{$key}`";
                $placeholders[] = ":{$key}";
                $params[$key] = $val;
            }
        }

        $sql = "INSERT INTO socios (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $id;
    }

    /**
     * Dynamically update a partner.
     *
     * @param string $id
     * @param array $datos
     * @return void
     */
    public function update(string $id, array $datos): void
    {
        if (empty($datos)) {
            return;
        }

        $fields = [];
        $params = ['id' => $id];

        // Ensure we always update the update timestamp
        if (!isset($datos['fecha_actualizacion'])) {
            $datos['fecha_actualizacion'] = DateHelper::now();
        }

        foreach ($datos as $key => $val) {
            if (in_array($key, self::ALLOWED_COLUMNS, true) && $key !== 'id') {
                $fields[] = "`{$key}` = :{$key}";
                $params[$key] = $val;
            }
        }

        if (empty($fields)) {
            return;
        }

        $sql = "UPDATE socios SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Soft delete a partner.
     *
     * @param string $id
     * @param string $motivo
     * @return void
     */
    public function softDelete(string $id, string $motivo): void
    {
        $now = DateHelper::now();
        $sql = "UPDATE socios SET 
                    estado = 'eliminado', 
                    motivo_baja = :motivo, 
                    fecha_baja = :now,
                    fecha_actualizacion = :now2
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id'     => $id,
            'motivo' => $motivo,
            'now'    => $now,
            'now2'   => $now
        ]);
    }

    /**
     * Reactivate a soft deleted partner.
     *
     * @param string $id
     * @return void
     */
    public function reactivate(string $id): void
    {
        $now = DateHelper::now();
        $sql = "UPDATE socios SET 
                    estado = 'activo', 
                    motivo_baja = NULL, 
                    fecha_baja = NULL,
                    fecha_actualizacion = :now
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id'  => $id,
            'now' => $now
        ]);
    }

    /**
     * Suspend a partner.
     *
     * @param string $id
     * @return void
     */
    public function suspend(string $id): void
    {
        $now = DateHelper::now();
        $sql = "UPDATE socios SET 
                    estado = 'suspendido',
                    fecha_actualizacion = :now
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id'  => $id,
            'now' => $now
        ]);
    }

    /**
     * Update geolocalisation data.
     *
     * @param string $id
     * @param float $lat
     * @param float $lng
     * @param string $zonaId
     * @return void
     */
    public function updateGeolocalizacion(string $id, float $lat, float $lng, string $zonaId): void
    {
        $now = DateHelper::now();
        $sql = "UPDATE socios SET 
                    latitud = :lat, 
                    longitud = :lng, 
                    zona_id = :zonaId, 
                    geolocalizacion_pendiente = 0,
                    fecha_actualizacion = :now
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id'     => $id,
            'lat'    => $lat,
            'lng'    => $lng,
            'zonaId' => $zonaId,
            'now'    => $now
        ]);
    }

    /**
     * Register a CSV inconsistency.
     *
     * @param array $datos
     * @return void
     */
    public function registrarInconsistencia(array $datos): void
    {
        $id = Uuid::uuid4()->toString();
        $now = DateHelper::now();

        $sql = "INSERT INTO importacion_inconsistencias (
                    id, 
                    datos_registro, 
                    motivo_rechazo, 
                    estado, 
                    fecha_importacion
                ) VALUES (
                    :id, 
                    :datos_registro, 
                    :motivo_rechazo, 
                    'pendiente', 
                    :now
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id'             => $id,
            'datos_registro' => json_encode($datos['datos_registro'], JSON_UNESCAPED_UNICODE),
            'motivo_rechazo' => $datos['motivo_rechazo'],
            'now'            => $now
        ]);
    }

    /**
     * Create a notification.
     *
     * @param array $datos
     * @return void
     */
    public function createNotification(array $datos): void
    {
        $id = Uuid::uuid4()->toString();
        $now = DateHelper::now();

        $sql = "INSERT INTO notificaciones (
                    id, 
                    tipo, 
                    mensaje, 
                    fecha, 
                    estado, 
                    referencia, 
                    fecha_expiracion_reversion
                ) VALUES (
                    :id, 
                    :tipo, 
                    :mensaje, 
                    :now, 
                    'no_leida', 
                    :referencia, 
                    :fecha_expiracion_reversion
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id'                         => $id,
            'tipo'                       => $datos['tipo'],
            'mensaje'                    => $datos['mensaje'],
            'now'                        => $now,
            'referencia'                 => json_encode($datos['referencia'], JSON_UNESCAPED_UNICODE),
            'fecha_expiracion_reversion' => $datos['fecha_expiracion_reversion']
        ]);
    }

    /**
     * Get user role by user ID.
     *
     * @param string $usuarioId
     * @return string|null
     */
    public function getUserRole(string $usuarioId): ?string
    {
        $sql = "SELECT rol FROM usuarios WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $usuarioId]);
        $row = $stmt->fetch();
        return $row ? $row['rol'] : null;
    }

    /**
     * Register audit event.
     *
     * @param string $accion
     * @param string $entidad
     * @param string|null $valorAnterior
     * @param string|null $valorNuevo
     * @param string|null $usuarioId
     * @param string|null $motivo
     * @return void
     */
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
