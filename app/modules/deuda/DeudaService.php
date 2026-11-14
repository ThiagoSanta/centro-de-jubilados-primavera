<?php

namespace CJP\Modules\Deuda;

use Exception;
use CJP\Shared\Exceptions\AppException;
use DateTime;
use CJP\Config\Database;
use CJP\Shared\Helpers\ValidationHelper;
use CJP\Modules\Notificaciones\NotificacionService;

class DeudaService
{
    private DeudaRepository $deudaRepository;
    private ConfiguracionCuotaRepository $cuotaRepository;
    private NotificacionService $notificacionService;

    public function __construct(
        ?DeudaRepository $deudaRepository = null,
        ?ConfiguracionCuotaRepository $cuotaRepository = null,
        ?NotificacionService $notificacionService = null
    ) {
        $this->deudaRepository = $deudaRepository ?? new DeudaRepository();
        $this->cuotaRepository = $cuotaRepository ?? new ConfiguracionCuotaRepository();
        $this->notificacionService = $notificacionService ?? new NotificacionService();
    }

    public function generarDeudaMensual(string $periodo, bool $confirmarDuplicado, string $usuarioId): array
    {
        // 1. Asegurar que el período cumpla estrictamente con el formato estándar 'AAAA-MM'
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            throw new AppException("Formato de período inválido. Debe ser AAAA-MM.", 400);
        }

        // Validar que el año del período no supere el año actual + 1
        $anioperiodo = (int)substr($periodo, 0, 4);
        $anioMaxPermitido = (int)date('Y') + 1;
        if ($anioperiodo < 2020 || $anioperiodo > $anioMaxPermitido) {
            throw new AppException("El período indicado ({$periodo}) está fuera del rango permitido (2020 - {$anioMaxPermitido}).", 422);
        }

        // 2. Recuperar el importe y la configuración de la cuota social vigente en el sistema
        $cuotaVigente = $this->cuotaRepository->getVigente();
        if (!$cuotaVigente) {
            throw new AppException("No hay una cuota configurada y vigente para generar la deuda.", 400);
        }
        $monto = (float)$cuotaVigente['monto'];

