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

    /**
     * Constructor del servicio de copias de seguridad.
     * Obtiene la instancia activa de la conexión a la base de datos.
     */
    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Genera el dump SQL de la base de datos y lo retorna como string.
     * No guarda el archivo en disco permanentemente.
     *
     * @param string $usuarioId
     * @return string  Contenido SQL del dump
     * @throws AppException
     */
    public function generarDump(string $usuarioId): string
    {
        if (!file_exists(self::MYSQLDUMP_PATH)) {
            throw new AppException(
                'mysqldump no está disponible en este servidor. Contacte al administrador técnico.',
                503
            );
        }

        $host   = Config::get('DB_HOST', 'localhost');
        $dbName = Config::get('DB_NAME');
        $user   = Config::get('DB_USER');
        $pass   = Config::get('DB_PASS', '');

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

        $this->registrarLog($usuarioId);

        return $sql;
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
}
