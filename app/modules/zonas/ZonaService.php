<?php

namespace CJP\Modules\Zonas;

use RuntimeException;
use CJP\Shared\Exceptions\AppException;

class ZonaService
{
    /** Eje horizontal: Ruta 9 (límite norte del Centro) */
    private const LAT_RUTA_9 = -32.811167;

    /** Eje horizontal: Bv. Balcarce (límite sur del Centro) */
    private const LAT_BALCARCE = -32.822137;

    /** Eje vertical: Calle Sarmiento */
    private const LNG_SARMIENTO = -61.389722;

    /** UUIDs fijos de las 6 zonas */
    private const ZONAS = [
        'Norte Oeste'  => '11111111-1111-1111-1111-111111111101',
        'Norte Este'   => '11111111-1111-1111-1111-111111111102',
        'Centro Oeste' => '11111111-1111-1111-1111-111111111103',
        'Centro Este'  => '11111111-1111-1111-1111-111111111104',
        'Sur Oeste'    => '11111111-1111-1111-1111-111111111105',
        'Sur Este'     => '11111111-1111-1111-1111-111111111106',
    ];

    private ZonaRepository $repository;

    /**
     * ZonaService constructor.
     *
     * @param ZonaRepository|null $repository
     */
    public function __construct(?ZonaRepository $repository = null)
    {
        $this->repository = $repository ?? new ZonaRepository();
    }

    /**
     * Assign a zone based on geographic coordinates.
     *
     * Boundary tie-breaking rules:
     * - Exactly on Ruta 9 (lat == -32.811167): assigned to Centro
     * - Exactly on Bv. Balcarce (lat == -32.822137): assigned to Centro
     * - Exactly on Calle Sarmiento (lng == -61.389722): assigned to Oeste
     *
     * @param float $lat Latitude (negative for southern hemisphere)
     * @param float $lng Longitude (negative for western hemisphere)
     * @return string UUID of the assigned zone
     */
    public function asignarZona(float $lat, float $lng): string
    {
        // Determine latitude band
        // In negative coords: more north = larger value (e.g. -32.80 > -32.81)
        if ($lat > self::LAT_RUTA_9) {
            // North of Ruta 9
            $banda = 'Norte';
        } elseif ($lat >= self::LAT_BALCARCE) {
            // Between Ruta 9 and Bv. Balcarce (inclusive on both boundaries → Centro)
            $banda = 'Centro';
        } else {
            // South of Bv. Balcarce
            $banda = 'Sur';
        }

        // Determine longitude sector
        // In negative coords: more west = smaller value (e.g. -61.40 < -61.39)
        // Exactly on Sarmiento → Oeste (tie-breaking)
        $sector = ($lng <= self::LNG_SARMIENTO) ? 'Oeste' : 'Este';

        $nombre = "{$banda} {$sector}";

        return self::ZONAS[$nombre];
    }

    /**
     * Get the name of a zone by its UUID.
     *
     * @param string $zonaId
     * @return string
     * @throws RuntimeException If the zone does not exist
     */
    public function getNombreZona(string $zonaId): string
    {
        $zona = $this->repository->findById($zonaId);

        if ($zona === null) {
            throw new AppException("La zona con ID '{$zonaId}' no existe.", 404);
        }

        return $zona['nombre'];
    }

    /**
     * Retrieve all zones.
     *
     * @return array
     */
    public function getTodasLasZonas(): array
    {
        return $this->repository->findAll();
    }
}
