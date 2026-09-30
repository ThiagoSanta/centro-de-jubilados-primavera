<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Error: Este script únicamente puede ser ejecutado por línea de comandos (CLI).\n");
}

set_time_limit(0);
ini_set('memory_limit', '512M');
if (function_exists('ignore_user_abort')) {
    ignore_user_abort(true);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/config/Config.php';
require_once __DIR__ . '/../app/config/Database.php';

use CJP\Modules\Socios\SocioService;

$importacionId = $argv[1] ?? null;

if (empty($importacionId)) {
    fwrite(STDERR, "Error: Falta el parámetro <importacion_id>.\n");
    fwrite(STDERR, "Uso: php " . basename(__FILE__) . " <importacion_id>\n");
    exit(1);
}

try {
    $socioService = new SocioService();
    $socioService->procesarImportacionWorker($importacionId);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error fatal en worker de importación [{$importacionId}]: " . $e->getMessage() . "\n");
    exit(1);
}
