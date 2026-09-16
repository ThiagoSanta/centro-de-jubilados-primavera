<?php

namespace CJP\Modules\Socios;

use Exception;
use CJP\Shared\Exceptions\AppException;
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
     * Constructor de SocioService.
     * Inyecta el repositorio de socios y el servicio de cálculo de zonas geográficas.
     */
    public function __construct(?SocioRepository $repository = null, ?ZonaService $zonaService = null)
    {
        $this->repository = $repository ?? new SocioRepository();
        $this->zonaService = $zonaService ?? new ZonaService();
    }

    /**
     * Registra un nuevo socio realizando validaciones de campos, geocodificación de dirección, cálculo de zona y generación de QR.
     *
     * @param array $datos Datos del socio recibidos en la solicitud
     * @param string $usuarioId UUID del usuario que ejecuta el alta
     * @return array Ficha completa del socio recién creado
     * @throws Exception Si faltan datos obligatorios o existen inconsistencias
     */
    public function crear(array $datos, string $usuarioId): array
    {
        // 1. Validar integridad de campos obligatorios, formatos de fecha y tipo de documento
        $this->validarDatosSocio($datos);

        $dni = trim($datos['dni']);
        if ($this->repository->findByDni($dni) !== null) {
            throw new AppException("El DNI ingresado ya pertenece a un socio.", 409);
        }

        // 2. Obtener el siguiente número correlativo de socio disponible
        $numeroSocio = $this->repository->getNextNumeroSocio();

        // 3. Geocodificar la dirección postal mediante el servicio Nominatim de OpenStreetMap
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

        // 4. Generar identificador único global (UUID v4) para el nuevo socio
        $socioId = Uuid::uuid4()->toString();

        // 5. Generar y almacenar la imagen del código QR identificatorio del socio
        $qrUrl = $this->generarQR($socioId);

        // 6. Estructurar el payload final para la inserción en la base de datos
        $payload = [
            'id'                        => $socioId,
            'numero_socio'              => $numeroSocio,
            'tipo_documento'           => !empty($datos['tipo_documento']) ? $datos['tipo_documento'] : 'dni',
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

        // 7. Asentar el evento de creación del socio en el registro de auditoría
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
     * Edita la información de un socio existente con validación de doble confirmación en cambios de DNI.
     *
     * @param string $id UUID del socio a modificar
     * @param array $datos Campos enviados para actualización
     * @param string $usuarioId UUID del usuario operador
     * @return array Ficha actualizada del socio
     * @throws Exception Si las validaciones de negocio fallan o el socio no existe
     */
    public function editar(string $id, array $datos, string $usuarioId): array
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new AppException("Socio no encontrado.", 404);
        }

        $payload = [];
        $valAnterior = [];

        // 1. Validar reglas de formato para campos modificados (teléfono, email, fechas)
        if (isset($datos['nombre_apellido'])) {
            $val = trim($datos['nombre_apellido']);
            if (empty($val)) {
                throw new AppException("El nombre y apellido son obligatorios.", 400);
            }
            $payload['nombre_apellido'] = $val;
            $valAnterior['nombre_apellido'] = $socio['nombre_apellido'];
        }

        if (isset($datos['fecha_nacimiento'])) {
            if (!$this->validarFecha($datos['fecha_nacimiento'])) {
                throw new AppException("Formato de fecha de nacimiento incorrecto (debe ser YYYY-MM-DD).", 400);
            }
            $payload['fecha_nacimiento'] = $datos['fecha_nacimiento'];
            $valAnterior['fecha_nacimiento'] = $socio['fecha_nacimiento'];
        }

        if (isset($datos['telefono'])) {
            $val = trim($datos['telefono']);
            if (empty($val)) {
                throw new AppException("El teléfono es obligatorio.", 400);
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
                throw new AppException("La modalidad de cobranza debe ser 'cobranza_domiciliaria' o 'cobranza_en_sede'.", 400);
            }
            $payload['modalidad_cobranza'] = $val;
            $valAnterior['modalidad_cobranza'] = $socio['modalidad_cobranza'];
        }

        // 2. Detección y verificación de cambio en el documento de identidad
        $dniCambiado = isset($datos['dni']) && trim($datos['dni']) !== $socio['dni'];
        $tipoDocCambiado = isset($datos['tipo_documento']) && trim($datos['tipo_documento']) !== ($socio['tipo_documento'] ?? 'dni');

        if ($tipoDocCambiado) {
            $tipoDoc = trim($datos['tipo_documento']);
            $tiposValidos = ['dni', 'libreta_civica', 'libreta_enrolamiento', 'pasaporte'];
            if (!in_array($tipoDoc, $tiposValidos, true)) {
                throw new AppException("Tipo de documento inválido.", 400);
            }
            $payload['tipo_documento'] = $tipoDoc;
            $valAnterior['tipo_documento'] = $socio['tipo_documento'] ?? 'dni';
        }

        if ($dniCambiado || $tipoDocCambiado) {
            // Verificar si el operador posee permisos suficientes para alterar el documento del socio
            $rol = $this->repository->getUserRole($usuarioId);
            if ($rol !== 'administrador') {
                throw new AppException("Solo los administradores pueden modificar el documento o tipo de documento de un socio.", 403);
            }

            // Exigir bandera explícita de confirmación ante cambios en el número de documento
            if ($dniCambiado && (!isset($datos['confirmar_cambio_dni']) || $datos['confirmar_cambio_dni'] !== true)) {
                throw new AppException("Se requiere doble confirmación para modificar el DNI.", 400);
            }
        }

        if ($dniCambiado) {
            $newDni = trim($datos['dni']);
            if (empty($newDni)) {
                throw new AppException("El número de documento es obligatorio.", 400);
            }

            // Garantizar la unicidad del nuevo DNI verificando que no pertenezca a otro socio registrado
            if ($this->repository->findByDni($newDni) !== null) {
                throw new AppException("El documento ingresado ya pertenece a otro socio.", 409);
            }

            // Validar formato de documento según la tipología seleccionada (DNI, Pasaporte, Cédula)
            $activeTipoDoc = $payload['tipo_documento'] ?? ($socio['tipo_documento'] ?? 'dni');
            $dniLimpio = preg_replace('/[^0-9a-zA-Z]/', '', $newDni);
            if ($activeTipoDoc === 'pasaporte') {
                if (!preg_match('/^[a-zA-Z0-9]+$/', $dniLimpio)) {
                    throw new AppException("El pasaporte solo puede contener caracteres alfanuméricos.", 400);
                }
            } else {
                if (!ctype_digit($dniLimpio)) {
                    throw new AppException("El número de documento debe ser exclusivamente numérico.", 400);
                }
            }

            $payload['dni'] = $newDni;
            $valAnterior['dni'] = $socio['dni'];
        }

        // 3. Detectar si el domicilio postal fue modificado para re-geolocalizarlo
        if (isset($datos['direccion']) && trim($datos['direccion']) !== $socio['direccion']) {
            $newAddress = trim($datos['direccion']);
            if (empty($newAddress)) {
                throw new AppException("La dirección es obligatoria.", 400);
            }

            $payload['direccion'] = $newAddress;
            $valAnterior['direccion'] = $socio['direccion'];

            // Re-geocodificar la nueva dirección y reasignar la zona geográfica correspondiente
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

        // 4. Persistir los cambios en la base de datos
        $this->repository->update($id, $payload);

        // 5. Registrar en auditoría las diferencias entre los valores previos y los nuevos
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
     * Suspende a un socio activo previas verificaciones de estado y permisos.
     *
     * @param string $id UUID del socio
     * @param string $usuarioId UUID del operador
     * @return void
     * @throws Exception Si el socio no existe o ya no se encuentra activo
     */
    public function suspender(string $id, string $usuarioId): void
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new AppException("Socio no encontrado.", 404);
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
     * Reactiva a un socio suspendido devolviéndolo al estado 'activo'.
     *
     * @param string $id UUID del socio
     * @param string $usuarioId UUID del operador
     * @return void
     * @throws Exception Si el socio no se encuentra suspendido
     */
    public function reactivar(string $id, string $usuarioId): void
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new AppException("Socio no encontrado.", 404);
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
     * Efectúa la baja lógica de un socio, programa alerta de reversión por 7 días y audita el evento.
     *
     * @param string $id UUID del socio
     * @param string $motivo Justificación de la baja
     * @param string $usuarioId UUID del operador
     * @return void
     * @throws Exception Si el socio no existe o ya fue dado de baja
     */
    public function eliminar(string $id, string $motivo, string $usuarioId): void
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new AppException("Socio no encontrado.", 404);
        }

        if (empty(trim($motivo))) {
            throw new AppException("El motivo de la baja es obligatorio.", 400);
        }

        // 1. Ejecutar la baja lógica en la base de datos preservando la integridad referencial
        $this->repository->softDelete($id, trim($motivo));

        // 2. Generar notificación con ventana de gracia de 7 días para permitir la reversión de la baja
        $expiration = DateHelper::addDays(DateHelper::now(), 7);
        $this->repository->createNotification([
            'tipo'                       => 'reversion_baja',
            'mensaje'                    => "Se ha registrado la baja del socio {$socio['nombre_apellido']} (N° {$socio['numero_socio']}) por: " . trim($motivo) . ".",
            'referencia'                 => ['entidad' => 'socios', 'id' => $id],
            'fecha_expiracion_reversion' => $expiration
        ]);

        // 3. Registrar el evento de baja lógica con su respectivo motivo en auditoría
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
     * Revierte la baja lógica de un socio dentro del plazo legal de 7 días posteriores a su registro.
     *
     * @param string $id UUID del socio
     * @param string $usuarioId UUID del operador
     * @return void
     * @throws Exception Si venció el plazo de 7 días o el socio no está dado de baja
     */
    public function revertirEliminacion(string $id, string $usuarioId): void
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new AppException("Socio no encontrado.", 404);
        }

        if ($socio['estado'] !== 'eliminado') {
            throw new AppException("El socio no se encuentra en estado eliminado.", 400);
        }

        // Validar que no hayan transcurrido más de 7 días desde la fecha_baja registrada
        if (empty($socio['fecha_baja'])) {
            throw new AppException("Fecha de baja no registrada.", 400);
        }

        $fechaExpiracion = DateHelper::addDays($socio['fecha_baja'], 7);
        if (DateHelper::isExpired($fechaExpiracion)) {
            throw new AppException("El plazo de 7 días para revertir la eliminación ha expirado.", 400);
        }

        // Restablecer el estado activo del socio y limpiar las marcas temporales de baja
        $this->repository->reactivate($id);

        // Registrar en auditoría la reversión formal de la baja lógica
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
     * Corrige manualmente las coordenadas geográficas de un socio y recalcula su zona de cobranza.
     *
     * @param string $id UUID del socio
     * @param float $lat Nueva latitud
     * @param float $lng Nueva longitud
     * @param string $usuarioId UUID del operador
     * @return void
     * @throws Exception Si las coordenadas son inválidas o el socio no existe
     */
    public function corregirGeolocalizacion(string $id, float $lat, float $lng, string $usuarioId): void
    {
        $rol = $this->repository->getUserRole($usuarioId);
        if ($rol !== 'administrador') {
            throw new AppException("Solo los administradores pueden corregir manualmente la geolocalización.", 403);
        }

        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new AppException("Socio no encontrado.", 404);
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

        // Asentar en auditoría la rectificación manual de coordenadas y reasignación de zona
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
     * Importa un lote de socios desde un archivo CSV con georreferenciación y reporte de inconsistencias.
     *
     * @param string $rutaArchivo Ruta temporal del archivo CSV recibido
     * @param string $usuarioId UUID del usuario que realiza la importación
     * @return array Balance del proceso con conteos de ['exitosos' => int, 'fallidos' => int]
     * @throws Exception Si el archivo no es legible o su estructura es incompatible
     */
    public function importarCSV(string $rutaArchivo, string $usuarioId): array
    {
        if (!file_exists($rutaArchivo)) {
            throw new AppException("Archivo CSV no encontrado.", 400);
        }

        $file = fopen($rutaArchivo, 'r');
        if (!$file) {
            throw new AppException("No se pudo abrir el archivo CSV.", 400);
        }

        // Eliminar la marca de orden de bytes (BOM) UTF-8 al inicio del archivo si está presente
        $bom = pack('H*', 'EFBBBF');
        $line = fgets($file);
        if (str_starts_with($line, $bom)) {
            $line = substr($line, 3);
        }

        // Detectar automáticamente si el delimitador de campos es punto y coma (;) o coma (,)
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
                throw new AppException("El archivo CSV no contiene las columnas requeridas: " . implode(', ', $requiredHeaders), 400);
            }
        }

        $exitosos = 0;
        $fallidos = 0;

        // Localizar las posiciones ordinales de las columnas obligatorias en la cabecera CSV
        $indices = [];
        foreach ($header as $idx => $name) {
            $indices[$name] = $idx;
        }

        $maxRows = 2000;
        $totalProcesadas = 0;

        while (($row = fgetcsv($file, 0, str_contains($line, ';') ? ';' : ',')) !== false) {
            if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                continue;
            }

            $totalProcesadas++;
            if ($totalProcesadas > $maxRows) {
                fclose($file);
                throw new AppException("El archivo supera el límite máximo permitido de {$maxRows} filas por importación.", 400);
            }

            // Mapear los valores de la fila actual hacia los campos de la entidad socio
            $rowData = [];
            foreach ($indices as $name => $idx) {
                $val = isset($row[$idx]) ? trim($row[$idx]) : '';
                $rowData[$name] = $this->sanitizeFormulaInjection($val);
            }

            $errors = [];

            // 1. Validar la presencia ineludible de nombre y apellido en la fila actual
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

            // 2. Descartar filas con documentos de identidad duplicados respecto a socios ya existentes
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

            // 3. Obtener coordenadas geográficas para la dirección e insertar el nuevo registro de socio
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
                    'tipo_documento'           => !empty($rowData['tipo_documento']) ? $rowData['tipo_documento'] : 'dni',
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

                // Registrar el evento de importación masiva en la tabla de auditoría
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
     * Obtiene las inconsistencias registradas en importaciones CSV según los filtros indicados.
     *
     * @param array $filtros Filtros de consulta (ej. 'estado' => 'pendiente'|'resuelto')
     * @return array Lista de inconsistencias
     */
    public function getInconsistencias(array $filtros): array
    {
        return $this->repository->getInconsistencias($filtros);
    }

    /**
     * Consulta el padrón de socios aplicando filtros de búsqueda y paginación.
     *
     * @param array $filtros Criterios de filtrado
     * @param int $pagina Página a recuperar
     * @return array Colección paginada de socios
     */
    public function listar(array $filtros, int $pagina): array
    {
        return $this->repository->findAll($filtros, $pagina);
    }

    /**
     * Obtiene la información detallada de un socio lanzando una excepción si no existe.
     *
     * @param string $id UUID del socio
     * @return array Ficha del socio
     * @throws Exception Si el socio no fue hallado
     */
    public function obtener(string $id): array
    {
        $socio = $this->repository->findById($id);
        if ($socio === null) {
            throw new AppException("Socio no encontrado.", 404);
        }
        return $socio;
    }

    /**
     * Genera el archivo PNG con el código QR del socio y retorna su ruta relativa pública.
     *
     * @param string $socioId UUID del socio
     * @return string URL pública del archivo QR generado
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
     * Valida la consistencia, presencia y formatos válidos de los campos de un socio.
     *
     * @param array $datos Datos a verificar
     * @return void
     * @throws Exception Si algún campo no cumple las reglas de negocio
     */
    private function validarDatosSocio(array $datos): void
    {
        if (empty($datos['nombre_apellido'])) {
            throw new AppException("El nombre y apellido son obligatorios.", 400);
        }

        $tipoDoc = !empty($datos['tipo_documento']) ? $datos['tipo_documento'] : 'dni';
        $tiposValidos = ['dni', 'libreta_civica', 'libreta_enrolamiento', 'pasaporte'];
        if (!in_array($tipoDoc, $tiposValidos, true)) {
            throw new AppException("Tipo de documento inválido.", 400);
        }

        if (empty($datos['dni'])) {
            throw new AppException("El número de documento es obligatorio.", 400);
        }

        $dniLimpio = preg_replace('/[^0-9a-zA-Z]/', '', $datos['dni']);
        if ($tipoDoc === 'pasaporte') {
            if (!preg_match('/^[a-zA-Z0-9]+$/', $dniLimpio)) {
                throw new AppException("El pasaporte solo puede contener caracteres alfanuméricos.", 400);
            }
        } else {
            if (!ctype_digit($dniLimpio)) {
                throw new AppException("El número de documento debe ser exclusivamente numérico.", 400);
            }
        }

        if (empty($datos['fecha_nacimiento']) || !$this->validarFecha($datos['fecha_nacimiento'])) {
            throw new AppException("Formato de fecha de nacimiento incorrecto (debe ser YYYY-MM-DD).", 400);
        }

        if (empty($datos['telefono'])) {
            throw new AppException("El teléfono es obligatorio.", 400);
        }

        if (empty($datos['direccion'])) {
            throw new AppException("La dirección es obligatoria.", 400);
        }

        if (empty($datos['modalidad_cobranza'])) {
            throw new AppException("La modalidad de cobranza es obligatoria.", 400);
        }

        $mod = $datos['modalidad_cobranza'];
        if ($mod !== 'cobranza_domiciliaria' && $mod !== 'cobranza_en_sede') {
            throw new AppException("La modalidad de cobranza debe ser 'cobranza_domiciliaria' o 'cobranza_en_sede'.", 400);
        }
    }

    /**
     * Valida que una cadena coincida con el formato de fecha ISO 'AAAA-MM-DD'.
     *
     * @param string $date Cadena de texto a evaluar
     * @return bool True si la fecha es sintácticamente válida
     */
    private function validarFecha(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Consulta la API de geocodificación de OpenStreetMap (Nominatim) para resolver coordenadas de una dirección.
     *
     * @param string $direccion Dirección física (calle y número)
     * @return array|null Arreglo con ['lat' => float, 'lng' => float] o null si no se resolvió
     */
    private function geocodeAddress(string $direccion): ?array
    {
        $addressQuery = $direccion;
        if (!str_contains(strtolower($direccion), 'cañada de gómez') && !str_contains(strtolower($direccion), 'canada de gomez')) {
            $addressQuery .= ', Cañada de Gómez, Santa Fe, Argentina';
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

    /**
     * Sanitiza campos de texto contra CSV / Formula Injection (Excel / Sheets).
     *
     * @param string $val
     * @return string
     */
    private function sanitizeFormulaInjection(string $val): string
    {
        if ($val === '') {
            return $val;
        }

        $first = $val[0];
        // Fórmulas estándar que inician con =, @, tabulación o retorno de carro
        if ($first === '=' || $first === '@' || $first === "\t" || $first === "\r") {
            return "'" . $val;
        }

        // + o - no seguidos de un número (para no alterar teléfonos como +54...)
        if (($first === '+' || $first === '-') && isset($val[1]) && !ctype_digit($val[1])) {
            return "'" . $val;
        }

        return $val;
    }
}
