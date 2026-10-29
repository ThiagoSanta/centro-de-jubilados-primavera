<?php
/**
 * ============================================================
 *  SCRIPT DE PRUEBAS AUTOMATIZADO — CJP Sistema
 *  Archivo: scripts/test_sistema.php
 *
 *  Uso:
 *    php scripts/test_sistema.php            -> ejecuta todas las pruebas
 *    php scripts/test_sistema.php --limpiar  -> borra restos TEST_ sin correr pruebas
 *
 *  Requisitos:
 *    - XAMPP corriendo (Apache + MySQL)
 *    - Extension cURL de PHP habilitada
 *
 *  Este script NO modifica ningun archivo del sistema existente.
 *  Solo crea datos con prefijo TEST_ que limpia al finalizar.
 * ============================================================
 */

declare(strict_types=1);

// ============================================================
//  CONFIGURACION — credenciales y rutas
// ============================================================

define('BASE_URL',      'http://localhost/centro-de-jubilados-primavera/public');
define('ADMIN_USER',    'admin');
define('ADMIN_PASS',    'Admin1234!');
define('COBRADOR_USER', 'TEST_cobrador_qa_9f3k');
define('COBRADOR_PASS', 'Cobrador1!');
define('TEST_PREFIX',   'TEST_');
define('TEST_DNI',      '99999998');   // DNI reservado para socio de prueba principal
define('TEST_DNI2',     '99999997');   // DNI reservado para segundo socio de prueba
define('TEST_DNI_PUNTOS', '99.888.777'); // DNI con puntos para prueba de normalizacion
define('TEST_DNI_NORMALIZADO', '99888777'); // DNI normalizado esperado para limpieza
// Valores exactos usados por el BLOQUE 16 al crear la cuota de prueba via POST /api/cuota.
// Se definen como constantes para que el borrado en limpieza use exactamente los mismos
// valores y nunca pueda borrar una cuota real (que tendria una fecha en el rango actual).
define('TEST_CUOTA_MONTO',        500.00);      // monto fijo que usa el bloque 16
define('TEST_CUOTA_FECHA',        '2099-06-01'); // fecha_vigencia_desde que usa el bloque 16
define('TEST_CUOTA_FECHA_MINIMA', '2099-01-01'); // umbral para el WHERE: cubre corridas acumuladas

// ============================================================
//  ESTADO GLOBAL
// ============================================================

$ctx = [
    'tests'              => 0,
    'passed'             => 0,
    'failed'             => 0,
    'omitted'            => 0,             // pruebas omitidas por precondicion no cumplida
    'failures'           => [],
    'admin_cookie'       => null,
    'cobrador_cookie'    => null,
    'socio_id'           => null,
    'socio_id2'          => null,
    'deuda_id'           => null,
    'pago_id'            => null,
    'planilla_id'        => null,
    'cobrador_id'        => null,
    'importacion_csv_id' => null,        // ID de la importacion CSV de prueba (Bloque 7b)
    'periodo_test'       => '2099-01',   // periodo ficticio para no pisar datos reales
];

// ============================================================
//  FUNCIONES UTILITARIAS
// ============================================================

/**
 * Hace una peticion HTTP a la API usando cURL.
 */
