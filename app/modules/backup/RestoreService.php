<?php

namespace CJP\Modules\Backup;

use CJP\Config\Config;
use CJP\Modules\Auth\AuthRepository;
use CJP\Modules\Auth\AuthService;
use CJP\Shared\Exceptions\AppException;

class RestoreService
{
    private ?BackupService $backupService = null;
    private ?AuthService   $authService = null;
    private ?AuthRepository $authRepository = null;

    /**
     * Tamaño máximo permitido para el archivo de backup en bytes (20 MB).
     */
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /**
     * Constructor de RestoreService.
     * Permite inyectar dependencias opcionales. No conecta a la base de datos en el constructor.
     */
    public function __construct(
        ?BackupService $backupService = null,
        ?AuthService $authService = null,
        ?AuthRepository $authRepository = null
    ) {
        $this->backupService  = $backupService;
        $this->authService    = $authService;
        $this->authRepository = $authRepository;
    }

    private function getBackupService(): BackupService
    {
        return $this->backupService ??= new BackupService();
    }

    private function getAuthService(): AuthService
    {
        return $this->authService ??= new AuthService();
    }

    private function getAuthRepository(): AuthRepository
    {
        return $this->authRepository ??= new AuthRepository();
    }

    /**
     * Ejecuta el proceso integral de restauración de la base de datos a partir de un archivo subido.
     *
     * Orden estricto de ejecución:
     * 1. Validaciones previas (confirmación, archivo, cabecera CJP, sentencias prohibidas y completitud).
     * 2. Bloqueo exclusivo con flock sobre storage/backups/.restaurando.
     * 3. Generación y rotación del backup de resguardo previo.
     * 4. Invocación segura del cliente mysql.exe vía proc_open y archivo de configuración temporal .cnf.
     * 5. Registro de auditoría y bitácora en archivo de log.
     * 6. Cierre forzoso de sesión del usuario administrador.
     *
     * @param array|null  $archivo      Estructura proveniente de $_FILES['archivo']
     * @param string|null $confirmacion Texto de confirmación enviado por POST
     * @param string      $usuarioId    UUID del usuario administrador autenticado
     * @return array      Información de resultado para la respuesta HTTP
     * @throws AppException
     */
    public function restaurar(?array $archivo, ?string $confirmacion, string $usuarioId): array
    {
        // a) Validar palabra de confirmación requerida
        if ($confirmacion !== 'RESTAURAR') {
            throw new AppException('Debe confirmar la operación escribiendo exactamente RESTAURAR.', 400);
        }

        // b, c, d, e) Validaciones estrictas del archivo y su contenido antes de generar backup o tocar la base de datos
        $this->validarArchivo($archivo, true);

        $tmpFilePath    = $archivo['tmp_name'];
        $nombreOriginal = $archivo['name'] ?? 'backup.sql';
        $tamanoArchivo  = (int)($archivo['size'] ?? filesize($tmpFilePath));

        // f) Bloqueo exclusivo no bloqueante para evitar restauraciones simultáneas
        $backupsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupsDir)) {
            // Se crean los directorios con permisos restrictivos si no existen
            @mkdir($backupsDir, 0700, true);
        }

        $lockFile = $backupsDir . DIRECTORY_SEPARATOR . '.restaurando';
        $lockFp   = @fopen($lockFile, 'c+');

        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            if ($lockFp) {
                fclose($lockFp);
            }
            throw new AppException('Ya hay una restauración en curso.', 409);
        }

        $cnfFile = null;

        try {
            // g) Generar backup de seguridad previo sin asentar en backups_log administrativo
            $fechaStr       = (new \DateTimeImmutable())->format('Y-m-d_H-i-s');
            $preBackupName  = "pre-restauracion-{$fechaStr}.sql";
            $preBackupPath  = $backupsDir . DIRECTORY_SEPARATOR . $preBackupName;

            try {
                $sqlSeguridad = $this->getBackupService()->generarDumpSinRegistro($usuarioId);
                $escrito      = @file_put_contents($preBackupPath, $sqlSeguridad);
                if ($escrito === false) {
                    throw new \Exception('No se pudo escribir el archivo de resguardo previo en disco.');
                }
            } catch (\Throwable $e) {
                // Si falla la generación del backup previo, se aborta inmediatamente sin tocar la base
                throw new AppException('Error al generar el backup de seguridad previo: ' . $e->getMessage(), 500);
            }

            // Conservar únicamente los 5 backups pre-restauracion-*.sql más recientes
            $this->rotarBackupsPrevios($backupsDir);

            // h) Ejecutar la restauración mediante el cliente oficial mysql.exe
            if (!file_exists(BackupService::MYSQL_PATH)) {
                throw new AppException('El cliente mysql no está disponible en este servidor. Contacte al administrador técnico.', 503);
            }

            $creds  = BackupService::getCliCredentials();
            $host   = $creds['host'];
            $dbName = $creds['dbName'];
            $user   = $creds['user'];
            $pass   = $creds['pass'];

            // Crear archivo temporal .cnf con permisos de solo lectura para pasar credenciales de forma segura
            $cnfFile = tempnam(sys_get_temp_dir(), 'cjp_rest_');
            if (!$cnfFile) {
                throw new AppException('No se pudo crear el archivo temporal de credenciales.', 500);
            }

            // Escapar barras invertidas y comillas dobles para que el archivo de opciones no sea vulnerable
            $escapeCnf = function (string $val): string {
                $val = str_replace('\\', '\\\\', $val);
                $val = str_replace('"', '\"', $val);
                return '"' . $val . '"';
            };

            $cnfContent = "[client]\n"
                . "host=" . $escapeCnf($host) . "\n"
                . "user=" . $escapeCnf($user) . "\n"
                . "password=" . $escapeCnf($pass) . "\n";

            file_put_contents($cnfFile, $cnfContent);

            // Registrar función de apagado como respaldo por si el proceso PHP muere abruptamente
            $shutdownFile = $cnfFile;
            register_shutdown_function(function () use ($shutdownFile) {
                if ($shutdownFile && file_exists($shutdownFile)) {
                    @unlink($shutdownFile);
                }
            });

            // Preparar descriptores de proc_open para stdin, stdout y stderr
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            // Formato de argumentos tipo array: --defaults-extra-file siempre en primer lugar
            $cmd = [
                BackupService::MYSQL_PATH,
                '--defaults-extra-file=' . $cnfFile,
                '--database=' . $dbName,
            ];

            set_time_limit(300);

            $sqlStream = @fopen($tmpFilePath, 'rb');
            if (!$sqlStream) {
                throw new AppException('No se pudo abrir el archivo de backup para transmitir a la base de datos.', 500);
            }

            $pipes   = [];
            $process = proc_open($cmd, $descriptors, $pipes);

            if (!is_resource($process)) {
                fclose($sqlStream);
                throw new AppException('No se pudo iniciar el proceso de restauración del cliente mysql.', 500);
            }

            // Transmisión directa por streaming de stdin sin cargar todo el archivo en la memoria de PHP
            stream_copy_to_stream($sqlStream, $pipes[0]);
            fclose($sqlStream);
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            // i) Comprobación del código de salida de mysql.exe
            if ($exitCode !== 0) {
                $this->registrarBitacoraArchivo($usuarioId, $nombreOriginal, 'FALLO');
                error_log("Error en la restauración de base de datos (código {$exitCode}): " . $stderr);

                // No intentar restaurar automáticamente; indicar el archivo previo para recuperación manual
                throw new AppException(
                    "La restauración falló durante la ejecución (código de salida {$exitCode}). " .
                    "La base de datos pudo haber quedado en un estado incompleto o inconsistente. " .
                    "Para recuperarla manualmente, use el respaldo de seguridad: storage/backups/{$preBackupName}",
                    500
                );
            }

            // j) Registro de auditoría y bitácora en caso de éxito
            try {
                $motivo = "Archivo restaurado: " . basename($nombreOriginal) . " ({$tamanoArchivo} bytes)";
                $this->getAuthRepository()->registerAuditEvent(
                    'RESTAURACION_BACKUP',
                    'base_de_datos',
                    null,
                    null,
                    $usuarioId,
                    $motivo
                );
            } catch (\Throwable $e) {
                // Al restaurar, la tabla usuarios fue sustituida; si el usuarioId actual ya no existe, la FK falla
                error_log('No se pudo registrar auditoría en la base de datos tras la restauración: ' . $e->getMessage());
            }

            // Escribir siempre el asiento en el log del sistema de archivos
            $this->registrarBitacoraArchivo($usuarioId, $nombreOriginal, 'EXITO');

            // k) Cerrar la sesión del administrador y retornar respuesta de éxito
            $this->getAuthService()->logout();

            return [
                'message' => 'Base de datos restaurada. Por seguridad se cerro la sesion: vuelva a iniciar sesion.',
            ];
        } finally {
            // Limpieza del archivo de configuración temporal .cnf
            if ($cnfFile && file_exists($cnfFile)) {
                @unlink($cnfFile);
            }

            // Liberación del bloqueo de archivo y borrado del indicador
            if ($lockFp) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                @unlink($lockFile);
            }
        }
    }

    /**
     * Valida los metadatos del archivo subido y delega la verificación de su contenido SQL.
     *
     * @param array|null $archivo             Arreglo proveniente de $_FILES
     * @param bool       $verificarIsUploaded Bandera para verificar subida HTTP legítima
     * @throws AppException
     */
    public function validarArchivo(?array $archivo, bool $verificarIsUploaded = true): void
    {
        if (!$archivo || !isset($archivo['error']) || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
            throw new AppException('Debe seleccionar un archivo de backup para restaurar.', 400);
        }

        if ($archivo['error'] !== UPLOAD_ERR_OK) {
            throw new AppException('Error al subir el archivo de backup (código: ' . $archivo['error'] . ').', 400);
        }

        $tmpPath = $archivo['tmp_name'] ?? '';
        if ($tmpPath === '' || !file_exists($tmpPath)) {
            throw new AppException('El archivo temporal de subida no se encuentra en el servidor.', 400);
        }

        if ($verificarIsUploaded && !is_uploaded_file($tmpPath)) {
            throw new AppException('El archivo recibido no es una subida HTTP válida.', 400);
        }

        // Extensión del archivo debe ser .sql
        $nombreOriginal = $archivo['name'] ?? '';
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            throw new AppException('La extensión del archivo debe ser .sql.', 400);
        }

        // Tamaño máximo de 20 MB
        $size = (int)($archivo['size'] ?? filesize($tmpPath));
        if ($size > self::MAX_FILE_SIZE) {
            throw new AppException('El archivo supera el tamaño máximo permitido de 20 MB.', 400);
        }

        // Validación de tipo MIME con finfo
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        $mimesPermitidos = ['text/plain', 'application/octet-stream'];
        if (!in_array($mime, $mimesPermitidos, true)) {
            throw new AppException('El tipo MIME del archivo no es válido. Debe ser texto plano o SQL.', 400);
        }

        // Validaciones internas del contenido SQL
        $this->validarContenidoSql($tmpPath);
    }

    /**
     * Inspecciona línea por línea el archivo SQL sin cargarlo por completo en memoria.
     * Comprueba cabecera identificatoria, base de datos coincidente, ausencia de sentencias peligrosas
     * y marca final de volcado completado.
     *
     * @param string $filePath Ruta absoluta al archivo en disco
     * @throws AppException
     */
    public function validarContenidoSql(string $filePath): void
    {
        $handle = @fopen($filePath, 'rb');
        if (!$handle) {
            throw new AppException('No se pudo abrir el archivo de backup para su lectura.', 500);
        }

        try {
            // c) Primera línea: debe coincidir exactamente con la marca oficial de versión
            $primeraLinea = fgets($handle);
            if ($primeraLinea === false) {
                throw new AppException('El archivo de backup está vacío.', 400);
            }

            $primeraLinea = rtrim($primeraLinea, "\r\n");
            if ($primeraLinea !== '-- CJP-BACKUP-V1') {
                throw new AppException('El archivo no posee la cabecera válida del sistema (-- CJP-BACKUP-V1). Debe generar un backup nuevo con el botón de Configuración.', 400);
            }

            // Lectura de cabecera inicial buscando la directiva "-- Base:"
            $dbEsperada     = Config::get('DB_NAME');
            $baseEncontrada = null;
            $lineasCabecera = 1;

            while ($lineasCabecera < 25 && ($line = fgets($handle)) !== false) {
                $lineasCabecera++;
                $trimmed = trim($line);
                if (str_starts_with($trimmed, '-- Base:')) {
                    $baseEncontrada = trim(substr($trimmed, strlen('-- Base:')));
                    break;
                }
                // Si encontramos sentencias que no son comentarios de cabecera, se concluye la búsqueda
                if ($trimmed !== '' && !str_starts_with($trimmed, '--') && !str_starts_with($trimmed, '/*')) {
                    break;
                }
            }

            if ($baseEncontrada === null || $baseEncontrada !== $dbEsperada) {
                throw new AppException('El archivo de backup no corresponde a la base de datos actual (' . $dbEsperada . '). Debe generar un backup nuevo con el botón de Configuración.', 400);
            }

            // d y e) Inspección completa línea por línea desde el principio
            rewind($handle);
            $lineaNum           = 0;
            $ultimaLineaNoVacia = '';

            // Expresiones regulares para sentencias prohibidas de administración y modificación de privilegios
            $regexProhibidas1 = '/(?:^|;)\s*(?:DROP\s+DATABASE|CREATE\s+DATABASE|USE\s+\S+\s*;|GRANT\b|REVOKE\b|CREATE\s+USER\b|DROP\s+USER\b|ALTER\s+USER\b|LOAD\s+DATA\b|SET\s+GLOBAL\b|SET\s+PASSWORD\b|SHUTDOWN\b|INSTALL\s+PLUGIN\b)/i';
            $regexProhibidas2 = '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i';
            $regexProhibidas3 = '/\bLOAD_FILE\s*\(/i';

            while (($line = fgets($handle)) !== false) {
                $lineaNum++;

                // Rechazar si coincide con patrones prohibidos, indicando únicamente el número de línea
                if (
                    preg_match($regexProhibidas1, $line) ||
                    preg_match($regexProhibidas2, $line) ||
                    preg_match($regexProhibidas3, $line)
                ) {
                    throw new AppException("Sentencia prohibida detectada en la línea {$lineaNum}. El archivo fue rechazado por motivos de seguridad.", 400);
                }

                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $ultimaLineaNoVacia = $trimmed;
                }
            }

            // Comprobar que la última línea no vacía empiece con la firma de finalización de mysqldump
            if (!str_starts_with($ultimaLineaNoVacia, '-- Dump completed')) {
                throw new AppException('El archivo de backup está incompleto o truncado (no finaliza con -- Dump completed).', 400);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Conserva únicamente los 5 archivos pre-restauracion-*.sql más recientes y borra los restantes.
     *
     * @param string $backupsDir Directorio donde se almacenan las copias de seguridad
     */
    private function rotarBackupsPrevios(string $backupsDir): void
    {
        $archivos = glob($backupsDir . DIRECTORY_SEPARATOR . 'pre-restauracion-*.sql') ?: [];
        usort($archivos, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        if (count($archivos) > 5) {
            $aEliminar = array_slice($archivos, 5);
            foreach ($aEliminar as $archivoViejo) {
                @unlink($archivoViejo);
            }
        }
    }

    /**
     * Escribe una línea informativa en storage/logs/restauraciones.log sin almacenar contraseñas ni datos sensibles.
     *
     * @param string $usuarioId      Identificador del operador
     * @param string $nombreArchivo  Nombre del archivo procesado
     * @param string $resultado      Resultado de la operación (EXITO / FALLO)
     */
    private function registrarBitacoraArchivo(string $usuarioId, string $nombreArchivo, string $resultado): void
    {
        $logsDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0700, true);
        }

        $logPath       = $logsDir . DIRECTORY_SEPARATOR . 'restauraciones.log';
        $fecha         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $usuarioLimpio = $usuarioId ?: 'sistema';
        $archivoLimpio = basename($nombreArchivo);

        $linea = sprintf("[%s] Usuario: %s | Archivo: %s | Resultado: %s\n", $fecha, $usuarioLimpio, $archivoLimpio, $resultado);
        @file_put_contents($logPath, $linea, FILE_APPEND | LOCK_EX);
    }
}
