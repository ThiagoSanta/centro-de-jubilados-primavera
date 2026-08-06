<?php

namespace CJP\Modules\Pagos;

use PDO;
use Exception;
use CJP\Shared\Exceptions\AppException;
use CJP\Config\Database;
use CJP\Modules\Deuda\DeudaRepository;
use CJP\Modules\Socios\SocioRepository;
use CJP\Modules\Notificaciones\NotificacionService;
use CJP\Shared\Services\WhatsAppService;
use FPDF;

class PagoService
{
    private PagoRepository $pagoRepository;
    private DeudaRepository $deudaRepository;
    private SocioRepository $socioRepository;
    private NotificacionService $notificacionService;
    private WhatsAppService $whatsAppService;
    private PDO $db;

    public function __construct(
        ?PagoRepository $pagoRepository = null,
        ?DeudaRepository $deudaRepository = null,
        ?SocioRepository $socioRepository = null,
        ?NotificacionService $notificacionService = null,
        ?WhatsAppService $whatsAppService = null,
        ?PDO $db = null
    ) {
        $this->pagoRepository = $pagoRepository ?? new PagoRepository();
        $this->deudaRepository = $deudaRepository ?? new DeudaRepository();
        $this->socioRepository = $socioRepository ?? new SocioRepository();
        $this->notificacionService = $notificacionService ?? new NotificacionService();
        $this->whatsAppService = $whatsAppService ?? new WhatsAppService();
        $this->db = $db ?? Database::getInstance();
    }