        // 3. Cargar el padrón de socios activos habilitados para la facturación masiva
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id FROM socios WHERE estado = 'activo'");
        $sociosActivos = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($sociosActivos)) {
            return ['generadas' => 0, 'omitidas' => 0, 'advertencias' => []];
        }

        $generadas = 0;
        $omitidas = 0;
        $advertencias = [];

        $db->beginTransaction();

        try {
            foreach ($sociosActivos as $socioId) {
                $deudaExistente = $this->deudaRepository->findBySocioAndPeriodo($socioId, $periodo);

                if ($deudaExistente) {
                    // Las cuotas ya pagadas no se pueden duplicar ni alterar
                    if ($deudaExistente['estado'] === 'pagada') {
                        $omitidas++;
                        continue;
                    }

                    if (!$confirmarDuplicado) {
                        $advertencias[] = $socioId;
                        $omitidas++;
                    } else {
                        // Reactivar si estaba exonerada o actualizar monto si estaba pendiente
                        $this->deudaRepository->updateEstado($deudaExistente['id'], 'pendiente');
                        $stmtUpdate = $db->prepare("UPDATE deudas SET fecha_exoneracion = NULL, monto = :monto WHERE id = :id");
                        $stmtUpdate->execute(['monto' => $monto, 'id' => $deudaExistente['id']]);
                        $generadas++;
                    }
                } else {
                    $this->deudaRepository->create([
                        'socio_id' => $socioId,
                        'periodo' => $periodo,
                        'monto' => $monto,
                        'estado' => 'pendiente'
                    ]);
                    $generadas++;
                }
            }

            // 6. Asentar en la bitácora de auditoría solo si realmente se generaron o reactivaron deudas
            if ($generadas > 0) {
                $this->deudaRepository->registerAuditEvent(
                    'GENERACION_DEUDA_MENSUAL',
                    'deudas',
                    null,
                    $periodo,
                    $usuarioId,
                    "Generadas: {$generadas}, Omitidas: {$omitidas}"
                );
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

        return [
            'generadas' => $generadas,
            'omitidas' => $omitidas,
            'advertencias' => $advertencias
        ];
    }

    public function cargarDeudaAnterior(string $socioId, float $monto, string $usuarioId): void
    {
        // 1. Comprobar que el socio destinatario exista en el padrón y no se encuentre dado de baja
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT estado FROM socios WHERE id = :id");
        $stmt->execute(['id' => $socioId]);
        $estado = $stmt->fetchColumn();

        if (!$estado || $estado !== 'activo') {
            throw new AppException("El socio no existe o no está activo.", 404);
        }

        // 2. Se permiten múltiples registros de deuda_anterior por socio (sin restricción)
        // 3. Crear el registro de deuda histórica con el monto suministrado
        $this->deudaRepository->create([
            'socio_id' => $socioId,
            'periodo' => 'deuda_anterior',
            'monto' => $monto,
            'estado' => 'pendiente'
        ]);

        // 4. Auditar la asignación de saldo inicial/deuda anterior al socio
        $this->deudaRepository->registerAuditEvent(
            'CARGA_DEUDA_ANTERIOR',
            'deudas',
            null,
            $socioId,
            $usuarioId,
            null
        );
    }

    public function exonerarDeuda(string $deudaId, string $motivo, string $usuarioId): void
    {
        $motivoLimpio = ValidationHelper::validarTextoPlano($motivo, 500, 'motivo de exoneración');
        if (empty($motivoLimpio)) {
            throw new AppException("El motivo de exoneración es obligatorio.", 400);
        }

        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM deudas WHERE id = :id");
        $stmt->execute(['id' => $deudaId]);
        $deuda = $stmt->fetch();

        // 1. Validar que la deuda a exonerar exista y que su estado admita exoneración ('pendiente')
        if (!$deuda) {
            throw new AppException("La deuda no existe.", 404);
        }

        if ($deuda['estado'] !== 'pendiente') {
            throw new AppException("Solo se pueden exonerar deudas en estado pendiente.", 400);
        }

        // 2. Marcar la deuda con estado 'exonerada' registrando la marca temporal de cancelación
        $this->deudaRepository->exonerar($deudaId, new DateTime());

        // 3. Dejar constancia formal en auditoría especificando el motivo reglamentario de la exoneración
        $this->deudaRepository->registerAuditEvent(
            'EXONERACION_DEUDA',
            'deudas',
            $deuda['estado'],
            'exonerada',
            $usuarioId,
            $motivoLimpio
        );

        // 4. Registrar notificación de acción con ventana de 7 días
        $stmtSocio = $db->prepare("SELECT nombre_apellido, dni, numero_socio FROM socios WHERE id = :id");
        $stmtSocio->execute(['id' => $deuda['socio_id']]);
        $socio = $stmtSocio->fetch();
        $socioNombre = $socio ? trim($socio['nombre_apellido'] ?? '') : 'Socio';
        if (empty($socioNombre) && $socio && isset($socio['numero_socio'])) {
            $socioNombre = 'Socio N° ' . $socio['numero_socio'];
        }
        $periodoFmt = $deuda['periodo'] === 'deuda_anterior' ? 'Deuda Anterior' : $deuda['periodo'];
        $expiracion = date('Y-m-d H:i:s', strtotime('+7 days'));

        $this->notificacionService->crear([
            'tipo' => 'exoneracion_deuda',
            'mensaje' => "Deuda de {$socioNombre} ({$periodoFmt}) por $ {$deuda['monto']} exonerada/anulada. Motivo: {$motivoLimpio}",
            'referencia' => ['entidad' => 'deudas', 'id' => $deudaId],
            'fecha_expiracion_reversion' => $expiracion
        ]);
    }

    public function getDeudaSocio(string $socioId): array
    {
        return $this->deudaRepository->findBySocio($socioId);
    }

    public function getDeudaPendienteSocio(string $socioId): array
    {
        return $this->deudaRepository->findPendientesBySocio($socioId);
    }

    public function registrarCuota(array $datos, string $usuarioId): array
    {
        $monto = ValidationHelper::validarMonto($datos['monto'] ?? 0, 'monto');
        $fechaVigencia = ValidationHelper::validarFecha($datos['fecha_vigencia_desde'] ?? '', 'fecha de vigencia');

        $datos['monto'] = $monto;
        $datos['fecha_vigencia_desde'] = $fechaVigencia;
        $datos['usuario_id'] = $usuarioId;
        $id = $this->cuotaRepository->create($datos);

        return ['id' => $id];
    }

    public function getCuotaVigente(): ?array
    {
        return $this->cuotaRepository->getVigente();
    }

    public function getHistoricoCuotas(): array
    {
        return $this->cuotaRepository->getHistorico();
    }
}
