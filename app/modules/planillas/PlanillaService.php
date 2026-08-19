<?php

namespace CJP\Modules\Planillas;

use Exception;
use CJP\Shared\Exceptions\AppException;
use CJP\Modules\Zonas\ZonaRepository;

class PlanillaService
{
    private PlanillaRepository $planillaRepo;
    private ZonaRepository $zonaRepo;

    public function __construct()
    {
        $this->planillaRepo = new PlanillaRepository();
        $this->zonaRepo = new ZonaRepository();
    }

    public function generar(string $zonaId, string $cobradorId, string $usuarioId): array
    {
        $zona = $this->zonaRepo->findById($zonaId);
        if (!$zona) {
            throw new AppException("La zona especificada no existe.", 404);
        }

        $cobradores = $this->planillaRepo->getCobradores();
        $cobrador = null;
        foreach ($cobradores as $c) {
            if ($c['id'] === $cobradorId) {
                $cobrador = $c;
                break;
            }
        }
        if (!$cobrador) {
            throw new AppException("El cobrador especificado no es válido.", 400);
        }

        $sociosOriginales = $this->planillaRepo->getSociosConDeudaDomiciliaria($zonaId);
        if (empty($sociosOriginales)) {
            throw new AppException("No hay socios con deuda domiciliaria en esta zona.", 422);
        }

        $geolocalizados = [];
        $sinGeolocalizar = [];

        foreach ($sociosOriginales as $socio) {
            if ($socio['geolocalizacion_pendiente'] || empty($socio['latitud']) || empty($socio['longitud'])) {
                $sinGeolocalizar[] = $socio;
            } else {
                $geolocalizados[] = $socio;
            }
        }

        // Algoritmo Nearest Neighbor
        $sedeLat = -32.820366;
        $sedeLng = -61.403157;
        
        $ordenados = [];
        $actualLat = $sedeLat;
        $actualLng = $sedeLng;
        
        while (!empty($geolocalizados)) {
            $mejorDistancia = INF;
            $mejorIndice = -1;
            
            foreach ($geolocalizados as $idx => $socio) {
                // Distancia euclidiana (sqrt((lat2-lat1)^2 + (lng2-lng1)^2))
                $dist = sqrt(pow($socio['latitud'] - $actualLat, 2) + pow($socio['longitud'] - $actualLng, 2));
                if ($dist < $mejorDistancia) {
                    $mejorDistancia = $dist;
                    $mejorIndice = $idx;
                }
            }
            
            $elegido = $geolocalizados[$mejorIndice];
            $ordenados[] = $elegido;
            $actualLat = $elegido['latitud'];
            $actualLng = $elegido['longitud'];
            
            array_splice($geolocalizados, $mejorIndice, 1);
        }

        // Ordenar alfabéticamente los sin geolocalizar
        usort($sinGeolocalizar, function ($a, $b) {
            return strcmp($a['nombre_apellido'], $b['nombre_apellido']);
        });

        foreach ($sinGeolocalizar as $sg) {
            $ordenados[] = $sg;
        }

        $sociosParaInsertar = [];
        $orden = 1;
        foreach ($ordenados as $socio) {
            $sociosParaInsertar[] = [
                'socio_id' => $socio['socio_id'],
                'orden' => $orden,
                'deuda_snapshot' => $socio['detalle_deudas']
            ];
            $orden++;
        }

        $planillaId = $this->planillaRepo->create([
            'fecha_generacion' => date('Y-m-d H:i:s'),
            'cobrador_id' => $cobradorId,
            'zona_id' => $zonaId
        ]);

        $this->planillaRepo->insertSocios($planillaId, $sociosParaInsertar);
        
        $rutaPdf = $this->generarPDF($planillaId, $ordenados, $zona, $cobrador);
        $this->planillaRepo->updatePdf($planillaId, $rutaPdf);

        $this->planillaRepo->registerAuditEvent($usuarioId, 'crear', 'planilla', $planillaId, [
            'zona_id' => $zonaId,
            'cobrador_id' => $cobradorId,
            'cantidad_socios' => count($ordenados)
        ]);

        return $this->planillaRepo->findById($planillaId);
    }

    private function generarPDF(string $planillaId, array $sociosOrdenados, array $zona, array $cobrador): string
    {
        require_once __DIR__ . '/../../../vendor/setasign/fpdf/fpdf.php';
        
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, utf8_decode('Planilla de Cobranza Domiciliaria'), 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 8, utf8_decode('Fecha: ' . date('d/m/Y')), 0, 1);
        $pdf->Cell(0, 8, utf8_decode('Zona: ' . $zona['nombre']), 0, 1);
        $pdf->Cell(0, 8, utf8_decode('Cobrador: ' . $cobrador['nombre'] . ' ' . $cobrador['apellido']), 0, 1);
        $pdf->Ln(5);

        // Cabecera tabla
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(15, 8, 'Orden', 1, 0, 'C');
        $pdf->Cell(60, 8, 'Nombre y Apellido', 1, 0, 'C');
        $pdf->Cell(60, 8, utf8_decode('Dirección'), 1, 0, 'C');
        $pdf->Cell(25, 8, 'Deuda', 1, 0, 'C');
        $pdf->Cell(30, 8, 'Firma', 1, 1, 'C');

        $pdf->SetFont('Arial', '', 9);
        $orden = 1;
        foreach ($sociosOrdenados as $socio) {
            $pdf->Cell(15, 10, $orden, 1, 0, 'C');
            
            $nombreCorto = substr($socio['nombre_apellido'], 0, 30);
            $pdf->Cell(60, 10, utf8_decode($nombreCorto), 1, 0, 'L');
            
            $dirCorta = substr($socio['direccion'], 0, 30);
            $pdf->Cell(60, 10, utf8_decode($dirCorta), 1, 0, 'L');
            
            $pdf->Cell(25, 10, '$ ' . number_format($socio['deuda_total'], 2, ',', '.'), 1, 0, 'R');
            $pdf->Cell(30, 10, '', 1, 1, 'C'); // Espacio firma
            $orden++;
        }

        $dir = __DIR__ . '/../../../storage/planillas';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $filename = $planillaId . '.pdf';
        $path = $dir . '/' . $filename;
        $pdf->Output('F', $path);

        return 'storage/planillas/' . $filename;
    }

    public function getHistorico(array $filtros, int $pagina): array
    {
        return $this->planillaRepo->findAll($filtros, $pagina);
    }

    public function getPlanilla(string $id): array
    {
        $planilla = $this->planillaRepo->findById($id);
        if (!$planilla) {
            throw new AppException("Planilla no encontrada", 404);
        }
        return $planilla;
    }

    public function getCobradores(): array
    {
        return $this->planillaRepo->getCobradores();
    }
}
