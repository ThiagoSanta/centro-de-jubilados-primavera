<?php

namespace CJP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use CJP\Modules\Zonas\ZonaService;

class ZonaServiceTest extends TestCase
{
    private ZonaService $zonaService;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock del repositorio para que las pruebas unitarias de cálculo geográfico no dependan de MySQL
        $mockRepo = $this->createMock(\CJP\Modules\Zonas\ZonaRepository::class);
        $this->zonaService = new ZonaService($mockRepo);
    }

    public function testAsignaNorteOesteCorrectamente(): void
    {
        // Al norte de Ruta 9 (-32.811167) y al oeste de Sarmiento (-61.389722)
        $zonaId = $this->zonaService->asignarZona(-32.805000, -61.395000);
        $this->assertSame('11111111-1111-1111-1111-111111111101', $zonaId);
    }

    public function testAsignaNorteEsteCorrectamente(): void
    {
        // Al norte de Ruta 9 y al este de Sarmiento
        $zonaId = $this->zonaService->asignarZona(-32.805000, -61.380000);
        $this->assertSame('11111111-1111-1111-1111-111111111102', $zonaId);
    }

    public function testAsignaCentroOesteCorrectamente(): void
    {
        // Entre Ruta 9 y Balcarce, al oeste de Sarmiento
        $zonaId = $this->zonaService->asignarZona(-32.815000, -61.395000);
        $this->assertSame('11111111-1111-1111-1111-111111111103', $zonaId);
    }

    public function testAsignaCentroEsteCorrectamente(): void
    {
        // Entre Ruta 9 y Balcarce, al este de Sarmiento
        $zonaId = $this->zonaService->asignarZona(-32.815000, -61.380000);
        $this->assertSame('11111111-1111-1111-1111-111111111104', $zonaId);
    }

    public function testAsignaSurOesteCorrectamente(): void
    {
        // Al sur de Bv. Balcarce (-32.822137) y al oeste de Sarmiento
        $zonaId = $this->zonaService->asignarZona(-32.825000, -61.395000);
        $this->assertSame('11111111-1111-1111-1111-111111111105', $zonaId);
    }

    public function testAsignaSurEsteCorrectamente(): void
    {
        // Al sur de Bv. Balcarce y al este de Sarmiento
        $zonaId = $this->zonaService->asignarZona(-32.825000, -61.380000);
        $this->assertSame('11111111-1111-1111-1111-111111111106', $zonaId);
    }

    public function testReglaDesempateExactamenteSobreRuta9AsignaACentro(): void
    {
        // Regla de negocio: lat == -32.811167 se asigna a banda Centro
        $zonaId = $this->zonaService->asignarZona(-32.811167, -61.395000);
        $this->assertSame('11111111-1111-1111-1111-111111111103', $zonaId);
    }

    public function testReglaDesempateExactamenteSobreBalcarceAsignaACentro(): void
    {
        // Regla de negocio: lat == -32.822137 se asigna a banda Centro
        $zonaId = $this->zonaService->asignarZona(-32.822137, -61.380000);
        $this->assertSame('11111111-1111-1111-1111-111111111104', $zonaId);
    }

    public function testReglaDesempateExactamenteSobreSarmientoAsignaAOeste(): void
    {
        // Regla de negocio: lng == -61.389722 se asigna a sector Oeste
        $zonaId = $this->zonaService->asignarZona(-32.815000, -61.389722);
        $this->assertSame('11111111-1111-1111-1111-111111111103', $zonaId);
    }
}
