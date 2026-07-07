<?php

namespace CJP\Modules\Socios;

use Exception;
use DateTime;
use CJP\Config\Config;
use CJP\Shared\Helpers\DateHelper;
use CJP\Modules\Zonas\ZonaService;
use Ramsey\Uuid\Uuid;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class SocioService
{
    private SocioRepository $repository;
    private ZonaService $zonaService;

    /**
     * SocioService constructor.
     *
     * @param SocioRepository|null $repository
     * @param ZonaService|null $zonaService
     */
    public function __construct(?SocioRepository $repository = null, ?ZonaService $zonaService = null)
    {
        $this->repository = $repository ?? new SocioRepository();
        $this->zonaService = $zonaService ?? new ZonaService();
    }

    /**
     * Create a new partner.
     *
     * @param array $datos
     * @param string $usuarioId
     * @return array
     * @throws Exception
     */
    public function crear(array $datos, string $usuarioId): array
    {
        // 1. Validation
        $this->validarDatosSocio($datos);

        $dni = trim($datos['dni']);
        if ($this->repository->findByDni($dni) !== null) {
            throw new Exception("El DNI ingresado ya pertenece a un socio.");
        }

        // 2. Generate sequential number
        $numeroSocio = $this->repository->getNextNumeroSocio();

        // 3. Geolocation via Nominatim
        $geo = $this->geocodeAddress($datos['direccion']);
        $lat = null;
        $lng = null;
        $zonaId = null;
        $geoPendiente = true;

        if ($geo !== null) {
            $lat = $geo['lat'];
            $lng = $geo['lng'];
            $zonaId = $this->zonaService->asignarZona($lat, $lng);
            $geoPendiente = false;
        }

        // 4. UUID generation
        $socioId = Uuid::uuid4()->toString();

        // 5. QR code generation
        $qrUrl = $this->generarQR($socioId);

        // 6. DB Insertion payload
        $payload = [
            'id'                        => $socioId,
            'numero_socio'              => $numeroSocio,
            'nombre_apellido'           => trim($datos['nombre_apellido']),
            'dni'                       => $dni,
            'fecha_nacimiento'          => $datos['fecha_nacimiento'],
            'telefono'                  => trim($datos['telefono']),
            'mutual'                    => !empty($datos['mutual']) ? trim($datos['mutual']) : null,
            'direccion'                 => trim($datos['direccion']),
            'latitud'                   => $lat,
            'longitud'                  => $lng,
            'zona_id'                   => $zonaId,
            'estado'                    => 'activo',
            'modalidad_cobranza'        => $datos['modalidad_cobranza'],
            'geolocalizacion_pendiente' => $geoPendiente ? 1 : 0,
            'qr_url'                    => $qrUrl,
        ];

        $this->repository->create($payload);

        // 7. Register audit event
        $this->repository->registerAuditEvent(
            'CREAR_SOCIO',
            'socios',
            null,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $usuarioId,
            'Alta de socio'
        );

        return $this->repository->findById($socioId);
    }

    /**
     * Edit partner data.
     *
     * @param string $id
     * @param array $datos
     * @param string $usuarioId
     * @return array
     * @throws Exception
     */
    public function editar(string $id, array $datos, string $usuarioId): array
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new Exception("Socio no encontrado.");
        }

        $payload = [];
        $valAnterior = [];

        // 1. Validation of common fields if they are sent
        if (isset($datos['nombre_apellido'])) {
            $val = trim($datos['nombre_apellido']);
            if (empty($val)) {
                throw new Exception("El nombre y apellido son obligatorios.");
            }
            $payload['nombre_apellido'] = $val;
            $valAnterior['nombre_apellido'] = $socio['nombre_apellido'];
        }

        if (isset($datos['fecha_nacimiento'])) {
            if (!$this->validarFecha($datos['fecha_nacimiento'])) {
                throw new Exception("Formato de fecha de nacimiento incorrecto (debe ser YYYY-MM-DD).");
            }
            $payload['fecha_nacimiento'] = $datos['fecha_nacimiento'];
            $valAnterior['fecha_nacimiento'] = $socio['fecha_nacimiento'];
        }

        if (isset($datos['telefono'])) {
            $val = trim($datos['telefono']);
            if (empty($val)) {
                throw new Exception("El teléfono es obligatorio.");
            }
            $payload['telefono'] = $val;
            $valAnterior['telefono'] = $socio['telefono'];
        }

        if (isset($datos['mutual'])) {
            $payload['mutual'] = !empty($datos['mutual']) ? trim($datos['mutual']) : null;
            $valAnterior['mutual'] = $socio['mutual'];
        }

        if (isset($datos['modalidad_cobranza'])) {
            $val = $datos['modalidad_cobranza'];
            if ($val !== 'cobranza_domiciliaria' && $val !== 'cobranza_en_sede') {
                throw new Exception("La modalidad de cobranza debe ser 'cobranza_domiciliaria' o 'cobranza_en_sede'.");
            }
            $payload['modalidad_cobranza'] = $val;
            $valAnterior['modalidad_cobranza'] = $socio['modalidad_cobranza'];
        }

        // 2. DNI check (Admin only, requires double confirmation)
        if (isset($datos['dni']) && trim($datos['dni']) !== $socio['dni']) {
            $newDni = trim($datos['dni']);
            if (empty($newDni)) {
                throw new Exception("El DNI es obligatorio.");
            }

            // Role verification
            $rol = $this->repository->getUserRole($usuarioId);
            if ($rol !== 'administrador') {
                throw new Exception("Solo los administradores pueden modificar el DNI de un socio.");
            }

            // Double confirmation check
            if (!isset($datos['confirmar_cambio_dni']) || $datos['confirmar_cambio_dni'] !== true) {
                throw new Exception("Se requiere doble confirmación para modificar el DNI.");
            }

            // Uniqueness check
            if ($this->repository->findByDni($newDni) !== null) {
                throw new Exception("El DNI ingresado ya pertenece a otro socio.");
            }

            $payload['dni'] = $newDni;
            $valAnterior['dni'] = $socio['dni'];
        }

        // 3. Address geocoding check
        if (isset($datos['direccion']) && trim($datos['direccion']) !== $socio['direccion']) {
            $newAddress = trim($datos['direccion']);
            if (empty($newAddress)) {
                throw new Exception("La dirección es obligatoria.");
            }

            $payload['direccion'] = $newAddress;
            $valAnterior['direccion'] = $socio['direccion'];

            // Geocode new address
            $geo = $this->geocodeAddress($newAddress);
            if ($geo !== null) {
                $payload['latitud'] = $geo['lat'];
                $payload['longitud'] = $geo['lng'];
                $payload['zona_id'] = $this->zonaService->asignarZona($geo['lat'], $geo['lng']);
                $payload['geolocalizacion_pendiente'] = 0;
            } else {
                $payload['latitud'] = null;
                $payload['longitud'] = null;
                $payload['zona_id'] = null;
                $payload['geolocalizacion_pendiente'] = 1;
            }

            $valAnterior['latitud'] = $socio['latitud'];
            $valAnterior['longitud'] = $socio['longitud'];
            $valAnterior['zona_id'] = $socio['zona_id'];
            $valAnterior['geolocalizacion_pendiente'] = $socio['geolocalizacion_pendiente'];
        }

        if (empty($payload)) {
            return $socio;
        }

        // 4. Database update
        $this->repository->update($id, $payload);

        // 5. Register audit event
        $this->repository->registerAuditEvent(
            'EDITAR_SOCIO',
            'socios',
            json_encode($valAnterior, JSON_UNESCAPED_UNICODE),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $usuarioId,
            'Edición de datos de socio'
        );

        return $this->repository->findById($id);
    }

    /**
     * Suspend a partner.
     *
     * @param string $id
     * @param string $usuarioId
     * @return void
     * @throws Exception
     */
    public function suspender(string $id, string $usuarioId): void
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new Exception("Socio no encontrado.");
        }

        $this->repository->suspend($id);

        $this->repository->registerAuditEvent(
            'SUSPENDER_SOCIO',
            'socios',
            json_encode(['estado' => $socio['estado']], JSON_UNESCAPED_UNICODE),
            json_encode(['estado' => 'suspendido'], JSON_UNESCAPED_UNICODE),
            $usuarioId,
            'Suspensión de socio'
        );
    }

    /**
     * Reactivate a partner.
     *
     * @param string $id
     * @param string $usuarioId
     * @return void
     * @throws Exception
     */
    public function reactivar(string $id, string $usuarioId): void
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new Exception("Socio no encontrado.");
        }

        $this->repository->reactivate($id);

        $this->repository->registerAuditEvent(
            'REACTIVAR_SOCIO',
            'socios',
            json_encode(['estado' => $socio['estado']], JSON_UNESCAPED_UNICODE),
            json_encode(['estado' => 'activo'], JSON_UNESCAPED_UNICODE),
            $usuarioId,
            'Reactivación de socio'
        );
    }

    /**
     * Logical delete (soft delete) of a partner.
     *
     * @param string $id
     * @param string $motivo
     * @param string $usuarioId
     * @return void
     * @throws Exception
     */
    public function eliminar(string $id, string $motivo, string $usuarioId): void
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new Exception("Socio no encontrado.");
        }

        if (empty(trim($motivo))) {
            throw new Exception("El motivo de la baja es obligatorio.");
        }

        // 1. DB logical delete
        $this->repository->softDelete($id, trim($motivo));

        // 2. Notification creation (7-day reversion window)
        $expiration = DateHelper::addDays(DateHelper::now(), 7);
        $this->repository->createNotification([
            'tipo'                       => 'reversion_baja',
            'mensaje'                    => "Se ha registrado la baja del socio {$socio['nombre_apellido']} (N° {$socio['numero_socio']}) por: " . trim($motivo) . ".",
            'referencia'                 => ['entidad' => 'socios', 'id' => $id],
            'fecha_expiracion_reversion' => $expiration
        ]);

        // 3. Register audit event
        $this->repository->registerAuditEvent(
            'ELIMINAR_SOCIO',
            'socios',
            json_encode(['estado' => $socio['estado']], JSON_UNESCAPED_UNICODE),
            json_encode(['estado' => 'eliminado', 'motivo_baja' => trim($motivo)], JSON_UNESCAPED_UNICODE),
            $usuarioId,
            trim($motivo)
        );
    }

    /**
     * Revert logical delete within the 7-day period.
     *
     * @param string $id
     * @param string $usuarioId
     * @return void
     * @throws Exception
     */
    public function revertirEliminacion(string $id, string $usuarioId): void
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new Exception("Socio no encontrado.");
        }

        if ($socio['estado'] !== 'eliminado') {
            throw new Exception("El socio no se encuentra en estado eliminado.");
        }

        // Check if 7 days have passed
        if (empty($socio['fecha_baja'])) {
            throw new Exception("Fecha de baja no registrada.");
        }

        $fechaExpiracion = DateHelper::addDays($socio['fecha_baja'], 7);
        if (DateHelper::isExpired($fechaExpiracion)) {
            throw new Exception("El plazo de 7 días para revertir la eliminación ha expirado.");
        }

        // Reactivate
        $this->repository->reactivate($id);

        // Audit
        $this->repository->registerAuditEvent(
            'REVERTIR_ELIMINACION',
            'socios',
            json_encode(['estado' => 'eliminado'], JSON_UNESCAPED_UNICODE),
            json_encode(['estado' => 'activo'], JSON_UNESCAPED_UNICODE),
            $usuarioId,
            'Reversión de baja dentro del período de gracia'
        );
    }

    /**
     * Correct partner coordinates manually.
     *
     * @param string $id
     * @param float $lat
     * @param float $lng
     * @param string $usuarioId
     * @return void
     * @throws Exception
     */
    public function corregirGeolocalizacion(string $id, float $lat, float $lng, string $usuarioId): void
    {
        $rol = $this->repository->getUserRole($usuarioId);
        if ($rol !== 'administrador') {
            throw new Exception("Solo los administradores pueden corregir manualmente la geolocalización.");
        }

        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new Exception("Socio no encontrado.");
        }

        $zonaId = $this->zonaService->asignarZona($lat, $lng);

        $valAnterior = [
            'latitud'                   => $socio['latitud'],
            'longitud'                  => $socio['longitud'],
            'zona_id'                   => $socio['zona_id'],
            'geolocalizacion_pendiente' => $socio['geolocalizacion_pendiente']
        ];

        $payload = [
            'latitud'                   => $lat,
            'longitud'                  => $lng,
            'zona_id'                   => $zonaId,
            'geolocalizacion_pendiente' => 0
        ];

        $this->repository->updateGeolocalizacion($id, $lat, $lng, $zonaId);

        // Audit
        $this->repository->registerAuditEvent(
            'CORREGIR_GEOLOCALIZACION',
            'socios',
            json_encode($valAnterior, JSON_UNESCAPED_UNICODE),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $usuarioId,
            'Corrección manual de geolocalización'
        );
    }

    /**
     * Import partners from CSV file.
     *
     * @param string $rutaArchivo
     * @param string $usuarioId
     * @return array [exitosos => int, fallidos => int]
     * @throws Exception
     */
    public function importarCSV(string $rutaArchivo, string $usuarioId): array
    {
        if (!file_exists($rutaArchivo)) {
            throw new Exception("Archivo CSV no encontrado.");
        }

        $file = fopen($rutaArchivo, 'r');
        if (!$file) {
            throw new Exception("No se pudo abrir el archivo CSV.");
        }

        // Clean UTF-8 BOM if present
        $bom = pack('H*', 'EFBBBF');
        $line = fgets($file);
        if (str_starts_with($line, $bom)) {
            $line = substr($line, 3);
        }

        // Detect separator
        $header = str_getcsv($line, ';');
        if (count($header) === 1 && str_contains($header[0], ',')) {
            $header = str_getcsv($line, ',');
        }

        $header = array_map(function ($h) {
            return trim(str_replace('"', '', $h));
        }, $header);

        $requiredHeaders = ['nombre_apellido', 'dni', 'fecha_nacimiento', 'telefono', 'direccion', 'modalidad_cobranza'];
        foreach ($requiredHeaders as $req) {
            if (!in_array($req, $header, true)) {
                fclose($file);
                throw new Exception("El archivo CSV no contiene las columnas requeridas: " . implode(', ', $requiredHeaders));
            }
        }

        $exitosos = 0;
        $fallidos = 0;

        // Find index of headers
        $indices = [];
        foreach ($header as $idx => $name) {
            $indices[$name] = $idx;
        }

        while (($row = fgetcsv($file, 0, str_contains($line, ';') ? ';' : ',')) !== false) {
            if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                continue;
            }

            // Map columns
            $rowData = [];
            foreach ($indices as $name => $idx) {
                $rowData[$name] = isset($row[$idx]) ? trim($row[$idx]) : '';
            }

            $errors = [];

            // 1. Mandatory values check
            if (empty($rowData['nombre_apellido'])) {
                $errors[] = "nombre_apellido es obligatorio";
            }
            if (empty($rowData['dni'])) {
                $errors[] = "dni es obligatorio";
            }
            if (empty($rowData['fecha_nacimiento']) || !$this->validarFecha($rowData['fecha_nacimiento'])) {
                $errors[] = "fecha_nacimiento inválida (esperado YYYY-MM-DD)";
            }
            if (empty($rowData['telefono'])) {
                $errors[] = "telefono es obligatorio";
            }
            if (empty($rowData['direccion'])) {
                $errors[] = "direccion es obligatorio";
            }
            if (
                empty($rowData['modalidad_cobranza']) ||
                ($rowData['modalidad_cobranza'] !== 'cobranza_domiciliaria' && $rowData['modalidad_cobranza'] !== 'cobranza_en_sede')
            ) {
                $errors[] = "modalidad_cobranza incorrecta (esperado 'cobranza_domiciliaria' o 'cobranza_en_sede')";
            }

            // 2. Duplicate DNI check
            if (!empty($rowData['dni'])) {
                if ($this->repository->findByDni($rowData['dni']) !== null) {
                    $errors[] = "DNI ya existente en el sistema";
                }
            }

            if (!empty($errors)) {
                $this->repository->registrarInconsistencia([
                    'datos_registro' => $rowData,
                    'motivo_rechazo' => implode('; ', $errors)
                ]);
                $fallidos++;
                continue;
            }

            // 3. Geocode and insert
            try {
                $numeroSocio = $this->repository->getNextNumeroSocio();
                $geo = $this->geocodeAddress($rowData['direccion']);
                $lat = null;
                $lng = null;
                $zonaId = null;
                $geoPendiente = true;

                if ($geo !== null) {
                    $lat = $geo['lat'];
                    $lng = $geo['lng'];
                    $zonaId = $this->zonaService->asignarZona($lat, $lng);
                    $geoPendiente = false;
                }

                $socioId = Uuid::uuid4()->toString();
                $qrUrl = $this->generarQR($socioId);

                $payload = [
                    'id'                        => $socioId,
                    'numero_socio'              => $numeroSocio,
                    'nombre_apellido'           => $rowData['nombre_apellido'],
                    'dni'                       => $rowData['dni'],
                    'fecha_nacimiento'          => $rowData['fecha_nacimiento'],
                    'telefono'                  => $rowData['telefono'],
                    'mutual'                    => !empty($rowData['mutual']) ? $rowData['mutual'] : null,
                    'direccion'                 => $rowData['direccion'],
                    'latitud'                   => $lat,
                    'longitud'                  => $lng,
                    'zona_id'                   => $zonaId,
                    'estado'                    => 'activo',
                    'modalidad_cobranza'        => $rowData['modalidad_cobranza'],
                    'geolocalizacion_pendiente' => $geoPendiente ? 1 : 0,
                    'qr_url'                    => $qrUrl,
                ];

                $this->repository->create($payload);

                // Register audit
                $this->repository->registerAuditEvent(
                    'CREAR_SOCIO',
                    'socios',
                    null,
                    json_encode($payload, JSON_UNESCAPED_UNICODE),
                    $usuarioId,
                    'Importación CSV'
                );

                $exitosos++;
            } catch (Exception $e) {
                $this->repository->registrarInconsistencia([
                    'datos_registro' => $rowData,
                    'motivo_rechazo' => "Error durante inserción: " . $e->getMessage()
                ]);
                $fallidos++;
            }
        }

        fclose($file);

        return [
            'exitosos' => $exitosos,
            'fallidos' => $fallidos
        ];
    }

    /**
     * List partners with filters and pagination.
     *
     * @param array $filtros
     * @param int $pagina
     * @return array
     */
    public function listar(array $filtros, int $pagina): array
    {
        return $this->repository->findAll($filtros, $pagina);
    }

    /**
     * Retrieve partner details.
     *
     * @param string $id
     * @return array
     * @throws Exception
     */
    public function obtener(string $id): array
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new Exception("Socio no encontrado.");
        }
        return $socio;
    }

    /**
     * Generate QR code PNG file for a partner and return its URL.
     *
     * @param string $socioId
     * @return string
     */
    public function generarQR(string $socioId): string
    {
        $appUrl = Config::get('APP_URL');
        $url = $appUrl . '/cobrador/socio/' . $socioId;

        $dir = dirname(__DIR__, 2) . '/storage/qr';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . $socioId . '.png';

        $qrCode = new QrCode($url);
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        $result->saveToFile($path);

        return $url;
    }

    /**
     * Validate partner fields.
     *
     * @param array $datos
     * @return void
     * @throws Exception
     */
    private function validarDatosSocio(array $datos): void
    {
        if (empty($datos['nombre_apellido'])) {
            throw new Exception("El nombre y apellido son obligatorios.");
        }

        if (empty($datos['dni'])) {
            throw new Exception("El DNI es obligatorio.");
        }

        if (empty($datos['fecha_nacimiento']) || !$this->validarFecha($datos['fecha_nacimiento'])) {
            throw new Exception("Formato de fecha de nacimiento incorrecto (debe ser YYYY-MM-DD).");
        }

        if (empty($datos['telefono'])) {
            throw new Exception("El teléfono es obligatorio.");
        }

        if (empty($datos['direccion'])) {
            throw new Exception("La dirección es obligatoria.");
        }

        if (empty($datos['modalidad_cobranza'])) {
            throw new Exception("La modalidad de cobranza es obligatoria.");
        }

        $mod = $datos['modalidad_cobranza'];
        if ($mod !== 'cobranza_domiciliaria' && $mod !== 'cobranza_en_sede') {
            throw new Exception("La modalidad de cobranza debe ser 'cobranza_domiciliaria' o 'cobranza_en_sede'.");
        }
    }

    /**
     * Validate date format YYYY-MM-DD.
     *
     * @param string $date
     * @return bool
     */
    private function validarFecha(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Retrieve coordinates from Nominatim.
     *
     * @param string $direccion
     * @return array|null
     */
    private function geocodeAddress(string $direccion): ?array
    {
        $addressQuery = $direccion;
        if (!str_contains(strtolower($direccion), 'armstrong')) {
            $addressQuery .= ', Armstrong, Santa Fe, Argentina';
        }

        $url = 'https://nominatim.openstreetmap.org/search?q=' . urlencode($addressQuery) . '&format=json&limit=1';

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: CJP/1.0'
                ],
                'timeout' => 3.0,
            ]
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data) || !isset($data[0]['lat']) || !isset($data[0]['lon'])) {
            return null;
        }

        return [
            'lat' => (float)$data[0]['lat'],
            'lng' => (float)$data[0]['lon']
        ];
    }
}
