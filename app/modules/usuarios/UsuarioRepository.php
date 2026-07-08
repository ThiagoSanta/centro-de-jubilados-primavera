<?php

namespace CJP\Modules\Usuarios;

use PDO;
use CJP\Config\Database;
use Ramsey\Uuid\Uuid;

class UsuarioRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Retorna todos los usuarios ordenados por apellido ASC.
     *
     * @return array
     */
    public function findAll(): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nombre, apellido, usuario, rol, estado, ultimo_acceso
             FROM usuarios
             ORDER BY apellido ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca un usuario por su UUID.
     *
     * @param string $id
     * @return array|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nombre, apellido, usuario, rol, estado, ultimo_acceso
             FROM usuarios
             WHERE id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Busca un usuario por su nombre de usuario.
     *
     * @param string $username
     * @return array|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nombre, apellido, usuario, rol, estado, ultimo_acceso
             FROM usuarios
             WHERE usuario = :usuario
             LIMIT 1"
        );
        $stmt->execute(['usuario' => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Crea un nuevo usuario y retorna su UUID generado.
     *
     * @param array $datos
     * @return string UUID del usuario creado
     */
    public function create(array $datos): string
    {
        $id = Uuid::uuid4()->toString();

        $stmt = $this->db->prepare(
            "INSERT INTO usuarios (id, nombre, apellido, usuario, contrasena_hash, rol, estado)
             VALUES (:id, :nombre, :apellido, :usuario, :contrasena_hash, :rol, 'activo')"
        );
        $stmt->execute([
            'id'              => $id,
            'nombre'          => $datos['nombre'],
            'apellido'        => $datos['apellido'],
            'usuario'         => $datos['usuario'],
            'contrasena_hash' => $datos['contrasena_hash'],
            'rol'             => $datos['rol'],
        ]);

        return $id;
    }

    /**
     * Actualiza dinámicamente los campos permitidos de un usuario.
     * Los campos aceptados son: nombre, apellido.
     *
     * @param string $id
     * @param array  $datos
     * @return void
     */
    public function update(string $id, array $datos): void
    {
        $allowed = ['nombre', 'apellido'];
        $sets    = [];
        $params  = ['id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $datos)) {
                $sets[]        = "{$field} = :{$field}";
                $params[$field] = $datos[$field];
            }
        }

        if (empty($sets)) {
            return;
        }

        $sql  = "UPDATE usuarios SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Actualiza el hash de la contraseña de un usuario.
     *
     * @param string $id
     * @param string $hash
     * @return void
     */
    public function updatePassword(string $id, string $hash): void
    {
        $stmt = $this->db->prepare(
            "UPDATE usuarios SET contrasena_hash = :hash WHERE id = :id"
        );
        $stmt->execute(['hash' => $hash, 'id' => $id]);
    }

    /**
     * Desactiva un usuario (estado = 'desactivado').
     *
     * @param string $id
     * @return void
     */
    public function deactivate(string $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE usuarios SET estado = 'desactivado' WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Reactiva un usuario (estado = 'activo').
     *
     * @param string $id
     * @return void
     */
    public function reactivate(string $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE usuarios SET estado = 'activo' WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Registra un evento de auditoría en la tabla auditoria.
     *
     * @param string      $accion
     * @param string      $entidad
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
        $stmt = $this->db->prepare(
            "INSERT INTO auditoria
                 (id, usuario_id, accion, entidad_afectada, valor_anterior, valor_nuevo, fecha_hora, motivo)
             VALUES
                 (UUID(), :usuario_id, :accion, :entidad, :valor_anterior, :valor_nuevo, NOW(), :motivo)"
        );
        $stmt->execute([
            'usuario_id'    => $usuarioId,
            'accion'        => $accion,
            'entidad'       => $entidad,
            'valor_anterior' => $valorAnterior,
            'valor_nuevo'   => $valorNuevo,
            'motivo'        => $motivo,
        ]);
    }
}
