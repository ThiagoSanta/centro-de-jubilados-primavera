<?php

namespace CJP\Modules\Auth;

use PDO;
use CJP\Config\Database;
use CJP\Shared\Helpers\DateHelper;
use Ramsey\Uuid\Uuid;

class AuthRepository
{
    private PDO $db;

    /**
     * Inicializa el repositorio inyectando la conexión a la base de datos.
     *
     * @param PDO|null $db Instancia de PDO o null para obtenerla mediante singleton
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Busca un usuario por su nombre de usuario único.
     *
     * @param string $username Nombre de usuario
     * @return array|null Datos del usuario o null si no existe
     */
    public function findByUsername(string $username): ?array
    {
        $sql = "SELECT * FROM usuarios WHERE usuario = :username LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    /**
     * Actualiza la fecha y hora del último acceso exitoso del usuario.
     *
     * @param string $userId UUID del usuario
     * @return void
     */
    public function updateLastAccess(string $userId): void
    {
        $sql = "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :userId";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['userId' => $userId]);
    }

    /**
     * Cuenta los intentos fallidos de inicio de sesión consecutivos ocurridos en los últimos 15 minutos para un usuario.
     *
     * @param string $username Nombre de usuario consultado
     * @return int Cantidad de intentos fallidos
     */
    public function getFailedAttempts(string $username): int
    {
        $sql = "SELECT accion FROM auditoria 
                WHERE entidad_afectada = 'usuarios' 
                  AND (
                    valor_nuevo = :username 
                    OR usuario_id = (
                        SELECT id FROM usuarios WHERE usuario = :username2 LIMIT 1
                    )
                  )
                  AND accion IN ('LOGIN_FALLIDO', 'LOGIN_EXITOSO')
                  AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                ORDER BY fecha_hora DESC, id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'username'  => $username,
            'username2' => $username,
        ]);

        $attempts = 0;
        while ($row = $stmt->fetch()) {
            if ($row['accion'] === 'LOGIN_FALLIDO') {
                $attempts++;
            } else {
                break;
            }
        }

        return $attempts;
    }

    /**
     * Verifica si una cuenta de usuario está temporalmente bloqueada por superar el límite de intentos fallidos (5 o más en 15 minutos).
     *
     * @param string $username Nombre de usuario
     * @return bool True si el usuario está bloqueado
     */
    public function isBlocked(string $username): bool
    {
        $sql = "SELECT COUNT(*) FROM auditoria 
                WHERE accion = 'LOGIN_FALLIDO' 
                  AND entidad_afectada = 'usuarios' 
                  AND valor_nuevo = :username 
                  AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['username' => $username]);

        return (int)$stmt->fetchColumn() >= 5;
    }

    /**
     * Verifica si una dirección IP está bloqueada temporalmente por registrar múltiples intentos fallidos (15 o más en 15 minutos).
     *
     * @param string $ip Dirección IP del cliente
     * @param int $limit Umbral de intentos fallidos permitidos
     * @return bool True si la IP está bloqueada
     */
    public function isIpBlocked(string $ip, int $limit = 15): bool
    {
        $sql = "SELECT COUNT(*) FROM auditoria 
                WHERE accion = 'LOGIN_FALLIDO' 
                  AND entidad_afectada = 'usuarios' 
                  AND valor_anterior = :ip 
                  AND fecha_hora >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ip' => $ip]);

        return (int)$stmt->fetchColumn() >= $limit;
    }

    /**
     * Registra una acción de auditoría vinculada a operaciones de autenticación y seguridad.
     *
     * @param string $accion Nombre de la acción ejecutada
     * @param string $entidad Entidad afectada (ej. 'auth')
     * @param string|null $valorAnterior Estado o valor previo en formato JSON
     * @param string|null $valorNuevo Estado o valor nuevo en formato JSON
     * @param string|null $usuarioId UUID del usuario que ejecuta o sufre la acción
     * @param string|null $motivo Motivo o detalle explicativo del evento
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
