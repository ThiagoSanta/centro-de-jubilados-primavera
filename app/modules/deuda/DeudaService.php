<?php

namespace CJP\Modules\Deuda;

use Exception;
use CJP\Shared\Exceptions\AppException;
use DateTime;
use CJP\Config\Database;

class DeudaService
{
    private DeudaRepository $deudaRepository;
    private ConfiguracionCuotaRepository $cuotaRepository;

    public function __construct(
        ?DeudaRepository $deudaRepository = null,
        ?ConfiguracionCuotaRepository $cuotaRepository = null
    ) {
        $this->deudaRepository = $deudaRepository ?? new DeudaRepository();
        $this->cuotaRepository = $cuotaRepository ?? new ConfiguracionCuotaRepository();
    }

    public function generarDeudaMensual(string $periodo, bool $confirmarDuplicado, string $usuarioId): array
    {
        // 1. Asegurar que el período cumpla estrictamente con el formato estándar 'AAAA-MM'
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            throw new AppException("Formato de período inválido. Debe ser AAAA-MM.", 400);
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
                    if (!$confirmarDuplicado) {
                        $advertencias[] = $socioId;
                        $omitidas++;
                    } else {
                        // Reactivar la deuda existente marcándola como 'pendiente' en caso de haber sido modificada previamente
                        $this->deudaRepository->updateEstado($deudaExistente['id'], 'pendiente');
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

            // 6. Asentar en la bitácora de auditoría la generación masiva de cuotas del período
            $this->deudaRepository->registerAuditEvent(
                'GENERACION_DEUDA_MENSUAL',
                'deudas',
                null,
                $periodo,
                $usuarioId,
                null
            );

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

        // 2. Validar que el socio no cuente ya con un saldo histórico migrado ('deuda_anterior')
        $deudaAnterior = $this->deudaRepository->findBySocioAndPeriodo($socioId, 'deuda_anterior');
        if ($deudaAnterior) {
            throw new AppException("El socio ya tiene una deuda anterior registrada.", 409);
        }

        // 3. Crear el registro de deuda histórica con el monto y motivo suministrados
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
        if (empty(trim($motivo))) {
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
            $motivo
        );
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
        if (!isset($datos['monto']) || (float)$datos['monto'] <= 0) {
            throw new AppException("El monto debe ser mayor a 0.", 400);
        }

        if (!isset($datos['fecha_vigencia_desde']) || !strtotime($datos['fecha_vigencia_desde'])) {
            throw new AppException("La fecha de vigencia es inválida.", 400);
        }

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
