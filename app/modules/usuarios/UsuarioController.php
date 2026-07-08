<?php

namespace CJP\Modules\Usuarios;

use Exception;
use CJP\Shared\AuthMiddleware;
use CJP\Shared\Helpers\ResponseHelper;

class UsuarioController
{
    private UsuarioService $service;

    public function __construct()
    {
        $this->service = new UsuarioService();
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

        try {
            $usuarios = $this->service->getAll();

            ResponseHelper::json([
                'success' => true,
                'data'    => $usuarios,
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
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

        try {
            $id      = $params['id'] ?? '';
            $usuario = $this->service->getOne($id);

            ResponseHelper::json([
                'success' => true,
                'data'    => $usuario,
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 404);
        }
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

        try {
            $input     = json_decode(file_get_contents('php://input'), true) ?? [];
            $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

            $usuario = $this->service->crear($input, $usuarioId);

            ResponseHelper::json([
                'success' => true,
                'message' => 'Usuario creado correctamente.',
                'data'    => $usuario,
            ], 201);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
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

        try {
            $id        = $params['id'] ?? '';
            $input     = json_decode(file_get_contents('php://input'), true) ?? [];
            $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

            $usuario = $this->service->editar($id, $input, $usuarioId);

            ResponseHelper::json([
                'success' => true,
                'message' => 'Usuario actualizado correctamente.',
                'data'    => $usuario,
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
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

        try {
            $id             = $params['id'] ?? '';
            $input          = json_decode(file_get_contents('php://input'), true) ?? [];
            $nuevaPassword  = $input['nueva_password'] ?? '';
            $usuarioId      = $_SESSION['usuario_id'] ?? 'sistema';

            $this->service->cambiarPassword($id, $nuevaPassword, $usuarioId);

            ResponseHelper::json([
                'success' => true,
                'message' => 'Contraseña actualizada correctamente.',
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
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

        try {
            $id        = $params['id'] ?? '';
            $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

            $this->service->desactivar($id, $usuarioId);

            ResponseHelper::json([
                'success' => true,
                'message' => 'Usuario desactivado correctamente.',
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
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

        try {
            $id        = $params['id'] ?? '';
            $usuarioId = $_SESSION['usuario_id'] ?? 'sistema';

            $this->service->reactivar($id, $usuarioId);

            ResponseHelper::json([
                'success' => true,
                'message' => 'Usuario reactivado correctamente.',
            ]);
        } catch (Exception $e) {
            ResponseHelper::json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
