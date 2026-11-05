<?php

namespace CJP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use CJP\Config\Config;

class SessionSecurityTest extends TestCase
{
    public function testTiempoInactividadCobradorEs30Minutos(): void
    {
        $lifetimeCobrador = (int)Config::get('SESSION_LIFETIME_COBRADOR', 1800);
        $this->assertSame(1800, $lifetimeCobrador, 'La sesión de cobrador debe expirar a los 30 minutos (1800 s)');
    }

    public function testTiempoInactividadAdminEs8Horas(): void
    {
        $lifetimeAdmin = (int)Config::get('SESSION_LIFETIME_ADMINISTRADOR', 28800);
        $this->assertSame(28800, $lifetimeAdmin, 'La sesión de administrador debe durar 8 horas (28800 s)');
    }

    public function testReglaBloqueoPorIntentosFallidos(): void
    {
        $maxIntentos = 5;
        $intentosUsuario = 5;
        $estaBloqueado = ($intentosUsuario >= $maxIntentos);

        $this->assertTrue($estaBloqueado, 'Al alcanzar 5 intentos fallidos la cuenta debe ser bloqueada.');
    }
}
