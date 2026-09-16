<?php

namespace CJP\Modules\Auth;

use CJP\Shared\Helpers\ResponseHelper;

class AuthController
{
    private AuthService $authService;

    /**
     * Inicializa el controlador inyectando el servicio de autenticación.
     *
     * @param AuthService|null $authService Instancia del servicio de autenticación o null para instanciar por defecto
     */
    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    /**
     * Procesa la solicitud de inicio de sesión validando credenciales y creando la sesión de usuario.
     *
     * @param array $params Parámetros de la ruta
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
     * Cierra la sesión activa del usuario y revoca las cookies asociadas.
     *
     * @param array $params Parámetros de la ruta
     * @return void
     */
    public function logout(array $params): void
    {
        $this->authService->logout();
        ResponseHelper::success(null, 'Sesión cerrada exitosamente.');
    }

    /**
     * Retorna los datos del usuario autenticado en la sesión actual si es válida y no ha expirado.
     *
     * @param array $params Parámetros de la ruta
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
