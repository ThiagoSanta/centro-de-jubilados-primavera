<?php

namespace CJP\Shared\Services;

class WhatsAppService
{
    /**
     * Genera la URL de WhatsApp con el mensaje de confirmación de pago.
     *
     * @param array $socio
     * @param array $deudas
     * @param array $pago
     * @return string
     */
    public function generarLinkPago(array $socio, array $deudas, array $pago): string
    {
        $texto = "Hola {$socio['nombre']}, confirmamos su pago en el Centro de Jubilados Primavera.\n\n";
        $texto .= "Monto Total: $" . number_format($pago['monto_total'], 2, ',', '.') . "\n";
        $texto .= "Períodos abonados:\n";
        foreach ($deudas as $deuda) {
            $periodoLabel = $deuda['periodo'] === 'deuda_anterior' ? 'Deuda Anterior' : date('m/Y', strtotime($deuda['periodo'] . '-01'));
            $texto .= "- {$periodoLabel}\n";
        }
        $texto .= "\n¡Muchas gracias!";

        return "https://wa.me/?text=" . urlencode($texto);
    }
}
