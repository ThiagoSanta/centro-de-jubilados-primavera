<?php

namespace CJP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use CJP\Shared\Helpers\ValidationHelper;
use CJP\Shared\Exceptions\AppException;

class ValidationHelperTest extends TestCase
{
    public function testValidaNombreCorrecto(): void
    {
        $nombre = "María René González-López";
        $resultado = ValidationHelper::validarNombreApellido($nombre);
        $this->assertSame($nombre, $resultado);
    }

    public function testRechazaNombreConNumeros(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage("solo puede contener letras");
        ValidationHelper::validarNombreApellido("Juan Carlos 2do");
    }

    public function testRechazaNombreConSimbolos(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage("solo puede contener letras");
        ValidationHelper::validarNombreApellido("Pedro <script>");
    }

    public function testValidaDniCorrecto(): void
    {
        $this->assertSame("12345678", ValidationHelper::validarDni("12.345.678"));
        $this->assertSame("98765432", ValidationHelper::validarDni("98765432"));
    }

    public function testRechazaDniInvalido(): void
    {
        $this->expectException(AppException::class);
        ValidationHelper::validarDni("12345A78");
    }

    public function testValidaTelefonoCorrecto(): void
    {
        $this->assertSame("+54 3471 423456", ValidationHelper::validarTelefono("+54 3471 423456"));
    }

    public function testRechazaTelefonoConLetras(): void
    {
        $this->expectException(AppException::class);
        ValidationHelper::validarTelefono("3471-telefono");
    }

    public function testValidaUsuarioCorrecto(): void
    {
        $this->assertSame("admin_cjp.2026", ValidationHelper::validarUsuario("Admin_cjp.2026"));
    }

    public function testRechazaUsuarioConEspacios(): void
    {
        $this->expectException(AppException::class);
        ValidationHelper::validarUsuario("usuario con espacios");
    }

    public function testValidaMontoPositivo(): void
    {
        $this->assertSame(1500.50, ValidationHelper::validarMonto("1500.50"));
    }

    public function testRechazaMontoCeroONegativo(): void
    {
        $this->expectException(AppException::class);
        ValidationHelper::validarMonto("-100");
    }

    public function testValidaFechaCorrecta(): void
    {
        $this->assertSame("2026-11-11", ValidationHelper::validarFecha("2026-11-11"));
    }

    public function testRechazaFechaInexistente(): void
    {
        $this->expectException(AppException::class);
        ValidationHelper::validarFecha("2026-02-31");
    }

    public function testSanitizaTextoPlanoYRemueveHtml(): void
    {
        $texto = "<b>Aviso importante:</b> el socio pagó en efectivo <script>alert(1)</script>";
        $limpio = ValidationHelper::validarTextoPlano($texto, 500);
        $this->assertSame("Aviso importante: el socio pagó en efectivo alert(1)", $limpio);
    }

    public function testRechazaTextoPlanoQueExcedeMaximo(): void
    {
        $this->expectException(AppException::class);
        $textoLargo = str_repeat("A", 1001);
        ValidationHelper::validarTextoPlano($textoLargo, 1000);
    }
}
