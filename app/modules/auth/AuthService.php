<?php

namespace CJP\Modules\Auth;

use Exception;
use CJP\Shared\Exceptions\AppException;
use CJP\Config\Config;

class AuthService
{
    private AuthRepository $repository;

    /**
     * Inicializa el servicio inyectando el repositorio de autenticación.
     *
     * @param AuthRepository|null $repository Instancia del repositorio de auth
     */
    public function __construct(?AuthRepository $repository = null)
    {
        $this->repository = $repository ?? new AuthRepository();
    }

    /**
     * Valida las credenciales ingresadas, gestiona bloqueos por fuerza bruta e inicializa la sesión en caso de éxito.
     *
     * @param string $username Nombre de usuario ingresado
     * @param string $password Contraseña en texto plano
     * @return array Datos del usuario autenticado (sin el hash de contraseña)
     * @throws Exception Si las credenciales son incorrectas, la cuenta está bloqueada o inactiva
     */
    public function login(string $username, string $password, ?string $ip = null): array
    {
        $clientIp = $ip ?? $this->getClientIp();

        // 1. Verificar si la dirección IP de origen se encuentra bloqueada (prevención de ataques distribuidos)
        if ($this->repository->isIpBlocked($clientIp)) {
            throw new AppException(
                "Demasiados intentos fallidos desde esta dirección de red. Intente más tarde.",
                403
            );
        }

        // 2. Verificar si la cuenta de usuario se encuentra bloqueada por exceso de intentos fallidos (protección contra fuerza bruta directa)
        if ($this->repository->isBlocked($username)) {
            throw new AppException(
                "El usuario se encuentra bloqueado temporalmente por exceso de intentos fallidos. Intente más tarde.",
                403
            );
        }

        // 3. Obtener el registro del usuario correspondiente al nombre ingresado
        $user = $this->repository->findByUsername($username);
        if ($user === null) {
            $this->repository->registerAuditEvent(
                'LOGIN_FALLIDO',
                'usuarios',
                $clientIp,
                $username,
                null,
                'El usuario no existe'
            );

            // Registrar evento de auditoría si este intento fallido provocó el bloqueo de la cuenta
            if ($this->repository->isBlocked($username)) {
                $this->repository->registerAuditEvent(
                    'BLOQUEO_USUARIO',
                    'usuarios',
                    $clientIp,
                    $username,
                    null,
                    'Usuario bloqueado por superar el límite de intentos fallidos'
                );
            }

            // Registrar evento de auditoría si la IP alcanzó el umbral máximo de intentos erróneos
            if ($this->repository->isIpBlocked($clientIp)) {
                $this->repository->registerAuditEvent(
                    'BLOQUEO_IP',
                    'usuarios',
                    $clientIp,
                    null,
                    null,
                    'Dirección IP bloqueada por superar el límite de intentos fallidos'
                );
            }

            throw new AppException("Credenciales incorrectas.", 401);
        }

        // 4. Validar que el usuario no se encuentre en estado 'desactivado'
        if ($user['estado'] !== 'activo') {
            throw new AppException("El usuario se encuentra desactivado.", 403);
        }

        // 5. Comparar la contraseña ingresada contra el hash seguro almacenado (bcrypt)
        if (!password_verify($password, $user['contrasena_hash'])) {
            $this->repository->registerAuditEvent(
                'LOGIN_FALLIDO',
                'usuarios',
                $clientIp,
                $username,
                $user['id'],
                'Contraseña incorrecta'
            );

            // Registrar evento de auditoría si este intento fallido provocó el bloqueo de la cuenta
            if ($this->repository->isBlocked($username)) {
                $this->repository->registerAuditEvent(
                    'BLOQUEO_USUARIO',
                    'usuarios',
                    $clientIp,
                    $username,
                    $user['id'],
                    'Usuario bloqueado por superar el límite de intentos fallidos'
                );
            }

            // Registrar evento de auditoría si la IP alcanzó el umbral máximo de intentos erróneos
            if ($this->repository->isIpBlocked($clientIp)) {
                $this->repository->registerAuditEvent(
                    'BLOQUEO_IP',
                    'usuarios',
                    $clientIp,
                    null,
                    $user['id'],
                    'Dirección IP bloqueada por superar el límite de intentos fallidos'
                );
            }

            throw new AppException("Credenciales incorrectas.", 401);
        }

        // 6. Iniciar la sesión PHP y regenerar el Session ID para mitigar ataques de fijación de sesión (Session Fixation)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);

        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['rol'] = $user['rol'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['apellido'] = $user['apellido'];
        $_SESSION['ultimo_acceso'] = time();

        // Registrar el inicio de sesión exitoso en la tabla de auditoría
        $this->repository->registerAuditEvent(
            'LOGIN_EXITOSO',
            'usuarios',
            $clientIp,
            null,
            $user['id'],
            'Inicio de sesión exitoso'
        );

        // 7. Actualizar la marca temporal del último acceso del usuario en la base de datos
        $this->repository->updateLastAccess($user['id']);

        // 8. Retornar los datos de sesión del usuario excluyendo deliberadamente el hash de contraseña
        unset($user['contrasena_hash']);

        return $user;
    }

    /**
     * Destruye la sesión PHP activa y elimina las cookies asociadas en el cliente.
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
     * Comprueba si existe una sesión válida y activa, verificando que no haya expirado por inactividad.
     *
     * @return array|null Datos del usuario en sesión o null si la sesión no es válida o expiró
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

    /**
     * Obtiene la dirección IP real del cliente considerando encabezados de proxy (HTTP_X_FORWARDED_FOR, etc.).
     *
     * @return string Dirección IP resuelta
     */
    private function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
