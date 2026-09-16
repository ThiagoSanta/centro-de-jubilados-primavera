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
        'tipo_documento',
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
     * Constructor de SocioRepository.
     * Inyecta la conexión PDO a la base de datos.
     *
     * @param PDO|null $db
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Obtiene el listado de socios aplicando filtros dinámicos y paginación.
     *
     * @param array $filtros Filtros opcionales (estado, zona_id, modalidad_cobranza, con_deuda, busqueda)
     * @param int $pagina Número de página actual
     * @return array Arreglo con 'total', 'pagina', 'por_pagina', 'total_paginas' y colección 'datos'
     */
    public function findAll(array $filtros, int $pagina): array
    {
        $porPagina = isset($filtros['limit']) && (int)$filtros['limit'] > 0 ? (int)$filtros['limit'] : 25;
        $maxLimit = 10000;
        if ($porPagina > $maxLimit) {
            $porPagina = $maxLimit;
        }
        $offset = ($pagina - 1) * $porPagina;

        $conditions = [];
        $params = [];

        // 1. Filtro: estado del socio ('activo', 'suspendido', 'baja')
        if (!empty($filtros['estado'])) {
            $conditions[] = "s.estado = :estado";
            $params['estado'] = $filtros['estado'];
        }

        // 2. Filtro: zona geográfica asignada
        if (!empty($filtros['zona_id'])) {
            $conditions[] = "s.zona_id = :zona_id";
            $params['zona_id'] = $filtros['zona_id'];
        }

        // 3. Filtro: modalidad de cobranza ('domicilio' o 'sede')
        if (!empty($filtros['modalidad_cobranza'])) {
            $conditions[] = "s.modalidad_cobranza = :modalidad_cobranza";
            $params['modalidad_cobranza'] = $filtros['modalidad_cobranza'];
        }

        // 4. Filtro: presencia de deudas pendientes (subconsulta booleana)
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

        // 5. Filtro de búsqueda general: coincidencia parcial en nombre/apellido, DNI o número de socio
        if (!empty($filtros['busqueda'])) {
            $busqueda = trim($filtros['busqueda']);
            $busquedaClean = preg_replace('/[^0-9a-zA-Z]/', '', $busqueda);

            $conditions[] = "(s.nombre_apellido LIKE :busqueda1 OR REPLACE(REPLACE(REPLACE(s.dni, '.', ''), ' ', ''), '-', '') LIKE :busqueda2 OR s.dni LIKE :busqueda3 OR CAST(s.numero_socio AS CHAR) LIKE :busqueda4)";
            $params['busqueda1'] = '%' . $busqueda . '%';
            $params['busqueda2'] = '%' . (!empty($busquedaClean) ? $busquedaClean : $busqueda) . '%';
            $params['busqueda3'] = '%' . $busqueda . '%';
            $params['busqueda4'] = '%' . $busqueda . '%';
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        // Calcular el total de registros que satisfacen los filtros para el paginador
        $countSql = "SELECT COUNT(*) FROM socios s {$whereClause}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Obtener el lote de registros con ordenamiento y paginación
        $sql = "SELECT s.*, 
                       z.nombre AS zona_nombre,
                       (COALESCE((SELECT COUNT(*) FROM deudas d WHERE d.socio_id = s.id AND d.estado = 'pendiente'), 0) >= 2) AS con_deuda
                FROM socios s 
                LEFT JOIN zonas z ON s.zona_id = z.id
                {$whereClause} 
                ORDER BY s.numero_socio ASC 
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);

        // Vincular parámetros de paginación explícitamente como enteros PDO::PARAM_INT para LIMIT y OFFSET
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $data = $stmt->fetchAll();

        // Castear campos numéricos y booleanos provenientes de la base de datos a sus tipos nativos en PHP
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
     * Busca un socio por su identificador UUID.
     *
     * @param string $id UUID del socio
     * @return array|null Datos del socio o null si no fue encontrado
     */
    public function findById(string $id): ?array
    {
        $sql = "SELECT s.*, 
                       z.nombre AS zona_nombre,
                       (COALESCE((SELECT COUNT(*) FROM deudas d WHERE d.socio_id = s.id AND d.estado = 'pendiente'), 0) >= 2) AS con_deuda
                FROM socios s 
                LEFT JOIN zonas z ON s.zona_id = z.id
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
     * Busca un socio por su número de documento (DNI).
     *
     * @param string $dni Número de documento
     * @return array|null Datos del socio o null si no existe
     */
    public function findByDni(string $dni): ?array
    {
        $dniClean = preg_replace('/[^0-9a-zA-Z]/', '', $dni);
        $sql = "SELECT s.*, 
                       z.nombre AS zona_nombre,
                       (COALESCE((SELECT COUNT(*) FROM deudas d WHERE d.socio_id = s.id AND d.estado = 'pendiente'), 0) >= 2) AS con_deuda
                FROM socios s 
                LEFT JOIN zonas z ON s.zona_id = z.id
                WHERE s.dni = :dni OR REPLACE(REPLACE(REPLACE(s.dni, '.', ''), ' ', ''), '-', '') = :dniClean
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['dni' => $dni, 'dniClean' => !empty($dniClean) ? $dniClean : $dni]);
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
     * Obtiene el siguiente número correlativo disponible para asignación de socio.
     *
     * @return int Siguiente número de socio
     */
    public function getNextNumeroSocio(): int
    {
        $sql = "SELECT COALESCE(MAX(numero_socio), 0) + 1 FROM socios";
        $stmt = $this->db->query($sql);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Inserta un nuevo socio en la tabla 'socios'.
     *
     * @param array $datos Atributos validados del socio
     * @return string UUID generado para el nuevo socio
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
     * Actualiza dinámicamente las columnas suministradas para un socio específico.
     *
     * @param string $id UUID del socio
     * @param array $datos Campos a actualizar
     * @return void
     */
    public function update(string $id, array $datos): void
    {
        if (empty($datos)) {
            return;
        }

        $fields = [];
        $params = ['id' => $id];

        // Asegurar que siempre se actualice la marca temporal 'fecha_actualizacion'
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
     * Registra la baja lógica de un socio (estado 'baja'), guardando el motivo y la fecha actual.
     *
     * @param string $id UUID del socio
     * @param string $motivo Razón de la baja
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
     * Reactiva un socio en estado de baja lógica (limpia 'fecha_baja' y 'motivo_baja').
     *
     * @param string $id UUID del socio
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
     * Suspende a un socio activo cambiando su estado a 'suspendido'.
     *
     * @param string $id UUID del socio
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
     * Actualiza las coordenadas geográficas y la zona asignada al socio.
     *
     * @param string $id UUID del socio
     * @param float $lat Latitud geográfica
     * @param float $lng Longitud geográfica
     * @param string $zonaId UUID de la zona resultante
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
     * Registra una inconsistencia detectada durante la importación masiva de socios desde CSV.
     *
     * @param array $datos Fila con datos erróneos, número de fila y descripción de la falla
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
     * Obtiene la lista de inconsistencias de importación CSV, con filtro opcional por estado ('pendiente' o 'resuelto').
     *
     * @param array $filtros Criterios de filtrado
     * @return array Colección de inconsistencias
     */
    public function getInconsistencias(array $filtros): array
    {
        $where  = [];
        $params = [];

        if (!empty($filtros['estado'])) {
            $where[]          = 'estado = :estado';
            $params['estado'] = $filtros['estado'];
        }

        $sql = 'SELECT id, datos_registro, motivo_rechazo, estado, fecha_importacion
                FROM importacion_inconsistencias'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY fecha_importacion DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Inserta una nueva notificación interna en el sistema.
     *
     * @param array $datos Datos de la notificación (usuario_id, tipo, mensaje, metadata)
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
     * Obtiene el rol asignado a un usuario por su identificador UUID.
     *
     * @param string $usuarioId UUID del usuario
     * @return string|null Rol del usuario o null si no fue encontrado
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
     * Inserta un registro de auditoría para operaciones ejecutadas en el módulo de socios.
     *
     * @param string $accion Tipo de acción efectuada
     * @param string $entidad Entidad impactada ('socios')
     * @param string|null $valorAnterior Estado previo en JSON
     * @param string|null $valorNuevo Estado nuevo en JSON
     * @param string|null $usuarioId UUID del operador responsable
     * @param string|null $motivo Justificación o detalle de la acción
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
