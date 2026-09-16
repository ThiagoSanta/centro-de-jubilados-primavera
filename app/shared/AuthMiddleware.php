<?php

namespace CJP\Shared;

use CJP\Modules\Auth\AuthService;
use CJP\Shared\Helpers\ResponseHelper;

class AuthMiddleware
{
    /**
     * Valida que exista una sesión activa y, opcionalmente, que el usuario cuente con el rol requerido.
     * Lanza una excepción o interrumpe la ejecución si no cumple las condiciones.
     *
     * @param string|null $rol Rol necesario para acceder al recurso ('administrador', 'cobrador', etc.)
     * @return void
     */
    public static function requireAuth(?string $rol = null): void
    {
        $authService = new AuthService();
        $session = $authService->checkSession();

        if ($session === null) {
            ResponseHelper::error('No autenticado', 401);
            exit;
        }

        if ($rol !== null && $session['rol'] !== $rol) {
            ResponseHelper::error('Acceso denegado', 403);
            exit;
        }
    }
}
