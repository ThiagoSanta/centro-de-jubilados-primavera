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

        ResponseHelper::json([
            'success' => true,
            'data'    => $usuarios,
        ]);
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

        ResponseHelper::json([
            'success' => true,
            'data'    => $usuario,
        ]);
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

        ResponseHelper::json([
            'success' => true,
            'message' => 'Usuario creado correctamente.',
            'data'    => $usuario,
        ], 201);
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

        ResponseHelper::json([
            'success' => true,
            'message' => 'Usuario actualizado correctamente.',
            'data'    => $usuario,
        ]);
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

        ResponseHelper::json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente.',
        ]);
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

        ResponseHelper::json([
            'success' => true,
            'message' => 'Usuario desactivado correctamente.',
        ]);
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

        ResponseHelper::json([
            'success' => true,
            'message' => 'Usuario reactivado correctamente.',
        ]);
    }
}
