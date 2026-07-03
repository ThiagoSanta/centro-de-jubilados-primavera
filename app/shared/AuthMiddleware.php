<?php

namespace CJP\Shared;

use CJP\Modules\Auth\AuthService;
use CJP\Shared\Helpers\ResponseHelper;

class AuthMiddleware
{
    /**
     * Require authentication and optionally a specific role.
     *
     * @param string|null $rol
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