    public function registrar(array $datos, string $usuarioId): array
    {
        $socioId = $datos['socio_id'] ?? '';
        $deudaIds = $datos['deuda_ids'] ?? [];
        $metodoPago = $datos['metodo_pago'] ?? 'efectivo';
        $observacion = $datos['observacion'] ?? '';

        if (empty($deudaIds)) {
            throw new AppException("Debe seleccionar al menos una deuda para pagar.", 400);
        }

        // Obtener todas las deudas pendientes del socio en orden de cascada
        $pendientes = $this->deudaRepository->findPendientesBySocio($socioId);
        
        $pendientesPorId = [];
        $ordenCascadaIds = [];
        foreach ($pendientes as $p) {
            $pendientesPorId[$p['id']] = $p;
            $ordenCascadaIds[] = $p['id'];
        }

        $montoTotal = 0;
        $deudasSeleccionadas = [];

        // Validar que las deudas seleccionadas sean las primeras N en cascada
        foreach ($deudaIds as $index => $deudaId) {
            if (!isset($pendientesPorId[$deudaId])) {
                throw new AppException("La deuda seleccionada no existe, no pertenece al socio o no está pendiente.", 400);
            }

            if ($ordenCascadaIds[$index] !== $deudaId) {
                throw new AppException("Debe respetar el orden cronológico de las deudas (Cascada). No puede saltear períodos anteriores.", 400);
            }

            $montoTotal += (float) $pendientesPorId[$deudaId]['monto'];
            $deudasSeleccionadas[] = $pendientesPorId[$deudaId];
        }

        $datosPago = [
            'socio_id' => $socioId,
            'monto_total' => $montoTotal,
            'metodo_pago' => $metodoPago,
            'observacion' => $observacion,
            'usuario_id' => $usuarioId,
            'fecha_hora' => date('Y-m-d H:i:s')
        ];

        $this->db->beginTransaction();
        try {
            $pagoId = $this->pagoRepository->create($datosPago);
            $this->pagoRepository->asociarDeudas($pagoId, $deudaIds);

            foreach ($deudaIds as $deudaId) {
                $this->deudaRepository->updateEstado($deudaId, 'pagada');
            }

            $this->pagoRepository->registerAuditEvent(
                'pagos',
                $pagoId,
                'registro',
                $usuarioId,
                ['monto_total' => $montoTotal, 'deudas_pagadas' => count($deudaIds)]
            );

            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Operaciones de lectura y generación de comprobantes fuera de la transacción DB
        $socio = $this->socioRepository->findById($socioId);
        $pagoInfo = $this->pagoRepository->findById($pagoId);
        $cobradorNombre = $pagoInfo['cobrador_nombre'] ?? 'Administrador';

        $comprobantePath = $this->generarComprobante($pagoId, $socio, $deudasSeleccionadas, $pagoInfo, $cobradorNombre);
        $linkWhatsApp = $this->whatsAppService->generarLinkPago($socio, $deudasSeleccionadas, $pagoInfo);

        return [
            'pago' => $pagoInfo,
            'comprobante_url' => $comprobantePath,
            'whatsapp_url' => $linkWhatsApp
        ];
    }

    public function anular(string $pagoId, string $motivo, string $usuarioId): void
    {
        $pago = $this->pagoRepository->findById($pagoId);
        
        if (!$pago) {
            throw new AppException("El pago no existe.", 404);
        }
        
        if ($pago['estado'] !== 'registrado') {
            throw new AppException("Solo se pueden anular pagos registrados.", 400);
        }

        if (empty($motivo)) {
            throw new AppException("Debe especificar un motivo para la anulación.", 400);
        }

        $this->db->beginTransaction();
        try {
            $this->pagoRepository->anular($pagoId);
            
            $deudaIds = $this->pagoRepository->getDeudaIdsByPago($pagoId);
            foreach ($deudaIds as $deudaId) {
                $this->deudaRepository->updateEstado($deudaId, 'pendiente');
                $stmt = $this->db->prepare("UPDATE deudas SET fecha_pago = NULL WHERE id = :id");
                $stmt->execute(['id' => $deudaId]);
            }

            $expiracion = date('Y-m-d H:i:s', strtotime('+7 days'));
            $socioNombre = $pago['socio_nombre'] ?? 'Socio';
            $this->notificacionService->crear([
                'tipo' => 'anulacion_pago',
                'mensaje' => "Pago de {$socioNombre} por $ {$pago['monto_total']} anulado. Motivo: {$motivo}",
                'referencia' => ['pago_id' => $pagoId],
                'fecha_expiracion_reversion' => $expiracion
            ]);

            $this->pagoRepository->registerAuditEvent(
                'pagos',
                $pagoId,
                'anulacion',
                $usuarioId,
                ['motivo' => $motivo]
            );

            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function generarComprobante(string $pagoId, array $socio, array $deudas, array $pago, string $cobrador): string
    {
        $dir = __DIR__ . '/../../../storage/comprobantes';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $filename = $pagoId . '.pdf';
        $filepath = $dir . '/' . $filename;

        $pdf = new FPDF();
        $pdf->AddPage();
        
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, utf8_decode('Centro de Jubilados Primavera'), 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, utf8_decode('Comprobante de Pago'), 0, 1, 'C');
        $pdf->Ln(5);
        
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, utf8_decode('Fecha y Hora: ' . date('d/m/Y H:i:s', strtotime($pago['fecha_hora']))), 0, 1);
        $pdf->Cell(0, 8, utf8_decode('Nro. de Socio: ' . $socio['numero_socio']), 0, 1);
        $pdf->Cell(0, 8, utf8_decode('Socio: ' . $socio['nombre'] . ' ' . $socio['apellido']), 0, 1);
        $pdf->Cell(0, 8, utf8_decode('Cobrador: ' . $cobrador), 0, 1);
        $pdf->Cell(0, 8, utf8_decode('Método de Pago: ' . ucfirst($pago['metodo_pago'])), 0, 1);
        $pdf->Ln(5);
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(95, 8, utf8_decode('Período'), 1);
        $pdf->Cell(95, 8, 'Monto', 1, 1, 'R');
        
        $pdf->SetFont('Arial', '', 12);
        foreach ($deudas as $deuda) {
            $periodoLabel = $deuda['periodo'] === 'deuda_anterior' ? 'Deuda Anterior' : date('m/Y', strtotime($deuda['periodo'] . '-01'));
            $pdf->Cell(95, 8, utf8_decode($periodoLabel), 1);
            $pdf->Cell(95, 8, '$ ' . number_format($deuda['monto'], 2, ',', '.'), 1, 1, 'R');
        }
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(95, 8, 'Total Pagado', 1);
        $pdf->Cell(95, 8, '$ ' . number_format($pago['monto_total'], 2, ',', '.'), 1, 1, 'R');

        $pdf->Output('F', $filepath);

        return '/api/pagos/' . $pagoId . '/comprobante';
    }

    public function generarLinkWhatsApp(array $socio, array $deudas, array $pago): string
    {
        return $this->whatsAppService->generarLinkPago($socio, $deudas, $pago);
    }

    public function getPagosBySocio(string $socioId): array
    {
        return $this->pagoRepository->findBySocio($socioId);
    }

    public function getPagos(array $filtros, int $pagina): array
    {
        return $this->pagoRepository->findAll($filtros, $pagina);
    }

    public function getPago(string $id): array
    {
        $pago = $this->pagoRepository->findById($id);
        if (!$pago) {
            throw new AppException("Pago no encontrado.", 404);
        }
        return $pago;
    }
}
