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
     * Constructor de ZonaService.
     * Inyecta el repositorio de zonas.
     *
     * @param ZonaRepository|null $repository
     */
    public function __construct(?ZonaRepository $repository = null)
    {
        $this->repository = $repository ?? new ZonaRepository();
    }

    /**
     * Determina la zona asignada según las coordenadas geográficas del domicilio.
     *
     * Reglas de desempate en límites limítrofes:
     * - Exactamente sobre Ruta 9 (lat == -32.811167): asigna a banda Centro
     * - Exactamente sobre Bv. Balcarce (lat == -32.822137): asigna a banda Centro
     * - Exactamente sobre Calle Sarmiento (lng == -61.389722): asigna a sector Oeste
     *
     * @param float $lat Latitud (valor negativo en hemisferio sur)
     * @param float $lng Longitud (valor negativo en hemisferio oeste)
     * @return string UUID de la zona asignada
     */
    public function asignarZona(float $lat, float $lng): string
    {
        // 1. Determinar la franja de latitud (Norte, Centro o Sur)
        // En coordenadas negativas del hemisferio sur, mayor valor algebraico implica mayor proximidad al norte (ej. -32.80 está más al norte que -32.81)
        if ($lat > self::LAT_RUTA_9) {
            // Franja al norte de la Ruta Nacional 9
            $banda = 'Norte';
        } elseif ($lat >= self::LAT_BALCARCE) {
            // Franja central entre Ruta 9 y Bv. Balcarce (ambos límites incluidos en Centro)
            $banda = 'Centro';
        } else {
            // Franja al sur de Bv. Balcarce
            $banda = 'Sur';
        }

        // 2. Determinar el sector de longitud (Oeste o Este según Calle Sarmiento)
        // En coordenadas negativas del hemisferio oeste, menor valor implica mayor proximidad al oeste (ej. -61.40 está más al oeste que -61.39)
        // Regla de desempate: coincidencia exacta sobre el eje de Sarmiento se asigna a Oeste
        $sector = ($lng <= self::LNG_SARMIENTO) ? 'Oeste' : 'Este';

        $nombre = "{$banda} {$sector}";

        return self::ZONAS[$nombre];
    }

    /**
     * Obtiene el nombre descriptivo de una zona a partir de su UUID.
     *
     * @param string $zonaId UUID de la zona
     * @return string Nombre de la zona
     * @throws RuntimeException Si la zona solicitada no existe en el catálogo
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
     * Retorna el catálogo completo de zonas registradas.
     *
     * @return array Colección de zonas
     */
    public function getTodasLasZonas(): array
    {
        return $this->repository->findAll();
    }
}
