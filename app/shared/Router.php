<?php

namespace CJP\Shared;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Config\Config;
use CJP\Shared\ExceptionHandler;

class Router
{
    private array $routes = [];

    /**
     * Registra una ruta para el método HTTP GET.
     *
     * @param string $pattern Patrón de URL (admite parámetros entre llaves {id})
     * @param mixed $handler Controlador o función callback a ejecutar
     * @return void
     */
    public function get(string $pattern, mixed $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    /**
     * Registra una ruta para el método HTTP POST.
     *
     * @param string $pattern Patrón de URL
     * @param mixed $handler Controlador o función callback a ejecutar
     * @return void
     */
    public function post(string $pattern, mixed $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * Registra una ruta para el método HTTP PUT.
     *
     * @param string $pattern Patrón de URL
     * @param mixed $handler Controlador o función callback a ejecutar
     * @return void
     */
    public function put(string $pattern, mixed $handler): void
    {
        $this->addRoute('PUT', $pattern, $handler);
    }

    /**
     * Registra una ruta para el método HTTP DELETE.
     *
     * @param string $pattern Patrón de URL
     * @param mixed $handler Controlador o función callback a ejecutar
     * @return void
     */
    public function delete(string $pattern, mixed $handler): void
    {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    /**
     * Auxiliar interno para agregar una definición de ruta a la colección.
     *
     * @param string $method Método HTTP (GET, POST, PUT, DELETE)
     * @param string $pattern Patrón de ruta solicitado
     * @param mixed $handler Manejador de la ruta
     * @return void
     */
    private function addRoute(string $method, string $pattern, mixed $handler): void
    {
        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    /**
     * Compara la URI y método de la petición actual contra las rutas registradas y ejecuta el manejador correspondiente.
     *
     * @return void
     */
    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        // Remover los parámetros de consulta (?param=valor) para evaluar únicamente el path base
        if (($pos = strpos($requestUri, '?')) !== false) {
            $requestUri = substr($requestUri, 0, $pos);
        }

        // Ajustar el prefijo de la ruta si la aplicación se ejecuta dentro de un subdirectorio configurado en APP_URL
        $appUrl = Config::get('APP_URL');
        if (!empty($appUrl)) {
            $basePath = parse_url($appUrl, PHP_URL_PATH);
            if (!empty($basePath) && $basePath !== '/' && str_starts_with($requestUri, $basePath)) {
                $requestUri = substr($requestUri, strlen($basePath));
            }
        }

        // Normalizar la URI para que siempre inicie con una sola barra y no tenga barras redundantes al final
        $requestUri = '/' . trim($requestUri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $pattern = '/' . trim($route['pattern'], '/');

            // Convertir placeholders de ruta como {id} en grupos de captura de expresiones regulares con nombre (?P<id>[^/]+)
            $patternRegex = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $pattern);
            $regex = '#^' . $patternRegex . '$#';

            if (preg_match($regex, $requestUri, $matches)) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                $handler = $route['handler'];

                try {
                    if (is_callable($handler)) {
                        call_user_func($handler, $params);
                        return;
                    }

                    if (is_array($handler) && count($handler) === 2) {
                        [$class, $method] = $handler;
                        if (class_exists($class)) {
                            $controller = new $class();
                            if (method_exists($controller, $method)) {
                                call_user_func([$controller, $method], $params);
                                return;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    ExceptionHandler::handle($e);
                    return;
                }

                ResponseHelper::error('Error interno del servidor: Handler no válido', 500);
                return;
            }
        }

        ResponseHelper::error('Ruta no encontrada', 404);
    }
}
