<?php

namespace CJP\Modules\Usuarios;

use CJP\Shared\AuthMiddleware;
use CJP\Shared\Helpers\ResponseHelper;

class UsuarioController
{
    private UsuarioService $service;

    public function __construct(?UsuarioService $service = null)
    {
        $this->service = $service ?? new UsuarioService();
    }

    /**
     * GET /api/usuarios
     * Lista todos los usuarios.
     *
     * @param array $params
     * @return void
     */
    public function getAll(array $params = []): void
    {
        AuthMiddleware::requireAuth('administrador');

        $usuarios = $this->service->getAll();

        ResponseHelper::success($usuarios, 'Usuarios obtenidos correctamente.');
    }

    /**
     * GET /api/usuarios/{id}
     * Retorna un usuario por su ID.
     *
     * @param array $params
     * @return void
     */
    public function getOne(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');

        $id      = $params['id'] ?? '';
        $usuario = $this->service->getOne($id);

        ResponseHelper::success($usuario, 'Usuario obtenido correctamente.');
    }

    /**
     * POST /api/usuarios
     * Crea un nuevo usuario.
     *
     * @param array $params
     * @return void
     */
    public function crear(array $params = []): void
    {
        AuthMiddleware::requireAuth('administrador');

        $input     = json_decode(file_get_contents('php://input'), true) ?? [];
        $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

        $usuario = $this->service->crear($input, $usuarioId);

        ResponseHelper::success($usuario, 'Usuario creado correctamente.', 201);
    }

    /**
     * PUT /api/usuarios/{id}
     * Edita nombre y apellido de un usuario.
     *
     * @param array $params
     * @return void
     */
    public function editar(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');

        $id        = $params['id'] ?? '';
        $input     = json_decode(file_get_contents('php://input'), true) ?? [];
        $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

        $usuario = $this->service->editar($id, $input, $usuarioId);

        ResponseHelper::success($usuario, 'Usuario actualizado correctamente.');
    }

    /**
     * POST /api/usuarios/{id}/password
     * Cambia la contraseña de un usuario.
     * Body: { "nueva_password": "..." }
     *
     * @param array $params
     * @return void
     */
    public function cambiarPassword(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');

        $id            = $params['id'] ?? '';
        $input         = json_decode(file_get_contents('php://input'), true) ?? [];
        $nuevaPassword = $input['nueva_password'] ?? '';
        $usuarioId     = $_SESSION['usuario_id'] ?? 'sistema';

        $this->service->cambiarPassword($id, $nuevaPassword, $usuarioId);

        ResponseHelper::success(null, 'Contraseña actualizada correctamente.');
    }

    /**
     * POST /api/usuarios/{id}/desactivar
     * Desactiva un usuario.
     *
     * @param array $params
     * @return void
     */
    public function desactivar(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');

        $id        = $params['id'] ?? '';
        $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

        $this->service->desactivar($id, $usuarioId);

        ResponseHelper::success(null, 'Usuario desactivado correctamente.');
    }

    /**
     * POST /api/usuarios/{id}/reactivar
     * Reactiva un usuario desactivado.
     *
     * @param array $params
     * @return void
     */
    public function reactivar(array $params): void
    {
        AuthMiddleware::requireAuth('administrador');

        $id        = $params['id'] ?? '';
        $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

        $this->service->reactivar($id, $usuarioId);

        ResponseHelper::success(null, 'Usuario reactivado correctamente.');
    }
}
