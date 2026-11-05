<?php

namespace CJP\Tests\Integration;

use PHPUnit\Framework\TestCase;
use CJP\Shared\Exceptions\AppException;

class CascadaReglasTest extends TestCase
{
    /**
     * Simula la validación de selección en cascada tal como está implementada en PagoService.
     */
    private function validarCascada(array $pendientes, array $deudaIdsSeleccionados): bool
    {
        if (empty($deudaIdsSeleccionados)) {
            throw new AppException("Debe seleccionar al menos una deuda para pagar.", 400);
        }

        $pendientesPorId = [];
        $ordenCascadaIds = [];
        foreach ($pendientes as $p) {
            $pendientesPorId[$p['id']] = $p;
            $ordenCascadaIds[] = $p['id'];
        }

        foreach ($deudaIdsSeleccionados as $index => $deudaId) {
            if (!isset($pendientesPorId[$deudaId])) {
                throw new AppException("La deuda seleccionada no existe o no pertenece al socio.", 400);
            }

            if (!isset($ordenCascadaIds[$index]) || $ordenCascadaIds[$index] !== $deudaId) {
                throw new AppException("Debe respetar el orden cronológico de las deudas (Cascada).", 400);
            }
        }

        return true;
    }

    public function testPermiteCobroEnOrdenCronologicoEstricto(): void
    {
        $deudas = [
            ['id' => 'uuid-anterior', 'periodo' => 'deuda_anterior', 'monto' => 1000],
            ['id' => 'uuid-2026-01',  'periodo' => '2026-01',        'monto' => 1200],
            ['id' => 'uuid-2026-02',  'periodo' => '2026-02',        'monto' => 1200],
        ];

        // Caso 1: paga solo la más antigua (deuda_anterior)
        $this->assertTrue($this->validarCascada($deudas, ['uuid-anterior']));

        // Caso 2: paga las dos más antiguas en orden
        $this->assertTrue($this->validarCascada($deudas, ['uuid-anterior', 'uuid-2026-01']));

        // Caso 3: paga todas
        $this->assertTrue($this->validarCascada($deudas, ['uuid-anterior', 'uuid-2026-01', 'uuid-2026-02']));
    }

    public function testRechazaSaltearDeudaAnterior(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage("Debe respetar el orden cronológico de las deudas (Cascada)");

        $deudas = [
            ['id' => 'uuid-anterior', 'periodo' => 'deuda_anterior', 'monto' => 1000],
            ['id' => 'uuid-2026-01',  'periodo' => '2026-01',        'monto' => 1200],
        ];

        // Intenta pagar 2026-01 sin saldar la deuda anterior
        $this->validarCascada($deudas, ['uuid-2026-01']);
    }

    public function testRechazaSaltearPeriodoIntermedio(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage("Debe respetar el orden cronológico de las deudas (Cascada)");

        $deudas = [
            ['id' => 'uuid-2026-01', 'periodo' => '2026-01', 'monto' => 1200],
            ['id' => 'uuid-2026-02', 'periodo' => '2026-02', 'monto' => 1200],
            ['id' => 'uuid-2026-03', 'periodo' => '2026-03', 'monto' => 1200],
        ];

        // Intenta pagar 2026-01 y saltar al 2026-03 sin pagar 2026-02
        $this->validarCascada($deudas, ['uuid-2026-01', 'uuid-2026-03']);
    }
}
