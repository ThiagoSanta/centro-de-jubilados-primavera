<?php

namespace CJP\Modules\Auth;

use CJP\Shared\Helpers\ResponseHelper;

class AuthController
{
    private AuthService $authService;

    /**
     * AuthController constructor.
     *
     * @param AuthService|null $authService
     */
    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    /**
     * Handle user login.
     *
     * @param array $params
     * @return void
     */
    public function login(array $params): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            ResponseHelper::error('El usuario y la contraseña son obligatorios.', 400);
            return;
        }

        $user = $this->authService->login($username, $password);
        ResponseHelper::success($user, 'Inicio de sesión exitoso.');
    }

    /**
     * Handle user logout.
     *
     * @param array $params
     * @return void
     */
    public function logout(array $params): void
    {
        $this->authService->logout();
        ResponseHelper::success(null, 'Sesión cerrada exitosamente.');
    }

    /**
     * Retrieve current user session.
     *
     * @param array $params
     * @return void
     */
    public function me(array $params): void
    {
        $session = $this->authService->checkSession();
        if ($session === null) {
            ResponseHelper::error('No autenticado', 401);
            return;
        }

        // Liberar el lock de sesión lo antes posible para evitar que requests
        // concurrentes (ej. navegación rápida entre páginas del cobrador) queden
        // bloqueados esperando que este script termine. checkSession() ya leyó
        // todo lo necesario de $_SESSION.
        session_write_close();

        ResponseHelper::success($session);
    }
}
