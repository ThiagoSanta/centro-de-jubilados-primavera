<?php

use CJP\Shared\Router;
use CJP\Shared\Helpers\ResponseHelper;

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
    ResponseHelper::json([
        'success' => true,
        'message' => 'CJP API funcionando'
    ]);
});

// Dispatch request
$router->dispatch();
