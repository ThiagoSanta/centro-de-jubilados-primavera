<?php

namespace CJP\Shared;

use CJP\Shared\Helpers\ResponseHelper;
use CJP\Config\Config;

class Router
{
    private array $routes = [];

    /**
     * Register a GET route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @return void
     */
    public function get(string $pattern, mixed $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    /**
     * Register a POST route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @return void
     */
    public function post(string $pattern, mixed $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @return void
     */
    public function put(string $pattern, mixed $handler): void
    {
        $this->addRoute('PUT', $pattern, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @param string $pattern
     * @param mixed $handler
     * @return void
     */
    public function delete(string $pattern, mixed $handler): void
    {
        $this->addRoute('DELETE', $pattern, $handler);
    }

    /**
     * Internal helper to add a route definition.
     *
     * @param string $method
     * @param string $pattern
     * @param mixed $handler
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
     * Match current request against registered routes and execute the handler.
     *
     * @return void
     */
    public function dispatch(): void
    {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string (e.g. ?page=2)
        if (($pos = strpos($requestUri, '?')) !== false) {
            $requestUri = substr($requestUri, 0, $pos);
        }

        // Handle subdirectory deployments using APP_URL if set in .env
        $appUrl = Config::get('APP_URL');
        if (!empty($appUrl)) {
            $basePath = parse_url($appUrl, PHP_URL_PATH);
            if (!empty($basePath) && $basePath !== '/' && str_starts_with($requestUri, $basePath)) {
                $requestUri = substr($requestUri, strlen($basePath));
            }
        }

        // Normalize URI: always start with single slash and remove trailing slashes
        $requestUri = '/' . trim($requestUri, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            $pattern = '/' . trim($route['pattern'], '/');

            // Convert routes like /socios/{id} to regex matchable format /socios/(?P<id>[^/]+)
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

                ResponseHelper::error('Error interno del servidor: Handler no válido', 500);
                return;
            }
        }

        ResponseHelper::error('Ruta no encontrada', 404);
    }
}
