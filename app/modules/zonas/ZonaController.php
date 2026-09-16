<?php

namespace CJP\Modules\Zonas;

use CJP\Shared\AuthMiddleware;
use CJP\Shared\Helpers\ResponseHelper;

class ZonaController
{
    private ZonaService $zonaService;

    /**
     * Constructor de ZonaController.
     * Inyecta el servicio de cálculo y consulta de zonas geográficas.
     *
     * @param ZonaService|null $zonaService
     */
    public function __construct(?ZonaService $zonaService = null)
    {
        $this->zonaService = $zonaService ?? new ZonaService();
    }

    /**
     * GET /api/zonas — Retorna el catálogo completo de zonas de cobranza definidas en la ciudad.
     *
     * @param array $params
     * @return void
     */
    public function index(array $params): void
    {
        AuthMiddleware::requireAuth();

        $zonas = $this->zonaService->getTodasLasZonas();
        ResponseHelper::success($zonas, 'Zonas obtenidas exitosamente.');
    }

    /**
     * POST /api/zonas/calcular — Calcula la zona geográfica asignada a partir de un par de coordenadas (latitud y longitud).
     *
     * @param array $params
     * @return void
     */
    public function calcular(array $params): void
    {
        AuthMiddleware::requireAuth();

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!isset($input['lat']) || !isset($input['lng'])) {
            ResponseHelper::error('Los campos "lat" y "lng" son obligatorios.', 400);
            return;
        }

        $lat = (float) $input['lat'];
        $lng = (float) $input['lng'];

        $zonaId = $this->zonaService->asignarZona($lat, $lng);
        $nombreZona = $this->zonaService->getNombreZona($zonaId);

        ResponseHelper::success([
            'zona_id' => $zonaId,
            'nombre'  => $nombreZona,
            'lat'     => $lat,
            'lng'     => $lng,
        ], 'Zona calculada exitosamente.');
    }
}
