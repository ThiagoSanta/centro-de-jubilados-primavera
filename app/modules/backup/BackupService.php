<?php

namespace CJP\Modules\Backup;

use PDO;
use CJP\Config\Config;
use CJP\Config\Database;
use CJP\Shared\Exceptions\AppException;
use Ramsey\Uuid\Uuid;

class BackupService
{
    private PDO $db;

    // Ruta fija a mysqldump en XAMPP Windows
    private const MYSQLDUMP_PATH = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';

    // Ruta fija al cliente mysql en XAMPP Windows (reutilizable para operaciones de restauración)
    public const MYSQL_PATH = 'C:\\xampp\\mysql\\bin\\mysql.exe';

    /**
     * Retorna las credenciales de conexión para clientes CLI (mysqldump y mysql).
     *
     * @return array{host: string, dbName: string, user: string, pass: string}
     */
    public static function getCliCredentials(): array
    {
        return [
            'host'   => Config::get('DB_HOST', 'localhost'),
            'dbName' => Config::get('DB_NAME'),
            'user'   => Config::get('DB_USER'),
            'pass'   => Config::get('DB_PASS', ''),
        ];
    }

    /**
     * Constructor del servicio de copias de seguridad.
     * Obtiene la instancia activa de la conexión a la base de datos.
     */
    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Genera el dump SQL de la base de datos y lo retorna como string con cabecera identificatoria.
     * No guarda el archivo en disco permanentemente.
     *
     * @param string $usuarioId UUID del usuario operador que ejecuta la accion
     * @param bool   $registrar Determina si se asienta el evento en backups_log (por defecto true)
     * @return string  Contenido SQL del dump con cabecera
     * @throws AppException
     */
    public function generarDump(string $usuarioId, bool $registrar = true): string
    {
        if (!file_exists(self::MYSQLDUMP_PATH)) {
            throw new AppException(
                'mysqldump no está disponible en este servidor. Contacte al administrador técnico.',
                503
            );
        }

        $creds  = self::getCliCredentials();
        $host   = $creds['host'];
        $dbName = $creds['dbName'];
        $user   = $creds['user'];
        $pass   = $creds['pass'];

        // Construir comando con escapeshellarg() para evitar inyección
        $cmd = sprintf(
            '%s --host=%s --user=%s --password=%s --single-transaction --routines --triggers --skip-lock-tables %s 2>&1',
            escapeshellarg(self::MYSQLDUMP_PATH),
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($dbName)
        );

        set_time_limit(120);

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $sql = implode("\n", $output);

        if ($exitCode !== 0) {
            // Filtrar líneas de error (no contienen DDL/DML)
            $errorLines = array_filter($output, fn($l) => str_starts_with($l, 'mysqldump:') || str_starts_with($l, 'ERROR'));
            $errorMsg = implode(' | ', array_values($errorLines));
            if (empty($errorMsg)) {
                $errorMsg = 'Código de salida: ' . $exitCode;
            }
            throw new AppException(
                'El backup falló durante la generación. Detalle: ' . $errorMsg,
                500
            );
        }

        if (trim($sql) === '') {
            throw new AppException(
                'El dump generado está vacío. Verifique la conexión con la base de datos.',
                500
            );
        }

        if ($registrar) {
            $this->registrarLog($usuarioId);
            $this->registrarAuditoria($usuarioId);
        }

        return $this->generarCabecera() . $sql;
    }

    /**
     * Variante de generarDump que no asienta el registro en backups_log.
     * Utilizada para copias de seguridad de resguardo previo a una restauracion,
     * evitando actualizar la fecha y aviso del ultimo backup realizado administrativamente.
     *
     * @param string $usuarioId UUID del usuario operador o 'sistema'
     * @return string Contenido SQL del dump con cabecera
     * @throws AppException
     */
    public function generarDumpSinRegistro(string $usuarioId = 'sistema'): string
    {
        return $this->generarDump($usuarioId, false);
    }

