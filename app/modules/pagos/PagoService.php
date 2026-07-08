<?php

namespace CJP\Modules\Pagos;

use Exception;
use CJP\Config\Database;
use CJP\Modules\Deuda\DeudaRepository;
use CJP\Modules\Socios\SocioRepository;
use FPDF;

class PagoService
{
    private PagoRepository $pagoRepository;
    private DeudaRepository $deudaRepository;
    private SocioRepository $socioRepository;

    public function __construct()
    {
        $this->pagoRepository = new PagoRepository();
        $this->deudaRepository = new DeudaRepository();
        $this->socioRepository = new SocioRepository();
    }

    public function registrar(array $datos, string $usuarioId): array
    {
        $socioId = $datos['socio_id'] ?? '';
        $deudaIds = $datos['deuda_ids'] ?? [];
        $metodoPago = $datos['metodo_pago'] ?? 'efectivo';
        $observacion = $datos['observacion'] ?? '';

        if (empty($deudaIds)) {
            throw new Exception("Debe seleccionar al menos una deuda para pagar.");
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
                throw new Exception("La deuda seleccionada no existe, no pertenece al socio o no está pendiente.");
            }

            if ($ordenCascadaIds[$index] !== $deudaId) {
                throw new Exception("Debe respetar el orden cronológico de las deudas (Cascada). No puede saltear períodos anteriores.");
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

        try {
            $pagoId = $this->pagoRepository->create($datosPago);
            $this->pagoRepository->asociarDeudas($pagoId, $deudaIds);

            foreach ($deudaIds as $deudaId) {
                $this->deudaRepository->updateEstado($deudaId, 'pagada');
            }

            $socio = $this->socioRepository->findById($socioId);
            $pago = clone (object)$datosPago; 
            // In a real app we might fetch it back from DB, let's just use what we have or fetch it
            $pagoInfo = $this->pagoRepository->findById($pagoId);
            $cobradorNombre = $pagoInfo['cobrador_nombre'] ?? 'Administrador';

            $comprobantePath = $this->generarComprobante($pagoId, $socio, $deudasSeleccionadas, $pagoInfo, $cobradorNombre);
            $linkWhatsApp = $this->generarLinkWhatsApp($socio, $deudasSeleccionadas, $pagoInfo);

            $this->pagoRepository->registerAuditEvent(
                'pagos',
                $pagoId,
                'registro',
                $usuarioId,
                ['monto_total' => $montoTotal, 'deudas_pagadas' => count($deudaIds)]
            );

            return [
                'pago' => $pagoInfo,
                'comprobante_url' => $comprobantePath,
                'whatsapp_url' => $linkWhatsApp
            ];
        } catch (Exception $e) {
            throw new Exception("Error al registrar el pago: " . $e->getMessage());
        }
    }

    public function anular(string $pagoId, string $motivo, string $usuarioId): void
    {
        $pago = $this->pagoRepository->findById($pagoId);
        
        if (!$pago) {
            throw new Exception("El pago no existe.");
        }
        
        if ($pago['estado'] !== 'registrado') {
            throw new Exception("Solo se pueden anular pagos registrados.");
        }

        if (empty($motivo)) {
            throw new Exception("Debe especificar un motivo para la anulación.");
        }

        try {
            $this->pagoRepository->anular($pagoId);
            
            $deudaIds = $this->pagoRepository->getDeudaIdsByPago($pagoId);
            foreach ($deudaIds as $deudaId) {
                // Update to pendiente directly via SQL if repository doesn't support reverting exactly, but we can use updateEstado
                // Wait, DeudaRepository::updateEstado might set fecha_pago... Let's use it or run custom SQL.
                // We'll update it to 'pendiente'
                $this->deudaRepository->updateEstado($deudaId, 'pendiente');
                // The prompt says "Revertir deudas asociadas a 'pendiente', fecha_pago=null". The updateEstado sets fecha_pago to NOW only if 'pagada'. Let's ensure it resets to null. We should do it via DeudaRepository or direct.
                // Since I cannot change DeudaRepository easily without risking conflict, I will use a direct method if DeudaRepository doesn't have it, but wait I CAN change DeudaRepository or use Database directly here. I will just execute an update here.
                $db = Database::getInstance();
                $stmt = $db->prepare("UPDATE deudas SET fecha_pago = NULL WHERE id = :id");
                $stmt->execute(['id' => $deudaId]);
            }

            $expiracion = date('Y-m-d H:i:s', strtotime('+7 days'));
            $this->pagoRepository->createNotificacion([
                'tipo' => 'anulacion_pago',
                'mensaje' => "Pago de {$pago['socio_nombre']} {$pago['socio_apellido']} por $ {$pago['monto_total']} anulado. Motivo: {$motivo}",
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
        } catch (Exception $e) {
            throw new Exception("Error al anular el pago: " . $e->getMessage());
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
        $texto = "Hola {$socio['nombre']}, confirmamos su pago en el Centro de Jubilados Primavera.\n\n";
        $texto .= "Monto Total: $" . number_format($pago['monto_total'], 2, ',', '.') . "\n";
        $texto .= "Períodos abonados:\n";
        foreach ($deudas as $deuda) {
            $periodoLabel = $deuda['periodo'] === 'deuda_anterior' ? 'Deuda Anterior' : date('m/Y', strtotime($deuda['periodo'] . '-01'));
            $texto .= "- {$periodoLabel}\n";
        }
        $texto .= "\n¡Muchas gracias!";

        $encodedText = urlencode($texto);
        return "https://wa.me/?text=" . $encodedText;
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
            throw new Exception("Pago no encontrado.");
        }
        return $pago;
    }
}