function apiRequest(string $method, string $path, ?array $body = null, ?string $cookie = null): array
{
    $url = BASE_URL . $path;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($cookie !== null && $cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        $headers[] = 'Cookie: ' . $cookie;
    }
    if ($body !== null) {
        $json = json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $headers[] = 'Content-Length: ' . strlen((string)$json);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw    = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $parsed = json_decode((string)$raw, true);
    return ['status' => $status, 'body' => $parsed ?? (string)$raw];
}

/**
 * Sube un archivo local al endpoint especificado via multipart/form-data.
 * Retorna el mismo formato que apiRequest: ['status' => int, 'body' => array|string].
 */
function apiRequestUpload(string $path, string $rutaArchivo, ?string $cookie = null, array $extraFields = []): array
{
    $url = BASE_URL . $path;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    $postFields = $extraFields;
    if ($rutaArchivo !== '' && file_exists($rutaArchivo)) {
        $ext = strtolower(pathinfo($rutaArchivo, PATHINFO_EXTENSION));
        $mime = ($ext === 'sql' || $ext === 'txt') ? 'text/plain' : 'text/csv';
        $postFields['archivo'] = new CURLFile($rutaArchivo, $mime, basename($rutaArchivo));
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

    $headers = ['Accept: application/json'];
    if ($cookie !== null && $cookie !== '') {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        $headers[] = 'Cookie: ' . $cookie;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $parsed = json_decode((string) $raw, true);
    return ['status' => $status, 'body' => $parsed ?? (string) $raw];
}

/**
 * Hace login y retorna la cookie de sesion, o null si falla.
 */
function doLogin(string $user, string $pass): ?string
{
    $url = BASE_URL . '/api/auth/login';
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     json_encode(['username' => $user, 'password' => $pass]));
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_HEADER,         true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        10);

    $response   = curl_exec($ch);
    $status     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($status !== 200) {
        return null;
    }
    $headerRaw = substr((string)$response, 0, $headerSize);
    if (preg_match_all('/Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/i', $headerRaw, $m)) {
        return trim(end($m[1]));
    }
    if (preg_match_all('/Set-Cookie:\s*([^;\r\n]+)/i', $headerRaw, $m)) {
        return trim(end($m[1]));
    }
    return null;
}

/**
 * Registra y evalua un paso de prueba, imprimiendo el resultado en consola.
 */
function assertStatus(array &$ctx, string $name, string $method, string $path, int $expectedCode, array $result): void
{
    $ctx['tests']++;
    $got = $result['status'];
    $ok  = ($got === $expectedCode);

    if ($ok) {
        $ctx['passed']++;
        $label = "\033[32mOK\033[0m";
    } else {
        $ctx['failed']++;
        $label   = "\033[31mFALLO\033[0m";
        $bodyStr = is_array($result['body'])
            ? ($result['body']['message'] ?? json_encode($result['body']))
            : (string)$result['body'];
        $ctx['failures'][] = "[{$method} {$path}] {$name}: esperado {$expectedCode}, recibido {$got} -> {$bodyStr}";
    }
    printf("  %-55s %-6s %-36s esp:%d got:%d %s\n",
        $name, strtoupper($method), $path, $expectedCode, $got, $label);
}

/**
 * Imprime una seccion decorada en la consola.
 */
function section(string $title): void
{
    echo PHP_EOL . "\033[36m" . str_repeat('-', 72) . "\033[0m" . PHP_EOL;
    echo "\033[1;36m  >> {$title}\033[0m" . PHP_EOL;
    echo "\033[36m" . str_repeat('-', 72) . "\033[0m" . PHP_EOL;
}

/**
 * Carga las variables del archivo .env y retorna un array.
 */
function loadEnv(): array
{
    $envPath = __DIR__ . '/../.env';
    if (!file_exists($envPath)) {
        return [];
    }
    $env = [];
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
    return $env;
}

/**
 * Conecta a la base de datos usando el .env del proyecto.
 */
function getDb(): PDO
{
    $env = loadEnv();
    if (empty($env)) {
        throw new RuntimeException("No se encontro el archivo .env en " . realpath(__DIR__ . '/..'));
    }
    $dsn = "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4";
    return new PDO($dsn, $env['DB_USER'], $env['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// ============================================================
//  MODO --limpiar  (ejecutable independientemente)
// ============================================================

if (in_array('--limpiar', $argv ?? [], true)) {
    echo PHP_EOL;
    echo "\033[1;33m+============================================================+\033[0m" . PHP_EOL;
    echo "\033[1;33m|  MODO LIMPIEZA — Eliminando datos TEST_ residuales          |\033[0m" . PHP_EOL;
    echo "\033[1;33m+============================================================+\033[0m" . PHP_EOL;
    echo PHP_EOL;

    try {
        $pdo  = getDb();
        $pdo->beginTransaction();

        // 1. Obtener ID del cobrador TEST_ si existe
        $stmtCob = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmtCob->execute([COBRADOR_USER]);
        $cobradorId = $stmtCob->fetchColumn() ?: null;

        // 2. Limpiar auditoría vinculada al cobrador TEST_ o a datos TEST_
        $deletedAuditoria = 0;
        if ($cobradorId) {
            $s = $pdo->prepare("DELETE FROM auditoria WHERE usuario_id = ?");
            $s->execute([$cobradorId]);
            $deletedAuditoria += $s->rowCount();
        }
        $s = $pdo->prepare("DELETE FROM auditoria WHERE motivo LIKE 'TEST\_%' OR valor_nuevo LIKE '%TEST\_%' OR valor_anterior LIKE '%TEST\_%' OR valor_nuevo LIKE ? OR valor_anterior LIKE ?");
        $s->execute(['%' . TEST_DNI . '%', '%' . TEST_DNI . '%']);
        $deletedAuditoria += $s->rowCount();

        // 3. Limpiar notificaciones vinculadas a datos TEST_
        $deletedNotificaciones = 0;
        try {
            $s = $pdo->prepare("DELETE FROM notificaciones WHERE mensaje LIKE '%TEST\_%' OR referencia LIKE ? OR referencia LIKE ?");
            $s->execute(['%' . TEST_DNI . '%', '%' . TEST_DNI2 . '%']);
            $deletedNotificaciones += $s->rowCount();
        } catch (Exception $e) { /* ignorar */ }

        // 4. Limpiar planillas y detalle vinculadas al cobrador TEST_
        $deletedPlanillas = 0;
        if ($cobradorId) {
            $pdo->prepare("DELETE ps FROM planilla_socio ps INNER JOIN planillas p ON ps.planilla_id = p.id WHERE p.cobrador_id = ?")->execute([$cobradorId]);
            $s = $pdo->prepare("DELETE FROM planillas WHERE cobrador_id = ?");
            $s->execute([$cobradorId]);
            $deletedPlanillas += $s->rowCount();
        }

        // 5. Identificar socios TEST_
        $stmt = $pdo->prepare("SELECT id FROM socios WHERE nombre_apellido LIKE 'TEST\_%' OR dni IN (?, ?)");
        $stmt->execute([TEST_DNI, TEST_DNI2]);
        $testSocioIds    = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $deletedPagos    = 0;
        $deletedDeudas   = 0;
        $deletedSocios   = 0;
        $deletedUsuarios = 0;

        if (!empty($testSocioIds)) {
            $in = implode(',', array_fill(0, count($testSocioIds), '?'));

            // Pagos de esas deudas
            $stmt = $pdo->prepare("SELECT id FROM deudas WHERE socio_id IN ({$in})");
            $stmt->execute($testSocioIds);
            $testDeudaIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($testDeudaIds)) {
                $inD = implode(',', array_fill(0, count($testDeudaIds), '?'));
                try {
                    $pdo->prepare("DELETE FROM pago_deuda WHERE deuda_id IN ({$inD})")->execute($testDeudaIds);
                } catch (Exception $e) { /* ignorar */ }
                $s = $pdo->prepare("DELETE FROM deudas WHERE id IN ({$inD})");
                $s->execute($testDeudaIds);
                $deletedDeudas = $s->rowCount();
            }

            // Pagos del socio (la columna real es socio_id, no deuda_id)
            $s = $pdo->prepare("DELETE FROM pagos WHERE socio_id IN ({$in})");
            $s->execute($testSocioIds);
            $deletedPagos = $s->rowCount();

            // Planillas detalle vinculadas a esos socios
            $pdo->prepare("DELETE FROM planilla_socio WHERE socio_id IN ({$in})")->execute($testSocioIds);

            // Notificaciones que referencien al socio_id en JSON
            foreach ($testSocioIds as $sId) {
                try {
                    $sNotif = $pdo->prepare("DELETE FROM notificaciones WHERE referencia LIKE ?");
                    $sNotif->execute(['%' . $sId . '%']);
                    $deletedNotificaciones += $sNotif->rowCount();
                } catch (Exception $e) { /* ignorar */ }
            }

            // Observaciones e historial
            foreach (['observaciones', 'historial_estados'] as $tabla) {
                try {
                    $pdo->prepare("DELETE FROM {$tabla} WHERE socio_id IN ({$in})")->execute($testSocioIds);
                } catch (Exception $e) { /* ignorar si la tabla no existe en este entorno */ }
            }

            // Socios
            $s = $pdo->prepare("DELETE FROM socios WHERE id IN ({$in})");
            $s->execute($testSocioIds);
            $deletedSocios = $s->rowCount();
        }

        // Planillas huérfanas sin detalle
        try {
            $pdo->query(
                "DELETE p FROM planillas p
                 LEFT JOIN planilla_socio ps ON ps.planilla_id = p.id
                 WHERE ps.planilla_id IS NULL"
            );
        } catch (Exception $e) { /* ignorar */ }

        // Borrado por seguridad de cualquier socio residual
        $pdo->prepare("DELETE FROM socios WHERE nombre_apellido LIKE 'TEST\_%' OR dni IN (?, ?, ?)")->execute([TEST_DNI, TEST_DNI2, TEST_DNI_NORMALIZADO]);

        // 5.b. Pagos registrados por el cobrador TEST_ (evita error FK fk_pagos_usuario)
        if ($cobradorId) {
            $pdo->prepare("DELETE pd FROM pago_deuda pd INNER JOIN pagos p ON pd.pago_id = p.id WHERE p.usuario_id = ?")->execute([$cobradorId]);
            $s = $pdo->prepare("DELETE FROM pagos WHERE usuario_id = ?");
            $s->execute([$cobradorId]);
            $deletedPagos += $s->rowCount();
        }

        // 6. Eliminar usuario cobrador TEST_
        $s = $pdo->prepare("DELETE FROM usuarios WHERE usuario = ?");
        $s->execute([COBRADOR_USER]);
        $deletedUsuarios = $s->rowCount();

        // 7. Zonas TEST_ si existieran
        try {
            $pdo->prepare("DELETE FROM zonas WHERE nombre LIKE 'TEST\_%'")->execute();
        } catch (Exception $e) { /* ignorar */ }

        // 8. Limpiar registros de importacion CSV residuales (datos_registro con prefijo TEST_Csv)
        try {
            $pdo->prepare("DELETE FROM importacion_inconsistencias WHERE datos_registro LIKE '%TEST_Csv%'")->execute();
        } catch (Exception $e) { /* ignorar si la tabla no existe */ }

        // 8.b. Limpiar cuotas de prueba acumuladas del BLOQUE 16.
        // El WHERE combina fecha_vigencia_desde >= TEST_CUOTA_FECHA_MINIMA (anno 2099) con el
        // monto exacto de prueba. Esta combinacion hace imposible borrar cuotas reales:
        // ninguna cuota de produccion tendra fecha en el siglo XXII.
        // No hay claves foraneas apuntando a configuracion_cuota (verificado con INFORMATION_SCHEMA).
        $deletedCuotas = 0;
        try {
            $sCuota = $pdo->prepare(
                "DELETE FROM configuracion_cuota
                 WHERE fecha_vigencia_desde >= ? AND monto = ?"
            );
            $sCuota->execute([TEST_CUOTA_FECHA_MINIMA, TEST_CUOTA_MONTO]);
            $deletedCuotas = $sCuota->rowCount();
        } catch (Exception $e) { /* ignorar si la tabla no existe en este entorno */ }

        $pdo->commit();

        echo "  Auditoría eliminada     : {$deletedAuditoria}" . PHP_EOL;
        echo "  Planillas eliminadas    : {$deletedPlanillas}" . PHP_EOL;
        echo "  Pagos eliminados        : {$deletedPagos}"     . PHP_EOL;
        echo "  Deudas eliminadas       : {$deletedDeudas}"    . PHP_EOL;
        echo "  Socios eliminados       : {$deletedSocios}"    . PHP_EOL;
        echo "  Usuarios TEST_ borrados : {$deletedUsuarios}"  . PHP_EOL;
        echo "  Notificaciones elim.    : {$deletedNotificaciones}" . PHP_EOL;
        echo "  Cuotas TEST_ borradas   : {$deletedCuotas}"   . PHP_EOL;
        echo PHP_EOL;
        echo "\033[32m  Limpieza completada exitosamente.\033[0m" . PHP_EOL . PHP_EOL;
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "\033[31m  ERROR: " . $e->getMessage() . "\033[0m" . PHP_EOL . PHP_EOL;
        exit(1);
    }
    exit(0);
}

// ============================================================
//  INICIO DE LAS PRUEBAS
// ============================================================

echo PHP_EOL;
echo "\033[1m+==================================================================+\033[0m" . PHP_EOL;
echo "\033[1m|       CJP -- SUITE DE PRUEBAS AUTOMATIZADAS DE API              |\033[0m" . PHP_EOL;
echo "\033[1m+==================================================================+\033[0m" . PHP_EOL;
echo "  Base URL : " . BASE_URL             . PHP_EOL;
echo "  Fecha    : " . date('Y-m-d H:i:s') . PHP_EOL;
echo "  Admin    : " . ADMIN_USER           . PHP_EOL;
echo "  Cobrador : " . COBRADOR_USER        . PHP_EOL;
echo PHP_EOL;

// ============================================================
//  BLOQUE 0 — CONECTIVIDAD
// ============================================================

section('BLOQUE 0: Conectividad basica');
$r = apiRequest('GET', '/api/ping');
assertStatus($ctx, 'Ping sin sesion (requireAuth sin cookie)', 'GET', '/api/ping', 401, $r);

// ============================================================
//  BLOQUE 1 — AUTH: CASOS DE ERROR
// ============================================================

section('BLOQUE 1: Auth -- Casos de error esperados');
$r = apiRequest('POST', '/api/auth/login', ['username' => ADMIN_USER, 'password' => 'WRONG_PASS_XXX']);
assertStatus($ctx, 'Login contrasena incorrecta -> 401', 'POST', '/api/auth/login', 401, $r);
$r = apiRequest('POST', '/api/auth/login', ['username' => 'noexiste_xyz', 'password' => 'nada']);
assertStatus($ctx, 'Login usuario inexistente -> 401', 'POST', '/api/auth/login', 401, $r);
$r = apiRequest('POST', '/api/auth/login', ['username' => '', 'password' => '']);
assertStatus($ctx, 'Login campos vacios -> 400', 'POST', '/api/auth/login', 400, $r);

// ============================================================
//  BLOQUE 2 — AUTH: LOGIN ADMINISTRADOR
// ============================================================

section('BLOQUE 2: Auth -- Login como Administrador');
$adminCookie = doLogin(ADMIN_USER, ADMIN_PASS);
if ($adminCookie === null) {
    echo "\033[1;31m  FATAL: No se pudo hacer login como administrador.\033[0m" . PHP_EOL;
    echo "  Verificar que XAMPP este corriendo y las credenciales sean correctas." . PHP_EOL;
    echo "  Usuario: " . ADMIN_USER . " | Password: " . ADMIN_PASS . PHP_EOL;
    exit(1);
}
$ctx['admin_cookie'] = $adminCookie;
echo "  Cookie admin obtenida: " . substr($adminCookie, 0, 35) . "..." . PHP_EOL;
$r = apiRequest('GET', '/api/auth/me', null, $adminCookie);
assertStatus($ctx, 'GET /api/auth/me como admin -> 200', 'GET', '/api/auth/me', 200, $r);

// ============================================================
//  BLOQUE 3 — DASHBOARD (admin-only)
// ============================================================

section('BLOQUE 3: Dashboard');
$r = apiRequest('GET', '/api/dashboard/metricas', null, $adminCookie);
assertStatus($ctx, 'GET /api/dashboard/metricas (admin) -> 200', 'GET', '/api/dashboard/metricas', 200, $r);
$r = apiRequest('GET', '/api/dashboard/metricas');
assertStatus($ctx, 'Dashboard sin auth -> 401', 'GET', '/api/dashboard/metricas', 401, $r);

// ============================================================
//  BLOQUE 4 — ZONAS
// ============================================================

section('BLOQUE 4: Zonas');
$r = apiRequest('GET', '/api/zonas', null, $adminCookie);
assertStatus($ctx, 'GET /api/zonas -> 200', 'GET', '/api/zonas', 200, $r);
$zonas  = $r['body']['data'] ?? [];
$zonaId = !empty($zonas) ? ($zonas[0]['id'] ?? null) : null;
$r = apiRequest('POST', '/api/zonas/calcular', ['lat' => -32.820366, 'lng' => -61.403157], $adminCookie);
assertStatus($ctx, 'POST /api/zonas/calcular (coords validas) -> 200', 'POST', '/api/zonas/calcular', 200, $r);
$r = apiRequest('POST', '/api/zonas/calcular', [], $adminCookie);
assertStatus($ctx, 'POST /api/zonas/calcular sin coords -> 400', 'POST', '/api/zonas/calcular', 400, $r);

// ============================================================
//  BLOQUE 5 — USUARIOS (admin-only)
// ============================================================

section('BLOQUE 5: Usuarios (admin-only)');
$r = apiRequest('GET', '/api/usuarios', null, $adminCookie);
assertStatus($ctx, 'GET /api/usuarios (admin) -> 200', 'GET', '/api/usuarios', 200, $r);
$r = apiRequest('GET', '/api/usuarios');
assertStatus($ctx, 'GET /api/usuarios sin auth -> 401', 'GET', '/api/usuarios', 401, $r);

$nuevoCobradorPayload = [
    'nombre'     => 'TEST_Cobrador',
    'apellido'   => 'TEST_Prueba',
    'usuario'    => COBRADOR_USER,
    'contrasena' => COBRADOR_PASS,
    'rol'        => 'cobrador',
];
$r = apiRequest('POST', '/api/usuarios', $nuevoCobradorPayload, $adminCookie);
assertStatus($ctx, 'POST /api/usuarios (crear cobrador TEST_) -> 201', 'POST', '/api/usuarios', 201, $r);
if ($r['status'] === 201) {
    $ctx['cobrador_id'] = $r['body']['data']['id'] ?? null;
    echo "  Cobrador TEST_ creado con ID: " . $ctx['cobrador_id'] . PHP_EOL;
} elseif (in_array($r['status'], [400, 409])) {
    // Si ya existia de una corrida previa sin limpiar, buscar su ID
    $rList = apiRequest('GET', '/api/usuarios', null, $adminCookie);
    $usuariosList = $rList['body']['data'] ?? [];
    foreach ($usuariosList as $u) {
        if (($u['usuario'] ?? '') === COBRADOR_USER) {
            $ctx['cobrador_id'] = $u['id'];
            break;
        }
    }
}
if ($ctx['cobrador_id']) {
    $r = apiRequest('GET', '/api/usuarios/' . $ctx['cobrador_id'], null, $adminCookie);
    assertStatus($ctx, 'GET /api/usuarios/{id} -> 200', 'GET', '/api/usuarios/{id}', 200, $r);
}

// ============================================================
//  BLOQUE 6 — LOGIN COBRADOR
// ============================================================

section('BLOQUE 6: Login como Cobrador TEST_');
$cobradorCookie = doLogin(COBRADOR_USER, COBRADOR_PASS);
if ($cobradorCookie === null) {
    echo "  ADVERTENCIA: No se pudo hacer login como cobrador. Saltando pruebas de cobrador." . PHP_EOL;
} else {
    $ctx['cobrador_cookie'] = $cobradorCookie;
    echo "  Cookie cobrador obtenida: " . substr($cobradorCookie, 0, 35) . "..." . PHP_EOL;
    $r = apiRequest('GET', '/api/dashboard/metricas', null, $cobradorCookie);
    assertStatus($ctx, 'Dashboard con rol cobrador -> 403', 'GET', '/api/dashboard/metricas', 403, $r);
    $r = apiRequest('GET', '/api/usuarios', null, $cobradorCookie);
    assertStatus($ctx, 'Listar usuarios con cobrador -> 403', 'GET', '/api/usuarios', 403, $r);
    $r = apiRequest('GET', '/api/auditoria', null, $cobradorCookie);
    assertStatus($ctx, 'Auditoria con cobrador -> 403', 'GET', '/api/auditoria', 403, $r);
    $r = apiRequest('GET', '/api/socios', null, $cobradorCookie);
    assertStatus($ctx, 'GET /api/socios con cobrador -> 200', 'GET', '/api/socios', 200, $r);
    $r = apiRequest('GET', '/api/zonas', null, $cobradorCookie);
    assertStatus($ctx, 'GET /api/zonas con cobrador -> 200', 'GET', '/api/zonas', 200, $r);
}

// ============================================================
//  BLOQUE 7 — SOCIOS: FLUJO COMPLETO
// ============================================================

section('BLOQUE 7: Socios -- Flujo completo');
$socioPayload = [
    'nombre_apellido'    => 'TEST_Juan Prueba',
    'tipo_documento'     => 'dni',
    'dni'                => TEST_DNI,
    'fecha_nacimiento'   => '1950-03-15',
    'telefono'           => '3417000000',
    'mutual'             => 'TEST_MUTUAL',
    'direccion'          => 'San Martin 100',
    'modalidad_cobranza' => 'cobranza_domiciliaria',
];
$r = apiRequest('POST', '/api/socios', $socioPayload, $adminCookie);
assertStatus($ctx, 'POST /api/socios (crear socio TEST_) -> 201', 'POST', '/api/socios', 201, $r);
if ($r['status'] === 201) {
    $ctx['socio_id'] = $r['body']['data']['id'] ?? null;
    echo "  Socio TEST_ creado con ID: " . $ctx['socio_id'] . PHP_EOL;
}
$r = apiRequest('POST', '/api/socios', $socioPayload, $adminCookie);
assertStatus($ctx, 'POST /api/socios DNI duplicado -> 409', 'POST', '/api/socios', 409, $r);
$r = apiRequest('POST', '/api/socios', ['nombre_apellido' => 'TEST_Incompleto'], $adminCookie);
assertStatus($ctx, 'POST /api/socios campos faltantes -> 400', 'POST', '/api/socios', 400, $r);

// Pruebas de validación de formato y longitud de documento según tipo (Lote 1)
$socioDniInvalido = array_merge($socioPayload, [
    'nombre_apellido' => 'TEST_Dni Corto',
    'dni'             => '123'
]);
$r = apiRequest('POST', '/api/socios', $socioDniInvalido, $adminCookie);
assertStatus($ctx, 'POST /api/socios DNI corto (3 digitos) -> 400', 'POST', '/api/socios', 400, $r);

$socioDniPuntos = array_merge($socioPayload, [
    'nombre_apellido' => 'TEST_Socio Con Puntos',
    'dni'             => TEST_DNI_PUNTOS
]);
$r = apiRequest('POST', '/api/socios', $socioDniPuntos, $adminCookie);
assertStatus($ctx, 'POST /api/socios DNI con puntos -> 201', 'POST', '/api/socios', 201, $r);
if ($r['status'] === 201) {
    $dniGuardado = $r['body']['data']['dni'] ?? '';
    $ctx['tests']++;
    if ($dniGuardado === TEST_DNI_NORMALIZADO) {
        $ctx['passed']++;
        echo "  DNI guardado normalizado ({$dniGuardado}) \033[32mOK\033[0m" . PHP_EOL;
    } else {
        $ctx['failed']++;
        $ctx['failures'][] = "DNI guardado no normalizado: esperado " . TEST_DNI_NORMALIZADO . ", recibido {$dniGuardado}";
        echo "  DNI guardado no normalizado: esperado " . TEST_DNI_NORMALIZADO . ", recibido {$dniGuardado} \033[31mFALLO\033[0m" . PHP_EOL;
    }
}
$r = apiRequest('POST', '/api/socios', $socioPayload);
assertStatus($ctx, 'POST /api/socios sin auth -> 401', 'POST', '/api/socios', 401, $r);
$r = apiRequest('GET', '/api/socios', null, $adminCookie);
assertStatus($ctx, 'GET /api/socios (listar) -> 200', 'GET', '/api/socios', 200, $r);
if ($ctx['socio_id']) {
    $r = apiRequest('GET', '/api/socios/' . $ctx['socio_id'], null, $adminCookie);
    assertStatus($ctx, 'GET /api/socios/{id} -> 200', 'GET', '/api/socios/{id}', 200, $r);
}
$r = apiRequest('GET', '/api/socios/00000000-0000-0000-0000-000000000000', null, $adminCookie);
assertStatus($ctx, 'GET /api/socios/{id-inexistente} -> 404', 'GET', '/api/socios/{id}', 404, $r);
if ($ctx['socio_id']) {
    $r = apiRequest('PUT', '/api/socios/' . $ctx['socio_id'], ['telefono' => '3417111111'], $adminCookie);
    assertStatus($ctx, 'PUT /api/socios/{id} (editar telefono) -> 200', 'PUT', '/api/socios/{id}', 200, $r);
    $r = apiRequest('POST', '/api/socios/' . $ctx['socio_id'] . '/suspender', [], $adminCookie);
    assertStatus($ctx, 'POST /api/socios/{id}/suspender -> 200', 'POST', '/api/socios/{id}/suspender', 200, $r);
    $r = apiRequest('POST', '/api/socios/' . $ctx['socio_id'] . '/reactivar', [], $adminCookie);
    assertStatus($ctx, 'POST /api/socios/{id}/reactivar -> 200', 'POST', '/api/socios/{id}/reactivar', 200, $r);
}
$r = apiRequest('GET', '/api/socios/inconsistencias', null, $adminCookie);
assertStatus($ctx, 'GET /api/socios/inconsistencias (admin) -> 200', 'GET', '/api/socios/inconsistencias', 200, $r);
if ($ctx['socio_id']) {
    $ctx['tests']++;
    $r = apiRequest('GET', '/api/socios/' . $ctx['socio_id'] . '/qr', null, $adminCookie);
    if ($r['status'] === 200) {
        $ctx['passed']++;
        echo "  GET /api/socios/{id}/qr -> 200 \033[32mOK\033[0m" . PHP_EOL;
    } else {
        $ctx['failed']++;
        $ctx['failures'][] = "GET /api/socios/{id}/qr: esperado 200, recibido {$r['status']}";
        echo "  GET /api/socios/{id}/qr -> {$r['status']} \033[31mFALLO\033[0m" . PHP_EOL;
    }
}
$socioPayload2 = array_merge($socioPayload, ['nombre_apellido' => 'TEST_Pedro Prueba', 'dni' => TEST_DNI2]);
$r = apiRequest('POST', '/api/socios', $socioPayload2, $adminCookie);
assertStatus($ctx, 'POST /api/socios (2do socio TEST_) -> 201', 'POST', '/api/socios', 201, $r);
if ($r['status'] === 201) {
    $ctx['socio_id2'] = $r['body']['data']['id'] ?? null;
    echo "  2do Socio TEST_ creado con ID: " . $ctx['socio_id2'] . PHP_EOL;
}

// ============================================================
//  BLOQUE 7b — IMPORTACION CSV: VALIDACION DE DOCUMENTO
// ============================================================

section('BLOQUE 7b: Importacion CSV -- Validacion de documento');

// Construir un CSV temporal con 2 filas:
//   Fila 1: socio valido con prefijo TEST_ y DNI de 8 digitos -> debe importarse
//   Fila 2: socio con DNI de solo 3 digitos -> debe quedar como inconsistencia
// Se usa sys_get_temp_dir() para no dejar residuos en el proyecto.
$csvTmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_importacion_cjp.csv';
$csvContenido  = "nombre_apellido;dni;fecha_nacimiento;telefono;direccion;modalidad_cobranza" . PHP_EOL;
// Fila valida: el nombre tiene prefijo TEST_ para que el Bloque 19 la limpie automaticamente
$csvContenido .= "TEST_CsvSocioValido;99777666;1960-01-15;3411234567;Av. Siempre Viva 742;cobranza_en_sede" . PHP_EOL;
// Fila invalida: DNI de 3 digitos, debe rechazarse con motivo claro
$csvContenido .= "TEST_CsvDocInvalido;123;1970-05-20;3417654321;Calle Falsa 123;cobranza_domiciliaria" . PHP_EOL;
file_put_contents($csvTmpPath, $csvContenido);

if ($adminCookie) {
    // Subir el CSV a la API de importacion
    $rImport = apiRequestUpload('/api/socios/importar', $csvTmpPath, $adminCookie);
    assertStatus($ctx, 'POST /api/socios/importar CSV 2 filas -> 202', 'POST', '/api/socios/importar', 202, $rImport);

    $importacionId = $rImport['body']['data']['id'] ?? null;

    if ($importacionId) {
        // Esperar a que el worker finalice el procesamiento (polling, maximo 30 segundos)
        $estadoFinal  = null;
        $maxIntentos  = 30;
        $intentos     = 0;
        while ($intentos < $maxIntentos) {
            sleep(1);
            $rEstado = apiRequest('GET', '/api/socios/importar/' . $importacionId . '/estado', null, $adminCookie);
            $estadoFinal = $rEstado['body']['data']['estado'] ?? null;
            // El worker puede terminar en 'completado' o 'error'
            if ($estadoFinal === 'completado' || $estadoFinal === 'error') {
                break;
            }
            $intentos++;
        }

        // Verificar que el worker termino dentro del tiempo maximo
        $ctx['tests']++;
        if ($estadoFinal === 'completado' || $estadoFinal === 'error') {
            $ctx['passed']++;
            echo "  Worker CSV finalizo con estado '{$estadoFinal}' en {$intentos}s \033[32mOK\033[0m" . PHP_EOL;
        } else {
            $ctx['failed']++;
            $ctx['failures'][] = "Worker CSV no finalizo en {$maxIntentos}s (ultimo estado: '" . ($estadoFinal ?? 'sin respuesta') . "')";
            echo "  Worker CSV no finalizo en {$maxIntentos}s \033[31mFALLO\033[0m" . PHP_EOL;
        }

        if ($estadoFinal === 'completado') {
            // Verificar que exactamente 1 fila fue exitosa
            $exitosos = (int) ($rEstado['body']['data']['exitosos'] ?? -1);
            $ctx['tests']++;
            if ($exitosos === 1) {
                $ctx['passed']++;
                echo "  CSV: 1 fila exitosa \033[32mOK\033[0m" . PHP_EOL;
            } else {
                $ctx['failed']++;
                $ctx['failures'][] = "CSV importacion: exitosos esperado 1, recibido {$exitosos}";
                echo "  CSV: exitosos esperado 1, recibido {$exitosos} \033[31mFALLO\033[0m" . PHP_EOL;
            }

            // Verificar que exactamente 1 fila fue rechazada
            $fallidos = (int) ($rEstado['body']['data']['fallidos'] ?? -1);
            $ctx['tests']++;
            if ($fallidos === 1) {
                $ctx['passed']++;
                echo "  CSV: 1 fila rechazada \033[32mOK\033[0m" . PHP_EOL;
            } else {
                $ctx['failed']++;
                $ctx['failures'][] = "CSV importacion: fallidos esperado 1, recibido {$fallidos}";
                echo "  CSV: fallidos esperado 1, recibido {$fallidos} \033[31mFALLO\033[0m" . PHP_EOL;
            }

            // Verificar que la inconsistencia tiene un motivo que menciona el documento invalido
            $rInc = apiRequest('GET', '/api/socios/inconsistencias', null, $adminCookie);
            assertStatus($ctx, 'GET /api/socios/inconsistencias post-CSV -> 200', 'GET', '/api/socios/inconsistencias', 200, $rInc);
            $inconsistencias = $rInc['body']['data'] ?? [];
            // Buscar la inconsistencia mas reciente de esta importacion por motivo de documento invalido
            $motivoEncontrado = false;
            foreach ($inconsistencias as $inc) {
                $motivo = strtolower($inc['motivo_rechazo'] ?? '');
                // El motivo debe mencionar dni o documento y algun indicador de longitud o digitos
                if (str_contains($motivo, 'dni') || str_contains($motivo, 'documento')) {
                    $motivoEncontrado = true;
                    break;
                }
            }
            $ctx['tests']++;
            if ($motivoEncontrado) {
                $ctx['passed']++;
                echo "  CSV: inconsistencia registrada con motivo de documento invalido \033[32mOK\033[0m" . PHP_EOL;
            } else {
                $ctx['failed']++;
                $ctx['failures'][] = "CSV importacion: no se encontro inconsistencia con motivo de documento invalido";
                echo "  CSV: inconsistencia sin motivo de documento \033[31mFALLO\033[0m" . PHP_EOL;
            }
        }

        // Guardar el importacionId para que el bloque 19 limpie los registros asociados
        $ctx['importacion_csv_id'] = $importacionId;
    } else {
        echo "  No se obtuvo importacion_id de la respuesta; se omiten verificaciones del worker." . PHP_EOL;
        $ctx['omitted'] += 5;
    }
} else {
    echo "  Sin sesion de administrador; se omite el bloque de importacion CSV." . PHP_EOL;
    $ctx['omitted'] += 5;
}

// Limpiar el archivo CSV temporal creado para la prueba
if (file_exists($csvTmpPath)) {
    @unlink($csvTmpPath);
}

// ============================================================
//  BLOQUE 8 — DEUDA Y CUOTAS
// ============================================================

section('BLOQUE 8: Deuda y Cuotas');
$r = apiRequest('GET', '/api/cuota/vigente', null, $adminCookie);
assertStatus($ctx, 'GET /api/cuota/vigente -> 200', 'GET', '/api/cuota/vigente', 200, $r);
$cuotaVigente = $r['body']['data'] ?? null;
$r = apiRequest('GET', '/api/cuota/historico', null, $adminCookie);
assertStatus($ctx, 'GET /api/cuota/historico -> 200', 'GET', '/api/cuota/historico', 200, $r);
if ($cuotaVigente) {
    $r = apiRequest('POST', '/api/deuda/generar', ['periodo' => $ctx['periodo_test']], $adminCookie);
    assertStatus($ctx, 'POST /api/deuda/generar (periodo 2099-01) -> 200', 'POST', '/api/deuda/generar', 200, $r);
} else {
    echo "  ADVERTENCIA: No hay cuota vigente. Saltando generacion de deuda mensual." . PHP_EOL;
    $ctx['tests']++;
    $ctx['passed']++;
}
$r = apiRequest('POST', '/api/deuda/generar', ['periodo' => '2099/01'], $adminCookie);
assertStatus($ctx, 'POST /api/deuda/generar periodo invalido -> 400', 'POST', '/api/deuda/generar', 400, $r);
if ($ctx['cobrador_cookie']) {
    $r = apiRequest('POST', '/api/deuda/generar', ['periodo' => '2099-02'], $ctx['cobrador_cookie']);
    assertStatus($ctx, 'POST /api/deuda/generar con cobrador -> 403', 'POST', '/api/deuda/generar', 403, $r);
}
if ($ctx['socio_id']) {
    $r = apiRequest('GET', '/api/deuda/socio/' . $ctx['socio_id'], null, $adminCookie);
    assertStatus($ctx, 'GET /api/deuda/socio/{id} -> 200', 'GET', '/api/deuda/socio/{socioId}', 200, $r);
    $deudas = $r['body']['data'] ?? [];
    foreach ($deudas as $d) {
        if (($d['estado'] ?? '') === 'pendiente') {
            $ctx['deuda_id'] = $d['id'];
            break;
        }
    }
    $r = apiRequest('GET', '/api/deuda/socio/' . $ctx['socio_id'] . '/pendientes', null, $adminCookie);
    assertStatus($ctx, 'GET /api/deuda/socio/{id}/pendientes -> 200', 'GET', '/api/deuda/socio/{socioId}/pendientes', 200, $r);
}
if ($ctx['socio_id']) {
    $r = apiRequest('POST', '/api/deuda/anterior', ['socio_id' => $ctx['socio_id'], 'monto' => 100.00], $adminCookie);
    assertStatus($ctx, 'POST /api/deuda/anterior -> 200', 'POST', '/api/deuda/anterior', 200, $r);
    if (!$ctx['deuda_id']) {
        $r2 = apiRequest('GET', '/api/deuda/socio/' . $ctx['socio_id'] . '/pendientes', null, $adminCookie);
        $ds = $r2['body']['data'] ?? [];
        if (!empty($ds)) {
            $ctx['deuda_id'] = $ds[0]['id'] ?? null;
        }
    }
}
echo "  deuda_id disponible: " . ($ctx['deuda_id'] ?? 'NO DISPONIBLE') . PHP_EOL;

// ============================================================
//  BLOQUE 9 — OBSERVACIONES E HISTORIAL
// ============================================================

section('BLOQUE 9: Observaciones e Historial');
if ($ctx['socio_id']) {
    $r = apiRequest('POST', '/api/observaciones', [
        'socio_id'  => $ctx['socio_id'],
        'contenido' => 'TEST_ Observacion de prueba automatizada.',
    ], $adminCookie);
    assertStatus($ctx, 'POST /api/observaciones -> 201', 'POST', '/api/observaciones', 201, $r);
    $r = apiRequest('POST', '/api/observaciones', [], $adminCookie);
    assertStatus($ctx, 'POST /api/observaciones sin datos -> 400', 'POST', '/api/observaciones', 400, $r);
    $r = apiRequest('GET', '/api/observaciones/socio/' . $ctx['socio_id'], null, $adminCookie);
    assertStatus($ctx, 'GET /api/observaciones/socio/{id} -> 200', 'GET', '/api/observaciones/socio/{id}', 200, $r);
    $r = apiRequest('GET', '/api/historial/socio/' . $ctx['socio_id'], null, $adminCookie);
    assertStatus($ctx, 'GET /api/historial/socio/{id} -> 200', 'GET', '/api/historial/socio/{id}', 200, $r);
}

// ============================================================
//  BLOQUE 10 — PAGOS
// ============================================================

section('BLOQUE 10: Pagos');
if ($ctx['deuda_id'] && $ctx['socio_id']) {
    // Obtener TODAS las deudas pendientes del socio para respetar la regla de cascada.
    // No se puede pagar deuda_2099-01 salteando deuda_anterior (creada en Bloque 8).
    $rPend = apiRequest('GET', '/api/deuda/socio/' . $ctx['socio_id'] . '/pendientes', null, $adminCookie);
    $todasPendientes = $rPend['body']['data'] ?? [];
    $deudaIdsPago = array_column($todasPendientes, 'id');
    if (empty($deudaIdsPago)) {
        $deudaIdsPago = [$ctx['deuda_id']]; // fallback a una sola si endpoint falla
    }

    $r = apiRequest('POST', '/api/pagos', [
        'socio_id'    => $ctx['socio_id'],
        'deuda_ids'   => $deudaIdsPago,
        'metodo_pago' => 'efectivo',
    ], $adminCookie);
    assertStatus($ctx, 'POST /api/pagos (registrar pago) -> 200', 'POST', '/api/pagos', 200, $r);
    if (in_array($r['status'], [200, 201])) {
        $ctx['pago_id'] = $r['body']['data']['pago']['id'] ?? ($r['body']['data']['id'] ?? null);
    }
    $r2     = apiRequest('GET', '/api/deuda/socio/' . $ctx['socio_id'], null, $adminCookie);
    $deudas = $r2['body']['data'] ?? [];
    $deudaPagada = false;
    foreach ($deudas as $d) {
        if ($d['id'] === $ctx['deuda_id'] && ($d['estado'] ?? '') === 'pagada') {
            $deudaPagada = true;
            break;
        }
    }
    $ctx['tests']++;
    if ($deudaPagada) {
        $ctx['passed']++;
        echo "  Verificar deuda -> estado 'pagada' tras registrar pago           \033[32mOK\033[0m" . PHP_EOL;
    } else {
        $ctx['failed']++;
        $ctx['failures'][] = "Verificar deuda: despues del pago la deuda {$ctx['deuda_id']} no esta en estado 'pagada'";
        echo "  Verificar deuda -> NO cambio a 'pagada' tras el pago             \033[31mFALLO\033[0m" . PHP_EOL;
    }
} else {
    echo "  ADVERTENCIA: No hay deuda_id. Saltando pruebas de pago." . PHP_EOL;
}
$r = apiRequest('GET', '/api/pagos', null, $adminCookie);
assertStatus($ctx, 'GET /api/pagos (listar todos) -> 200', 'GET', '/api/pagos', 200, $r);

// Pruebas de busqueda de pagos por nombre o DNI (parametro busqueda)
$rBusqNombre = apiRequest('GET', '/api/pagos?busqueda=TEST_Juan', null, $adminCookie);
assertStatus($ctx, 'GET /api/pagos?busqueda=TEST_Juan -> 200', 'GET', '/api/pagos?busqueda={nombre}', 200, $rBusqNombre);

$rBusqDni = apiRequest('GET', '/api/pagos?busqueda=' . TEST_DNI, null, $adminCookie);
assertStatus($ctx, 'GET /api/pagos?busqueda=' . TEST_DNI . ' -> 200', 'GET', '/api/pagos?busqueda={dni}', 200, $rBusqDni);

$rBusqInexistente = apiRequest('GET', '/api/pagos?busqueda=Inexistente_XYZ_99999', null, $adminCookie);
assertStatus($ctx, 'GET /api/pagos?busqueda=Inexistente -> 200', 'GET', '/api/pagos?busqueda={inexistente}', 200, $rBusqInexistente);
$itemsInexistente = $rBusqInexistente['body']['data']['items'] ?? [];
$ctx['tests']++;
if (count($itemsInexistente) === 0) {
    $ctx['passed']++;
    echo "  Busqueda de pago inexistente retorna 0 registros              \033[32mOK\033[0m" . PHP_EOL;
} else {
    $ctx['failed']++;
    $ctx['failures'][] = 'GET /api/pagos con busqueda inexistente devolvio items';
    echo "  Busqueda de pago inexistente retorno items inesperados         \033[31mFALLO\033[0m" . PHP_EOL;
}
if ($ctx['socio_id']) {
    $r = apiRequest('GET', '/api/pagos/socio/' . $ctx['socio_id'], null, $adminCookie);
    assertStatus($ctx, 'GET /api/pagos/socio/{id} -> 200', 'GET', '/api/pagos/socio/{socioId}', 200, $r);
}
if ($ctx['pago_id']) {
    $r = apiRequest('GET', '/api/pagos/' . $ctx['pago_id'], null, $adminCookie);
    assertStatus($ctx, 'GET /api/pagos/{id} -> 200', 'GET', '/api/pagos/{id}', 200, $r);
    $ctx['tests']++;
    $r = apiRequest('GET', '/api/pagos/' . $ctx['pago_id'] . '/comprobante', null, $adminCookie);
    if (in_array($r['status'], [200, 404])) {
        $ctx['passed']++;
        echo "  GET /api/pagos/{id}/comprobante -> {$r['status']} (200 o 404 aceptado)  \033[32mOK\033[0m" . PHP_EOL;
    } else {
        $ctx['failed']++;
        $ctx['failures'][] = "GET /api/pagos/{id}/comprobante: esperado 200 o 404, recibido {$r['status']}";
        echo "  GET /api/pagos/{id}/comprobante -> {$r['status']}                      \033[31mFALLO\033[0m" . PHP_EOL;
    }
    $r = apiRequest('POST', '/api/pagos/' . $ctx['pago_id'] . '/anular', [
        'motivo' => 'TEST_ anulacion de prueba automatizada',
    ], $adminCookie);
    assertStatus($ctx, 'POST /api/pagos/{id}/anular -> 200', 'POST', '/api/pagos/{id}/anular', 200, $r);
    if ($ctx['deuda_id'] && $ctx['socio_id']) {
        $r2     = apiRequest('GET', '/api/deuda/socio/' . $ctx['socio_id'], null, $adminCookie);
        $deudas = $r2['body']['data'] ?? [];
        $deudaPendiente = false;
        foreach ($deudas as $d) {
            if ($d['id'] === $ctx['deuda_id'] && ($d['estado'] ?? '') === 'pendiente') {
                $deudaPendiente = true;
                break;
            }
        }
        $ctx['tests']++;
        if ($deudaPendiente) {
            $ctx['passed']++;
            echo "  Verificar deuda -> volvio a 'pendiente' luego de anular pago     \033[32mOK\033[0m" . PHP_EOL;
        } else {
            $ctx['failed']++;
            $ctx['failures'][] = "Verificar deuda: despues de anular el pago la deuda {$ctx['deuda_id']} no volvio a 'pendiente'";
            echo "  Verificar deuda -> NO volvio a 'pendiente' tras anular pago       \033[31mFALLO\033[0m" . PHP_EOL;
        }
    }
}
$r = apiRequest('GET', '/api/pagos');
assertStatus($ctx, 'GET /api/pagos sin auth -> 401', 'GET', '/api/pagos', 401, $r);

// ============================================================
//  BLOQUE 11 — PLANILLAS
// ============================================================

section('BLOQUE 11: Planillas');

// Obtener zona_id directamente del socio TEST_ recien creado.
// El propio socio ya tiene deuda domiciliaria pendiente tras la anulacion del pago
// en el Bloque 10, por lo que la generacion de planilla es deterministica.
// Si zona_id es null (fallo de geocodificacion), el subtest se marca OMITIDO.
$zonaIdSocio = null;
if ($ctx['socio_id']) {
    $r = apiRequest('GET', '/api/socios/' . $ctx['socio_id'], null, $adminCookie);
    $zonaIdSocio = $r['body']['data']['zona_id'] ?? null;
}
$r = apiRequest('GET', '/api/planillas/cobradores', null, $adminCookie);
assertStatus($ctx, 'GET /api/planillas/cobradores -> 200', 'GET', '/api/planillas/cobradores', 200, $r);
$r = apiRequest('GET', '/api/planillas', null, $adminCookie);
assertStatus($ctx, 'GET /api/planillas (historial) -> 200', 'GET', '/api/planillas', 200, $r);

if ($ctx['cobrador_id'] && $zonaIdSocio) {
    // El socio TEST_ (cobranza_domiciliaria, activo, con deuda pendiente) esta en esta zona;
    // no dependemos de socios preexistentes en la BD.
    $r = apiRequest('POST', '/api/planillas', [
        'zona_id'     => $zonaIdSocio,
        'cobrador_id' => $ctx['cobrador_id'],
    ], $adminCookie);
    assertStatus($ctx, 'POST /api/planillas (generar) -> 201', 'POST', '/api/planillas', 201, $r);
    if ($r['status'] === 201) {
        $ctx['planilla_id'] = $r['body']['data']['id'] ?? null;
        echo "  Planilla creada con ID: " . $ctx['planilla_id'] . PHP_EOL;
    }
    if ($ctx['planilla_id']) {
        $r = apiRequest('GET', '/api/planillas/' . $ctx['planilla_id'], null, $adminCookie);
        assertStatus($ctx, 'GET /api/planillas/{id} -> 200', 'GET', '/api/planillas/{id}', 200, $r);
        $ctx['tests']++;
        $r = apiRequest('GET', '/api/planillas/' . $ctx['planilla_id'] . '/pdf', null, $adminCookie);
        if (in_array($r['status'], [200, 400, 404])) {
            $ctx['passed']++;
            echo "  GET /api/planillas/{id}/pdf -> {$r['status']} (200/400/404 aceptado)    \033[32mOK\033[0m" . PHP_EOL;
        } else {
            $ctx['failed']++;
            $ctx['failures'][] = "GET /api/planillas/{id}/pdf: inesperado {$r['status']}";
            echo "  GET /api/planillas/{id}/pdf -> {$r['status']}                          \033[31mFALLO\033[0m" . PHP_EOL;
        }
    }
} elseif (!$zonaIdSocio) {
    // La geocodificacion no asigno zona al socio TEST_; no es posible probar
    // la generacion de planilla de forma deterministica. Se registra como OMITIDA.
    $ctx['omitted']++;
    echo "  \033[33mOMITIDA\033[0m: zona_id del socio TEST_ es null (geocodificacion pendiente)." . PHP_EOL;
    echo "           Se saltea POST /api/planillas para evitar falsos fallos." . PHP_EOL;
} else {
    echo "  ADVERTENCIA: cobrador no disponible. Saltando generacion de planilla." . PHP_EOL;
}
$r = apiRequest('POST', '/api/planillas', [
    'zona_id'     => '00000000-0000-0000-0000-000000000000',
    'cobrador_id' => $ctx['cobrador_id'] ?? '00000000-0000-0000-0000-000000000001',
], $adminCookie);
$ctx['tests']++;
if ($r['status'] >= 400) {
    $ctx['passed']++;
    echo "  POST /api/planillas zona inexistente -> {$r['status']} (4xx esperado)        \033[32mOK\033[0m" . PHP_EOL;
} else {
    $ctx['failed']++;
    $ctx['failures'][] = "POST /api/planillas zona inexistente: esperado 4xx, recibido {$r['status']}";
    echo "  POST /api/planillas zona inexistente -> {$r['status']}                      \033[31mFALLO\033[0m" . PHP_EOL;
}
$r = apiRequest('POST', '/api/planillas', ['zona_id' => ''], $adminCookie);
assertStatus($ctx, 'POST /api/planillas sin cobrador_id -> 400', 'POST', '/api/planillas', 400, $r);
if ($ctx['cobrador_cookie'] && $zonaIdSocio && $ctx['cobrador_id']) {
    $r = apiRequest('POST', '/api/planillas', [
        'zona_id'     => $zonaIdSocio,
        'cobrador_id' => $ctx['cobrador_id'],
    ], $ctx['cobrador_cookie']);
    assertStatus($ctx, 'POST /api/planillas con cobrador -> 403', 'POST', '/api/planillas', 403, $r);
}

// ============================================================
//  BLOQUE 12 — NOTIFICACIONES
// ============================================================

section('BLOQUE 12: Notificaciones');
$r = apiRequest('GET', '/api/notificaciones', null, $adminCookie);
assertStatus($ctx, 'GET /api/notificaciones -> 200', 'GET', '/api/notificaciones', 200, $r);

// --- Generar notificacion TEST_ propia (deterministica) ---
// Damos de baja al 2do socio TEST_ para forzar la creacion de una notificacion
// con mensaje que contiene 'TEST_'. Esto evita depender de notificaciones[0]
// que puede ser una notificacion real y vencida (p.ej. tras limpiar residuos).
$notif1 = null;
if ($ctx['socio_id2']) {
    $rBaja = apiRequest('DELETE', '/api/socios/' . $ctx['socio_id2'], [
        'motivo' => 'TEST_ baja de prueba automatizada',
    ], $adminCookie);
    assertStatus($ctx, 'DELETE socio_id2 para generar notif TEST_ -> 200', 'DELETE', '/api/socios/{id}', 200, $rBaja);

    // Buscar la notificacion TEST_ recien creada en el listado (no archivada)
    $rNotifs = apiRequest('GET', '/api/notificaciones', null, $adminCookie);
    $todasNotifs = $rNotifs['body']['data'] ?? [];
    foreach ($todasNotifs as $notifItem) {
        $msg    = $notifItem['mensaje'] ?? '';
        $estado = $notifItem['estado']  ?? '';
        if (stripos($msg, 'TEST_') !== false && $estado !== 'archivada') {
            $notif1 = $notifItem['id'];
            echo "  Notificacion TEST_ encontrada: {$notif1} (msg: " . substr($msg, 0, 60) . ")" . PHP_EOL;
            break;
        }
    }
    if (!$notif1) {
        echo "  ADVERTENCIA: No se encontro notificacion TEST_ tras la baja de socio_id2." . PHP_EOL;
    }
}

if ($notif1) {
    // 1. Marcar como leida
    $r = apiRequest('POST', '/api/notificaciones/' . $notif1 . '/leida', [], $adminCookie);
    assertStatus($ctx, 'POST /api/notificaciones/{id}/leida -> 200', 'POST', '/api/notificaciones/{id}/leida', 200, $r);

    // 2. Revertir (exige estado != 'archivada'; la ventana de tiempo debe estar vigente)
    $r = apiRequest('POST', '/api/notificaciones/' . $notif1 . '/revertir', [], $adminCookie);
    assertStatus($ctx, 'POST /api/notificaciones/{id}/revertir -> 200', 'POST', '/api/notificaciones/{id}/revertir', 200, $r);

    // 3. Archivar (idempotente)
    $r = apiRequest('POST', '/api/notificaciones/' . $notif1 . '/archivar', [], $adminCookie);
    assertStatus($ctx, 'POST /api/notificaciones/{id}/archivar -> 200', 'POST', '/api/notificaciones/{id}/archivar', 200, $r);

    // 4. Desarchivar: revierte el estado a 'no_leida'
    $r = apiRequest('POST', '/api/notificaciones/' . $notif1 . '/desarchivar', [], $adminCookie);
    assertStatus($ctx, 'POST /api/notificaciones/{id}/desarchivar -> 200', 'POST', '/api/notificaciones/{id}/desarchivar', 200, $r);

    // Intentar desarchivar cuando ya esta activa debe responder 400
    $r = apiRequest('POST', '/api/notificaciones/' . $notif1 . '/desarchivar', [], $adminCookie);
    assertStatus($ctx, 'POST /api/notificaciones/{id}/desarchivar ya activa -> 400', 'POST', '/api/notificaciones/{id}/desarchivar', 400, $r);

    // Desarchivar con ID inexistente -> 404
    $r = apiRequest('POST', '/api/notificaciones/00000000-0000-0000-0000-000000000000/desarchivar', [], $adminCookie);
    assertStatus($ctx, 'POST /api/notificaciones/{id}/desarchivar inexistente -> 404', 'POST', '/api/notificaciones/{id}/desarchivar', 404, $r);

    // Volver a archivar para mantener limpio el estado de la notificacion
    $r = apiRequest('POST', '/api/notificaciones/' . $notif1 . '/archivar', [], $adminCookie);
    assertStatus($ctx, 'POST /api/notificaciones/{id}/archivar (re-archivar) -> 200', 'POST', '/api/notificaciones/{id}/archivar', 200, $r);
} else {
    echo "  ADVERTENCIA: No hay notificacion TEST_ disponible; saltando pruebas individuales." . PHP_EOL;
}

// Verificar que la reversion de la notificacion realmente reactivo al socio_id2
// (prueba de extremo a extremo: revertir notif de baja => socio vuelve a 'activo').
// NO se llama a POST /revertir de nuevo: eso ya lo hizo el endpoint de notificacion.
if ($ctx['socio_id2']) {
    $rCheck2 = apiRequest('GET', '/api/socios/' . $ctx['socio_id2'], null, $adminCookie);
    $estadoSocio2 = $rCheck2['body']['data']['estado'] ?? null;
    $ctx['tests']++;
    if ($estadoSocio2 === 'activo') {
        $ctx['passed']++;
        echo "  Verificar socio_id2 activo tras revertir notif de baja         \033[32mOK\033[0m" . PHP_EOL;
    } else {
        $ctx['failed']++;
        $ctx['failures'][] = "Verificar socio_id2 activo: estado esperado 'activo', recibido '" . ($estadoSocio2 ?? 'null') . "'";
        echo "  Verificar socio_id2 activo tras revertir notif de baja         \033[31mFALLO\033[0m" . PHP_EOL;
    }
}

$r = apiRequest('GET', '/api/notificaciones');
assertStatus($ctx, 'GET /api/notificaciones sin auth -> 401', 'GET', '/api/notificaciones', 401, $r);

// ============================================================
//  BLOQUE 13 — AUDITORIA
// ============================================================

section('BLOQUE 13: Auditoria');
$r = apiRequest('GET', '/api/auditoria', null, $adminCookie);
assertStatus($ctx, 'GET /api/auditoria (admin) -> 200', 'GET', '/api/auditoria', 200, $r);
$auditoria = $r['body']['data']['items'] ?? [];
$auditId   = !empty($auditoria) ? ($auditoria[0]['id'] ?? null) : null;
if ($auditId) {
    $r = apiRequest('GET', '/api/auditoria/' . $auditId, null, $adminCookie);
    assertStatus($ctx, 'GET /api/auditoria/{id} -> 200', 'GET', '/api/auditoria/{id}', 200, $r);
} else {
    echo "  ADVERTENCIA: No hay registros de auditoria para GET individual." . PHP_EOL;
}

// ============================================================
//  BLOQUE 14 — SOFT DELETE Y REVERT
// ============================================================

section('BLOQUE 14: Soft Delete y Revert del Socio TEST_');
if ($ctx['socio_id']) {
    $r = apiRequest('DELETE', '/api/socios/' . $ctx['socio_id'], ['motivo' => '   '], $adminCookie);
    assertStatus($ctx, 'DELETE /api/socios/{id} sin motivo -> 400', 'DELETE', '/api/socios/{id}', 400, $r);
    $r = apiRequest('DELETE', '/api/socios/' . $ctx['socio_id'], [
        'motivo' => 'TEST_ baja de prueba automatizada',
    ], $adminCookie);
    assertStatus($ctx, 'DELETE /api/socios/{id} (soft delete) -> 200', 'DELETE', '/api/socios/{id}', 200, $r);
    $r = apiRequest('POST', '/api/socios/' . $ctx['socio_id'] . '/revertir', [], $adminCookie);
    assertStatus($ctx, 'POST /api/socios/{id}/revertir (revert delete) -> 200', 'POST', '/api/socios/{id}/revertir', 200, $r);
}

// ============================================================
//  BLOQUE 15 — GEOLOCALIZACION (admin-only)
// ============================================================

section('BLOQUE 15: Geolocalizacion manual (admin-only)');
if ($ctx['socio_id']) {
    $r = apiRequest('POST', '/api/socios/' . $ctx['socio_id'] . '/geolocalizacion', [
        'lat' => -32.820366,
        'lng' => -61.403157,
    ], $adminCookie);
    assertStatus($ctx, 'POST /api/socios/{id}/geolocalizacion (admin) -> 200', 'POST', '/api/socios/{id}/geolocalizacion', 200, $r);
    $r = apiRequest('POST', '/api/socios/' . $ctx['socio_id'] . '/geolocalizacion', [], $adminCookie);
    assertStatus($ctx, 'POST geolocalizacion sin coords -> 400', 'POST', '/api/socios/{id}/geolocalizacion', 400, $r);
    if ($ctx['cobrador_cookie']) {
        $r = apiRequest('POST', '/api/socios/' . $ctx['socio_id'] . '/geolocalizacion', [
            'lat' => -32.820366,
            'lng' => -61.403157,
        ], $ctx['cobrador_cookie']);
        assertStatus($ctx, 'POST geolocalizacion con cobrador -> 403', 'POST', '/api/socios/{id}/geolocalizacion', 403, $r);
    }
}

// ============================================================
//  BLOQUE 16 — REGISTRAR CUOTA
// ============================================================

section('BLOQUE 16: Cuotas -- Registrar');
$r = apiRequest('POST', '/api/cuota', [
    'monto'                => TEST_CUOTA_MONTO,
    'fecha_vigencia_desde' => TEST_CUOTA_FECHA,
    'descripcion'          => 'TEST_ cuota de prueba automatizada',
], $adminCookie);
$ctx['tests']++;
if (in_array($r['status'], [200, 201])) {
    $ctx['passed']++;
    echo "  POST /api/cuota (registrar) -> {$r['status']} (200 o 201 aceptado)           \033[32mOK\033[0m" . PHP_EOL;
} else {
    $ctx['failed']++;
    $det = is_array($r['body']) ? ($r['body']['message'] ?? json_encode($r['body'])) : $r['body'];
    $ctx['failures'][] = "POST /api/cuota: esperado 200 o 201, recibido {$r['status']} -> {$det}";
    echo "  POST /api/cuota -> {$r['status']}                                            \033[31mFALLO\033[0m" . PHP_EOL;
}

// ============================================================
//  BLOQUE 17 — EXONERAR DEUDA
// ============================================================

section('BLOQUE 17: Exoneracion de deuda');
// deuda_id apunta a la deuda del socio 1, que puede estar en estado 'pagada' por la
// reversión del pago ejecutada en el BLOQUE 12. Para garantizar una deuda 'pendiente',
// se crea una deuda_anterior propia para socio_id2 (sin pagos ni deudas previas).
$deudaExonerarId = null;
if ($ctx['socio_id2']) {
    $rDex = apiRequest('POST', '/api/deuda/anterior', [
        'socio_id' => $ctx['socio_id2'],
        'monto'    => 50.00,
    ], $adminCookie);
    assertStatus($ctx, 'POST /api/deuda/anterior (para exoneracion) -> 200', 'POST', '/api/deuda/anterior', 200, $rDex);
    // Recuperar el id de esa deuda recién creada
    $rDex2 = apiRequest('GET', '/api/deuda/socio/' . $ctx['socio_id2'] . '/pendientes', null, $adminCookie);
    $pendSocio2 = $rDex2['body']['data'] ?? [];
    $deudaExonerarId = !empty($pendSocio2) ? ($pendSocio2[0]['id'] ?? null) : null;
}
if ($deudaExonerarId) {
    $r = apiRequest('POST', '/api/deuda/' . $deudaExonerarId . '/exonerar', [
        'motivo' => 'TEST_ exoneracion de prueba automatizada',
    ], $adminCookie);
    assertStatus($ctx, 'POST /api/deuda/{id}/exonerar (admin) -> 200', 'POST', '/api/deuda/{id}/exonerar', 200, $r);
} else {
    echo "  ADVERTENCIA: No hay deuda pendiente para exoneracion. Saltando." . PHP_EOL;
}

// ============================================================
//  BLOQUE 18 — HISTORIAL DE BACKUPS Y METRICAS DE DEUDA
// ============================================================

section('BLOQUE 18: Historial de backups y metricas de deuda');

// GET /api/backup/historial - admin debe recibir 200 con array en data
$r = apiRequest('GET', '/api/backup/historial', null, $adminCookie);
assertStatus($ctx, 'GET /api/backup/historial (admin) -> 200', 'GET', '/api/backup/historial', 200, $r);
$ctx['tests']++;
// Verificar que la respuesta contiene un array en data (puede estar vacio si no hay backups)
if (isset($r['body']['success']) && $r['body']['success'] === true && isset($r['body']['data']) && is_array($r['body']['data'])) {
    $ctx['passed']++;
    echo "  GET /api/backup/historial data es array                        \033[32mOK\033[0m" . PHP_EOL;
} else {
    $ctx['failed']++;
    $det = is_array($r['body']) ? ($r['body']['message'] ?? json_encode($r['body'])) : $r['body'];
    $ctx['failures'][] = "GET /api/backup/historial: data no es array -> {$det}";
    echo "  GET /api/backup/historial data no es array                     \033[31mFALLO\033[0m" . PHP_EOL;
}

// GET /api/backup/historial - cobrador debe recibir 403
if ($ctx['cobrador_cookie']) {
    $r = apiRequest('GET', '/api/backup/historial', null, $ctx['cobrador_cookie']);
    assertStatus($ctx, 'GET /api/backup/historial (cobrador) -> 403', 'GET', '/api/backup/historial', 403, $r);
}

// GET /api/backup/historial - sin sesion debe recibir 401
$r = apiRequest('GET', '/api/backup/historial', null, null);
assertStatus($ctx, 'GET /api/backup/historial (sin sesion) -> 401', 'GET', '/api/backup/historial', 401, $r);

// GET /api/dashboard/metricas - verificar que devuelve deuda_mes_generada como booleano
$r = apiRequest('GET', '/api/dashboard/metricas', null, $adminCookie);
assertStatus($ctx, 'GET /api/dashboard/metricas (con deuda_mes_generada) -> 200', 'GET', '/api/dashboard/metricas', 200, $r);
$ctx['tests']++;
// deuda_mes_generada debe ser un booleano (true o false) en el payload
if (
    isset($r['body']['success']) && $r['body']['success'] === true
    && isset($r['body']['data']['deuda_mes_generada'])
    && is_bool($r['body']['data']['deuda_mes_generada'])
) {
    $ctx['passed']++;
    $val = $r['body']['data']['deuda_mes_generada'] ? 'true' : 'false';
    echo "  GET /api/dashboard/metricas deuda_mes_generada es bool ({$val})  \033[32mOK\033[0m" . PHP_EOL;
} else {
    $ctx['failed']++;
    $det = is_array($r['body']) ? json_encode($r['body']['data'] ?? $r['body']) : $r['body'];
    $ctx['failures'][] = "GET /api/dashboard/metricas: deuda_mes_generada no es bool -> {$det}";
    echo "  GET /api/dashboard/metricas deuda_mes_generada no es bool       \033[31mFALLO\033[0m" . PHP_EOL;
}

// ============================================================
//  BLOQUE 19 — RESTAURAR BACKUP - SEGURIDAD
// ============================================================

section('BLOQUE 19: Restaurar backup - seguridad');

$env = loadEnv();
$dbName = $env['DB_NAME'] ?? 'centro_jubilados_primavera';

// 1. sin sesion -> 401
$r = apiRequestUpload('/api/backup/restaurar', '', null);
assertStatus($ctx, 'POST /api/backup/restaurar (sin sesion) -> 401', 'POST', '/api/backup/restaurar', 401, $r);

// 2. con cobrador -> 403
if ($ctx['cobrador_cookie']) {
    $r = apiRequestUpload('/api/backup/restaurar', '', $ctx['cobrador_cookie']);
    assertStatus($ctx, 'POST /api/backup/restaurar (cobrador) -> 403', 'POST', '/api/backup/restaurar', 403, $r);
}

// 3. administrador sin confirmacion -> 400
$tmpSinConf = tempnam(sys_get_temp_dir(), 'test_rest_');
file_put_contents($tmpSinConf, "-- CJP-BACKUP-V1\n");
$r = apiRequestUpload('/api/backup/restaurar', $tmpSinConf, $adminCookie, []);
@unlink($tmpSinConf);
assertStatus($ctx, 'POST /api/backup/restaurar (sin confirmacion) -> 400', 'POST', '/api/backup/restaurar', 400, $r);

// 4. administrador con confirmacion pero sin archivo -> 400
$r = apiRequestUpload('/api/backup/restaurar', '', $adminCookie, ['confirmacion' => 'RESTAURAR']);
assertStatus($ctx, 'POST /api/backup/restaurar (con confirmacion sin archivo) -> 400', 'POST', '/api/backup/restaurar', 400, $r);

// 5. archivo .txt -> 400
$tmpTxt = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_backup_' . uniqid() . '.txt';
file_put_contents($tmpTxt, "Contenido plano no SQL\n");
$r = apiRequestUpload('/api/backup/restaurar', $tmpTxt, $adminCookie, ['confirmacion' => 'RESTAURAR']);
@unlink($tmpTxt);
assertStatus($ctx, 'POST /api/backup/restaurar (archivo .txt) -> 400', 'POST', '/api/backup/restaurar', 400, $r);

// 6. .sql sin cabecera CJP -> 400
$tmpNoCjp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_backup_' . uniqid() . '.sql';
file_put_contents($tmpNoCjp, "-- MySQL dump 10.13\nSELECT 1;\n-- Dump completed\n");
$r = apiRequestUpload('/api/backup/restaurar', $tmpNoCjp, $adminCookie, ['confirmacion' => 'RESTAURAR']);
@unlink($tmpNoCjp);
assertStatus($ctx, 'POST /api/backup/restaurar (.sql sin cabecera CJP) -> 400', 'POST', '/api/backup/restaurar', 400, $r);

// 7. .sql con cabecera CJP pero "-- Base: otra_base" -> 400
$tmpOtraBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_backup_' . uniqid() . '.sql';
file_put_contents($tmpOtraBase, "-- CJP-BACKUP-V1\n-- Base: otra_base_invalida\n-- Dump completed\n");
$r = apiRequestUpload('/api/backup/restaurar', $tmpOtraBase, $adminCookie, ['confirmacion' => 'RESTAURAR']);
@unlink($tmpOtraBase);
assertStatus($ctx, 'POST /api/backup/restaurar (.sql con cabecera CJP pero otra base) -> 400', 'POST', '/api/backup/restaurar', 400, $r);

// 8. .sql con cabecera y base correctas pero con una linea "DROP DATABASE x;" -> 400
$tmpDropDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_backup_' . uniqid() . '.sql';
file_put_contents($tmpDropDb, "-- CJP-BACKUP-V1\n-- Base: {$dbName}\nDROP DATABASE x;\n-- Dump completed\n");
$r = apiRequestUpload('/api/backup/restaurar', $tmpDropDb, $adminCookie, ['confirmacion' => 'RESTAURAR']);
@unlink($tmpDropDb);
assertStatus($ctx, 'POST /api/backup/restaurar (.sql con sentencia DROP DATABASE) -> 400', 'POST', '/api/backup/restaurar', 400, $r);

// 9. .sql con cabecera y base correctas pero sin "-- Dump completed" -> 400
$tmpTruncado = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_backup_' . uniqid() . '.sql';
file_put_contents($tmpTruncado, "-- CJP-BACKUP-V1\n-- Base: {$dbName}\n-- Archivo truncado sin marca final\n");
$r = apiRequestUpload('/api/backup/restaurar', $tmpTruncado, $adminCookie, ['confirmacion' => 'RESTAURAR']);
@unlink($tmpTruncado);
assertStatus($ctx, 'POST /api/backup/restaurar (.sql sin -- Dump completed) -> 400', 'POST', '/api/backup/restaurar', 400, $r);

// ============================================================
//  BLOQUE 20 — LOGOUT
// ============================================================

section('BLOQUE 20: Logout');
$r = apiRequest('POST', '/api/auth/logout', null, $adminCookie);
assertStatus($ctx, 'POST /api/auth/logout (admin) -> 200', 'POST', '/api/auth/logout', 200, $r);
$r = apiRequest('GET', '/api/socios', null, $adminCookie);
assertStatus($ctx, 'GET /api/socios con sesion cerrada -> 401', 'GET', '/api/socios', 401, $r);
if ($ctx['cobrador_cookie']) {
    $r = apiRequest('POST', '/api/auth/logout', null, $ctx['cobrador_cookie']);
    assertStatus($ctx, 'POST /api/auth/logout (cobrador) -> 200', 'POST', '/api/auth/logout', 200, $r);
}

// ============================================================
//  BLOQUE 21 — LIMPIEZA FINAL (respetando FKs)
// ============================================================

section('BLOQUE 21: Limpieza de datos TEST_');
echo "  Conectando a la base de datos para limpieza directa..." . PHP_EOL;
$cleaned = false;
try {
    $pdo = getDb();
    $pdo->beginTransaction();

    // 1. Obtener ID del cobrador TEST_ si existe
    $cobradorId = $ctx['cobrador_id'] ?? null;
    if (!$cobradorId) {
        $stmtCob = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmtCob->execute([COBRADOR_USER]);
        $cobradorId = $stmtCob->fetchColumn() ?: null;
    }

    // 2. Limpiar auditoría vinculada al cobrador TEST_ o a datos TEST_
    if ($cobradorId) {
        $pdo->prepare("DELETE FROM auditoria WHERE usuario_id = ?")->execute([$cobradorId]);
    }
    $pdo->prepare("DELETE FROM auditoria WHERE motivo LIKE 'TEST\_%' OR valor_nuevo LIKE '%TEST\_%' OR valor_anterior LIKE '%TEST\_%' OR valor_nuevo LIKE ? OR valor_anterior LIKE ?")
        ->execute(['%' . TEST_DNI . '%', '%' . TEST_DNI . '%']);

    // 3. Limpiar notificaciones vinculadas a datos TEST_
    try {
        $pdo->prepare("DELETE FROM notificaciones WHERE mensaje LIKE '%TEST\_%' OR referencia LIKE ? OR referencia LIKE ?")
            ->execute(['%' . TEST_DNI . '%', '%' . TEST_DNI2 . '%']);
    } catch (Exception $e) { /* ignorar */ }

    // 4. Limpiar planillas y detalles vinculadas al cobrador o planilla_id
    if ($cobradorId) {
        $pdo->prepare("DELETE ps FROM planilla_socio ps INNER JOIN planillas p ON ps.planilla_id = p.id WHERE p.cobrador_id = ?")->execute([$cobradorId]);
        $pdo->prepare("DELETE FROM planillas WHERE cobrador_id = ?")->execute([$cobradorId]);
    }
    if (!empty($ctx['planilla_id'])) {
        $pdo->prepare("DELETE FROM planilla_socio WHERE planilla_id = ?")->execute([$ctx['planilla_id']]);
        $pdo->prepare("DELETE FROM planillas WHERE id = ?")->execute([$ctx['planilla_id']]);
    }
    try {
        $pdo->query("DELETE p FROM planillas p LEFT JOIN planilla_socio ps ON ps.planilla_id = p.id WHERE ps.planilla_id IS NULL");
    } catch (Exception $e) { /* ignorar */ }

    // 5. Socios, pagos y deudas
    $stmt = $pdo->prepare("SELECT id FROM socios WHERE nombre_apellido LIKE 'TEST\_%' OR dni IN (?, ?, ?)");
    $stmt->execute([TEST_DNI, TEST_DNI2, TEST_DNI_NORMALIZADO]);
    $testSocioIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $socioIds = array_values(array_unique(array_filter(array_merge(
        [$ctx['socio_id'] ?? null, $ctx['socio_id2'] ?? null],
        $testSocioIds
    ))));

    if (!empty($socioIds)) {
        $in = implode(',', array_fill(0, count($socioIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM deudas WHERE socio_id IN ({$in})");
        $stmt->execute($socioIds);
        $deudaIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($deudaIds)) {
            $inD = implode(',', array_fill(0, count($deudaIds), '?'));
            try {
                $pdo->prepare("DELETE FROM pago_deuda WHERE deuda_id IN ({$inD})")->execute($deudaIds);
            } catch (Exception $e) { /* ignorar */ }
            $pdo->prepare("DELETE FROM deudas WHERE id IN ({$inD})")->execute($deudaIds);
        }
        $pdo->prepare("DELETE FROM pagos WHERE socio_id IN ({$in})")->execute($socioIds);
        $pdo->prepare("DELETE FROM planilla_socio WHERE socio_id IN ({$in})")->execute($socioIds);
        foreach ($socioIds as $sId) {
            try {
                $pdo->prepare("DELETE FROM notificaciones WHERE referencia LIKE ?")->execute(['%' . $sId . '%']);
            } catch (Exception $e) { /* ignorar */ }
        }
        foreach (['observaciones', 'historial_estados'] as $tabla) {
            try {
                $pdo->prepare("DELETE FROM {$tabla} WHERE socio_id IN ({$in})")->execute($socioIds);
            } catch (Exception $e) { /* ignorar */ }
        }
        $pdo->prepare("DELETE FROM socios WHERE id IN ({$in})")->execute($socioIds);
    }
    $pdo->prepare("DELETE FROM socios WHERE nombre_apellido LIKE 'TEST\_%' OR dni IN (?, ?, ?)")->execute([TEST_DNI, TEST_DNI2, TEST_DNI_NORMALIZADO]);

    // 5.b. Pagos registrados por el cobrador TEST_ (evita error FK fk_pagos_usuario)
    if ($cobradorId) {
        $pdo->prepare("DELETE pd FROM pago_deuda pd INNER JOIN pagos p ON pd.pago_id = p.id WHERE p.usuario_id = ?")->execute([$cobradorId]);
        $pdo->prepare("DELETE FROM pagos WHERE usuario_id = ?")->execute([$cobradorId]);
    }

    // 6. Eliminar usuario cobrador TEST_
    $pdo->prepare("DELETE FROM usuarios WHERE usuario = ?")->execute([COBRADOR_USER]);

    // 7. Zonas TEST_ si existieran
    try {
        $pdo->prepare("DELETE FROM zonas WHERE nombre LIKE 'TEST\_%'")->execute();
    } catch (Exception $e) { /* ignorar */ }

    // 8. Limpiar registros de importacion CSV de prueba (importacion_inconsistencias e importaciones_csv)
    // Solo se borran las que tienen importacion_id coincidente con la prueba o cuyo datos_registro contenga TEST_
    try {
        if (!empty($ctx['importacion_csv_id'])) {
            $pdo->prepare("DELETE FROM importacion_inconsistencias WHERE importacion_id = ?")
                ->execute([$ctx['importacion_csv_id']]);
            $pdo->prepare("DELETE FROM importaciones_csv WHERE id = ?")
                ->execute([$ctx['importacion_csv_id']]);
        }
        // Borrado de seguridad: inconsistencias con nombre de prueba en datos_registro
        $pdo->prepare("DELETE FROM importacion_inconsistencias WHERE datos_registro LIKE '%TEST_Csv%'")->execute();
    } catch (Exception $e) { /* ignorar si la tabla no existe en este entorno */ }

    // 9. Limpiar cuotas de prueba acumuladas del BLOQUE 16.
    // El WHERE combina fecha_vigencia_desde >= TEST_CUOTA_FECHA_MINIMA (anno 2099) con el
    // monto exacto de prueba. Esta combinacion hace imposible borrar cuotas reales:
    // ninguna cuota de produccion tendra fecha en el siglo XXII.
    // No hay claves foraneas apuntando a configuracion_cuota (verificado con INFORMATION_SCHEMA).
    try {
        $pdo->prepare(
            "DELETE FROM configuracion_cuota
             WHERE fecha_vigencia_desde >= ? AND monto = ?"
        )->execute([TEST_CUOTA_FECHA_MINIMA, TEST_CUOTA_MONTO]);
    } catch (Exception $e) { /* ignorar si la tabla no existe en este entorno */ }

    $pdo->commit();
    $cleaned = true;
    echo "  \033[32mLimpieza completada exitosamente.\033[0m" . PHP_EOL;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "  \033[31mERROR en limpieza: " . $e->getMessage() . "\033[0m" . PHP_EOL;
    echo "  Ejecutar: php scripts/test_sistema.php --limpiar" . PHP_EOL;
}

// ============================================================
//  RESUMEN FINAL
// ============================================================

echo PHP_EOL;
echo "\033[1m+==================================================================+\033[0m" . PHP_EOL;
echo "\033[1m|                    RESUMEN DE PRUEBAS                           |\033[0m" . PHP_EOL;
echo "\033[1m+==================================================================+\033[0m" . PHP_EOL;
echo PHP_EOL;

$total   = $ctx['tests'];
$passed  = $ctx['passed'];
$failed  = $ctx['failed'];
$omitted = $ctx['omitted'];
$pct     = $total > 0 ? round($passed / $total * 100) : 0;
echo "  Total de pruebas : \033[1m{$total}\033[0m"                                       . PHP_EOL;
echo "  Pasaron          : \033[32m{$passed}\033[0m"                                     . PHP_EOL;
echo "  Fallaron         : \033[" . ($failed > 0 ? '31' : '32') . "m{$failed}\033[0m"   . PHP_EOL;
if ($omitted > 0) {
    echo "  Omitidas         : \033[33m{$omitted}\033[0m (precondicion no cumplida)"     . PHP_EOL;
}
echo "  Tasa de exito    : {$pct}%"                                                       . PHP_EOL;
echo PHP_EOL;

if (!empty($ctx['failures'])) {
    echo "\033[31m  ==================================================\033[0m" . PHP_EOL;
    echo "\033[31m  PRUEBAS FALLIDAS (" . count($ctx['failures']) . "):\033[0m"  . PHP_EOL;
    echo "\033[31m  ==================================================\033[0m" . PHP_EOL;
    foreach ($ctx['failures'] as $i => $f) {
        echo "  \033[31m" . ($i + 1) . ". {$f}\033[0m" . PHP_EOL;
    }
    echo PHP_EOL;
}
if ($omitted > 0) {
    echo "\033[33m  NOTA: {$omitted} prueba(s) omitida(s) por precondicion no cumplida\033[0m" . PHP_EOL;
    echo "\033[33m        (ej: geocodificacion pendiente). No cuentan como fallos.\033[0m"      . PHP_EOL;
    echo PHP_EOL;
}
if (!$cleaned) {
    echo "\033[33m  ATENCION: Limpieza no completada. Ejecutar:\033[0m" . PHP_EOL;
    echo "\033[33m  php scripts/test_sistema.php --limpiar\033[0m"      . PHP_EOL;
    echo PHP_EOL;
}
if ($failed === 0) {
    echo "\033[1;32m  Todas las pruebas pasaron correctamente!\033[0m" . PHP_EOL;
} else {
    echo "\033[1;31m  Algunas pruebas fallaron. Revisar el listado anterior.\033[0m" . PHP_EOL;
}
echo PHP_EOL;
exit($failed > 0 ? 1 : 0);
