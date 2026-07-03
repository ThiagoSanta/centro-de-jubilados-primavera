<?php

namespace CJP\Modules\Auth;

use Exception;
use CJP\Config\Config;

class AuthService
{
    private AuthRepository $repository;

    /**
     * AuthService constructor.
     *
     * @param AuthRepository|null $repository
     */
    public function __construct(?AuthRepository $repository = null)
    {
        $this->repository = $repository ?? new AuthRepository();
    }

    /**
     * Authenticate user credentials and create session if successful.
     *
     * @param string $username
     * @param string $password
     * @return array
     * @throws Exception
     */
    public function login(string $username, string $password): array
    {
        // 1. Check if username is blocked
        if ($this->repository->isBlocked($username)) {
            throw new Exception(
                "El usuario se encuentra bloqueado temporalmente por exceso de intentos fallidos. Intente más tarde."
            );
        }

        // 2. Find user by username
        $user = $this->repository->findByUsername($username);
        if ($user === null) {
            $this->repository->registerAuditEvent(
                'LOGIN_FALLIDO',
                'usuarios',
                null,
                $username,
                null,
                'El usuario no existe'
            );

            // Audit block event if limit reached
            if ($this->repository->isBlocked($username)) {
                $this->repository->registerAuditEvent(
                    'BLOQUEO_USUARIO',
                    'usuarios',
                    null,
                    $username,
                    null,
                    'Usuario bloqueado por superar el límite de intentos fallidos'
                );
            }

            throw new Exception("Credenciales incorrectas.");
        }

        // 3. Verify user is active
        if ($user['estado'] !== 'activo') {
            throw new Exception("El usuario se encuentra desactivado.");
        }

        // 4. Verify password
        if (!password_verify($password, $user['contrasena_hash'])) {
            $this->repository->registerAuditEvent(
                'LOGIN_FALLIDO',
                'usuarios',
                null,
                $username,
                $user['id'],
                'Contraseña incorrecta'
            );

            // Audit block event if limit reached
            if ($this->repository->isBlocked($username)) {
                $this->repository->registerAuditEvent(
                    'BLOQUEO_USUARIO',
                    'usuarios',
                    null,
                    $username,
                    $user['id'],
                    'Usuario bloqueado por superar el límite de intentos fallidos'
                );
            }

            throw new Exception("Credenciales incorrectas.");
        }

        // 5. Initialize session, regenerate ID
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);

        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['rol'] = $user['rol'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['apellido'] = $user['apellido'];
        $_SESSION['ultimo_acceso'] = time();

        // Audit success event
        $this->repository->registerAuditEvent(
            'LOGIN_EXITOSO',
            'usuarios',
            null,
            null,
            $user['id'],
            'Inicio de sesión exitoso'
        );

        // 6. Update last access date in DB
        $this->repository->updateLastAccess($user['id']);

        // 7. Return user (excluding password hash)
        unset($user['contrasena_hash']);

        return $user;
    }

    /**
     * Destroy the PHP session and delete its cookies.
     *
     * @return void
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
    }

    /**
     * Check if a valid session exists and is not expired.
     *
     * @return array|null
     */
    public function checkSession(): ?array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (
            !isset($_SESSION['usuario_id']) ||
            !isset($_SESSION['rol']) ||
            !isset($_SESSION['ultimo_acceso'])
        ) {
            return null;
        }

        $rol = $_SESSION['rol'];
        $lifetime = $rol === 'administrador'
            ? (int)Config::get('SESSION_LIFETIME_ADMINISTRADOR', 28800)
            : (int)Config::get('SESSION_LIFETIME_COBRADOR', 1800);

        $elapsedTime = time() - (int)$_SESSION['ultimo_acceso'];

        if ($elapsedTime > $lifetime) {
            $this->logout();
            return null;
        }

        return [
            'usuario_id'    => $_SESSION['usuario_id'],
            'rol'           => $_SESSION['rol'],
            'nombre'        => $_SESSION['nombre'],
            'apellido'      => $_SESSION['apellido'],
            'ultimo_acceso' => $_SESSION['ultimo_acceso'],
        ];
    }
}
