<?php

session_start();

use CJP\Shared\Router;
use CJP\Shared\Helpers\ResponseHelper;
use CJP\Modules\Auth\AuthController;
use CJP\Shared\AuthMiddleware;

// Load composer autoloader and explicitly require config/db classes
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/Config.php';
require_once __DIR__ . '/../app/config/Database.php';

// Set basic CORS headers for local development
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$router = new Router();

// Define base routes
$router->get('/api/ping', function () {
    AuthMiddleware::requireAuth();
    ResponseHelper::json([
        'success' => true,
        'message' => 'CJP API funcionando'
    ]);
});

// Authentication routes
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->get('/api/auth/me', [AuthController::class, 'me']);

// Dispatch request
$router->dispatch();