    /**
     * Genera la cabecera estándar de identificación para los backups del sistema.
     * Permite validar en la restauración que el archivo pertenece a esta aplicación y base de datos.
     *
     * @return string Bloque de cabecera con comentarios SQL
     */
    private function generarCabecera(): string
    {
        $dbName   = Config::get('DB_NAME');
        $fechaIso = (new \DateTimeImmutable())->format('c');

        return "-- CJP-BACKUP-V1\n"
            . "-- Sistema: Centro de Jubilados Primavera\n"
            . "-- Base: {$dbName}\n"
            . "-- Fecha: {$fechaIso}\n";
    }

    /**
     * Retorna la fecha y hora del último backup registrado.
     *
     * @return array|null  ['fecha_hora' => '...', 'usuario_nombre' => '...', 'dias_transcurridos' => N]
     */
    public function getUltimoBackup(): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.fecha_hora, u.nombre, u.apellido
             FROM backups_log b
             LEFT JOIN usuarios u ON b.usuario_id = u.id
             ORDER BY b.fecha_hora DESC
             LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $fechaHora = new \DateTimeImmutable($row['fecha_hora']);
        $ahora = new \DateTimeImmutable();
        $dias = (int) $ahora->diff($fechaHora)->days;

        return [
            'fecha_hora'        => $row['fecha_hora'],
            'usuario_nombre'    => trim(($row['nombre'] ?? '') . ' ' . ($row['apellido'] ?? '')),
            'dias_transcurridos' => $dias,
        ];
    }

    /**
     * Retorna los últimos 20 backups registrados en orden cronológico descendente.
     * Incluye fecha_hora y el nombre completo del usuario que realizó la acción.
     *
     * @return array Lista de registros de backup
     */
    public function getHistorialBackups(): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.id, b.fecha_hora, u.nombre, u.apellido
             FROM backups_log b
             LEFT JOIN usuarios u ON b.usuario_id = u.id
             ORDER BY b.fecha_hora DESC
             LIMIT 20'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'id'             => $row['id'] ?? null,
                'fecha_hora'     => $row['fecha_hora'],
                'usuario_nombre' => trim(($row['nombre'] ?? '') . ' ' . ($row['apellido'] ?? '')) ?: 'Sistema',
            ];
        }, $rows ?: []);
    }

    /**
     * Registra una fila en backups_log.
     */
    private function registrarLog(string $usuarioId): void
    {
        $id  = Uuid::uuid4()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO backups_log (id, usuario_id, fecha_hora) VALUES (:id, :usuarioId, :fechaHora)'
        );
        $stmt->execute([
            'id'        => $id,
            'usuarioId' => $usuarioId,
            'fechaHora' => $now,
        ]);
    }

    /**
     * Registra el evento de backup manual en la tabla de auditoria para trazabilidad.
     */
    private function registrarAuditoria(string $usuarioId): void
    {
        try {
            $id = Uuid::uuid4()->toString();
            $stmt = $this->db->prepare(
                'INSERT INTO auditoria (id, usuario_id, accion, entidad_afectada, valor_anterior, valor_nuevo, fecha_hora, motivo) 
                 VALUES (:id, :usuario_id, :accion, :entidad_afectada, :valor_anterior, :valor_nuevo, NOW(), :motivo)'
            );
            $stmt->execute([
                'id'               => $id,
                'usuario_id'       => ($usuarioId === 'sistema' || empty($usuarioId)) ? null : $usuarioId,
                'accion'           => 'GENERACION_BACKUP',
                'entidad_afectada' => 'base_de_datos',
                'valor_anterior'   => null,
                'valor_nuevo'      => null,
                'motivo'           => 'Generación manual de copia de seguridad de la base de datos'
            ]);
        } catch (\Throwable $e) {
            error_log('No se pudo registrar auditoría de backup: ' . $e->getMessage());
        }
    }
}
