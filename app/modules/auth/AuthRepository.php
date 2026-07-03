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
     * AuthRepository constructor.
     *
     * @param PDO|null $db
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Find user by username.
     *
     * @param string $username
     * @return array|null
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
     * Update user's last access timestamp.
     *
     * @param string $userId
     * @return void
     */
    public function updateLastAccess(string $userId): void
    {
        $sql = "UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = :userId";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['userId' => $userId]);
    }

    /**
     * Count consecutive failed login attempts in the last 15 minutes.
     *
     * @param string $username
     * @return int
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
     * Check if a username is blocked (5 or more failed login attempts in the last 15 minutes).
     *
     * @param string $username
     * @return bool
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
     * Register an audit event in the database.
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
