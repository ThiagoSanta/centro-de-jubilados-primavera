<?php

namespace CJP\Modules\Usuarios;

use Exception;

class UsuarioService
{
    private UsuarioRepository $repository;

    public function __construct()
    {
        $this->repository = new UsuarioRepository();
    }

    /**
     * Retorna todos los usuarios.
     *
     * @return array
     */
    public function getAll(): array
    {
        return $this->repository->findAll();
    }

    /**
     * Retorna un usuario por ID o lanza excepción si no existe.
     *
     * @param string $id
     * @return array
     * @throws Exception
     */
    public function getOne(string $id): array
    {
        $usuario = $this->repository->findById($id);

        if ($usuario === null) {
            throw new Exception('Usuario no encontrado.');
        }

        return $usuario;
    }

    /**
     * Crea un nuevo usuario.
     * Valida username único y hashea la contraseña con bcrypt.
     * El rol NO es modificable después de crear.
     *
     * @param array  $datos
     * @param string $usuarioId ID del usuario que realiza la acción
     * @return array
     * @throws Exception
     */
    public function crear(array $datos, string $usuarioId): array
    {
        $nombre    = trim($datos['nombre'] ?? '');
        $apellido  = trim($datos['apellido'] ?? '');
        $username  = trim($datos['usuario'] ?? '');
        $password  = $datos['contrasena'] ?? '';
        $rol       = $datos['rol'] ?? '';

        if (empty($nombre)) {
            throw new Exception('El nombre es obligatorio.');
        }

        if (empty($apellido)) {
            throw new Exception('El apellido es obligatorio.');
        }

        if (empty($username)) {
            throw new Exception('El nombre de usuario es obligatorio.');
        }

        if (empty($password) || strlen($password) < 8) {
            throw new Exception('La contraseña debe tener al menos 8 caracteres.');
        }

        if (!in_array($rol, ['administrador', 'cobrador'], true)) {
            throw new Exception('El rol debe ser "administrador" o "cobrador".');
        }

        // Validar username único
        $existente = $this->repository->findByUsername($username);
        if ($existente !== null) {
            throw new Exception('El nombre de usuario ya está en uso.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $id = $this->repository->create([
            'nombre'          => $nombre,
            'apellido'        => $apellido,
            'usuario'         => $username,
            'contrasena_hash' => $hash,
            'rol'             => $rol,
        ]);

        $this->repository->registerAuditEvent(
            'USUARIO_CREADO',
            'usuarios',
            null,
            $id,
            $usuarioId,
            "Usuario {$username} creado con rol {$rol}"
        );

        return $this->repository->findById($id);
    }

    /**
     * Edita un usuario existente.
     * No permite cambiar el rol ni el username.
     *
     * @param string $id
     * @param array  $datos
     * @param string $usuarioId ID del usuario que realiza la acción
     * @return array
     * @throws Exception
     */
    public function editar(string $id, array $datos, string $usuarioId): array
    {
        $usuario = $this->repository->findById($id);

        if ($usuario === null) {
            throw new Exception('Usuario no encontrado.');
        }

        // No permitir cambiar rol ni username — se ignoran silenciosamente
        $permitidos = ['nombre', 'apellido'];
        $actualizar = [];

        foreach ($permitidos as $campo) {
            if (isset($datos[$campo]) && trim($datos[$campo]) !== '') {
                $actualizar[$campo] = trim($datos[$campo]);
            }
        }

        if (empty($actualizar)) {
            throw new Exception('No se proporcionaron campos válidos para actualizar.');
        }

        $this->repository->update($id, $actualizar);

        $this->repository->registerAuditEvent(
            'USUARIO_EDITADO',
            'usuarios',
            $usuario['nombre'] . ' ' . $usuario['apellido'],
            ($actualizar['nombre'] ?? $usuario['nombre']) . ' ' . ($actualizar['apellido'] ?? $usuario['apellido']),
            $usuarioId,
            "Datos del usuario {$usuario['usuario']} actualizados"
        );

        return $this->repository->findById($id);
    }

    /**
     * Cambia la contraseña de un usuario.
     * Valida longitud mínima de 8 caracteres.
     *
     * @param string $id
     * @param string $nuevaPassword
     * @param string $usuarioId ID del usuario que realiza la acción
     * @return void
     * @throws Exception
     */
    public function cambiarPassword(string $id, string $nuevaPassword, string $usuarioId): void
    {
        $usuario = $this->repository->findById($id);

        if ($usuario === null) {
            throw new Exception('Usuario no encontrado.');
        }

        if (strlen($nuevaPassword) < 8) {
            throw new Exception('La nueva contraseña debe tener al menos 8 caracteres.');
        }

        $hash = password_hash($nuevaPassword, PASSWORD_BCRYPT);
        $this->repository->updatePassword($id, $hash);

        $this->repository->registerAuditEvent(
            'USUARIO_CAMBIO_PASSWORD',
            'usuarios',
            null,
            $id,
            $usuarioId,
            "Contraseña del usuario {$usuario['usuario']} modificada"
        );
    }

    /**
     * Desactiva un usuario. No puede desactivarse a sí mismo.
     *
     * @param string $id
     * @param string $usuarioId ID del usuario que realiza la acción
     * @return void
     * @throws Exception
     */
    public function desactivar(string $id, string $usuarioId): void
    {
        if ($id === $usuarioId) {
            throw new Exception('No puede desactivar su propio usuario.');
        }

        $usuario = $this->repository->findById($id);

        if ($usuario === null) {
            throw new Exception('Usuario no encontrado.');
        }

        if ($usuario['estado'] === 'desactivado') {
            throw new Exception('El usuario ya se encuentra desactivado.');
        }

        $this->repository->deactivate($id);

        $this->repository->registerAuditEvent(
            'USUARIO_DESACTIVADO',
            'usuarios',
            'activo',
            'desactivado',
            $usuarioId,
            "Usuario {$usuario['usuario']} desactivado"
        );
    }

    /**
     * Reactiva un usuario previamente desactivado.
     *
     * @param string $id
     * @param string $usuarioId ID del usuario que realiza la acción
     * @return void
     * @throws Exception
     */
    public function reactivar(string $id, string $usuarioId): void
    {
        $usuario = $this->repository->findById($id);

        if ($usuario === null) {
            throw new Exception('Usuario no encontrado.');
        }

        if ($usuario['estado'] === 'activo') {
            throw new Exception('El usuario ya se encuentra activo.');
        }

        $this->repository->reactivate($id);

        $this->repository->registerAuditEvent(
            'USUARIO_REACTIVADO',
            'usuarios',
            'desactivado',
            'activo',
            $usuarioId,
            "Usuario {$usuario['usuario']} reactivado"
        );
    }
}
