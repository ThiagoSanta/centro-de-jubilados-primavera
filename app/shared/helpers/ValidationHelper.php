<?php

namespace CJP\Shared\Helpers;

use CJP\Shared\Exceptions\AppException;

class ValidationHelper
{
    /**
     * Valida que un nombre o apellido contenga exclusivamente letras, acentos, diéresis, espacios y guiones/apóstrofes.
     * Rechaza tajantemente números y caracteres especiales.
     */
    public static function validarNombreApellido(string $valor, string $campo = 'nombre y apellido'): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            throw new AppException("El campo {$campo} es obligatorio.", 422);
        }

        if (mb_strlen($valor) < 2 || mb_strlen($valor) > 200) {
            throw new AppException("El campo {$campo} debe tener entre 2 y 200 caracteres.", 422);
        }

        // Solo letras unicode, espacios, apóstrofes y guiones. No admite números ni símbolos.
        if (!preg_match('/^[\p{L}\s\'-]+$/u', $valor)) {
            throw new AppException("El campo {$campo} solo puede contener letras y espacios, sin números ni caracteres especiales.", 422);
        }

        return $valor;
    }

    /**
     * Valida documento nacional de identidad (DNI). Solo dígitos numéricos (6 a 8 dígitos).
     */
    public static function validarDni(string $valor): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            throw new AppException("El DNI es obligatorio.", 422);
        }

        // Quitar posibles puntos de separación de miles para normalizar
        $limpio = str_replace(['.', ' ', '-'], '', $valor);

        if (!ctype_digit($limpio) || strlen($limpio) < 6 || strlen($limpio) > 8) {
            throw new AppException("El DNI debe contener entre 6 y 8 dígitos numéricos.", 422);
        }

        return $limpio;
    }

    /**
     * Valida direcciones postales. Admite caracteres alfanuméricos, espacios y signos de puntuación válidos.
     * Rechaza símbolos especiales no correspondientes a una dirección.
     */
    public static function validarDireccion(string $valor, string $campo = 'dirección'): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            throw new AppException("El campo {$campo} es obligatorio.", 422);
        }

        if (mb_strlen($valor) < 3 || mb_strlen($valor) > 200) {
            throw new AppException("El campo {$campo} debe tener entre 3 y 200 caracteres.", 422);
        }

        if (!preg_match('/^[\p{L}0-9\s.,\-\/º°#]+$/u', $valor)) {
            throw new AppException("El campo {$campo} solo puede contener caracteres alfanuméricos y signos habituales de calle/altura (números, espacios, puntos, comas, guiones, barras, °).", 422);
        }

        return $valor;
    }

    /**
     * Valida teléfono: dígitos, espacios, guiones, paréntesis y opcional '+' al inicio.
     */
    public static function validarTelefono(string $valor): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            throw new AppException("El teléfono es obligatorio.", 422);
        }

        if (!preg_match('/^\+?[0-9\s\-\(\)]{6,30}$/', $valor)) {
            throw new AppException("El formato de teléfono es inválido. Solo debe contener números, espacios, guiones o paréntesis.", 422);
        }

        return $valor;
    }

    /**
     * Valida nombre de usuario para inicio de sesión: alfanumérico, puntos y guiones bajos (3-50 car).
     */
    public static function validarUsuario(string $valor): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            throw new AppException("El nombre de usuario es obligatorio.", 422);
        }

        if (!preg_match('/^[a-zA-Z0-9._]{3,50}$/', $valor)) {
            throw new AppException("El usuario solo puede contener letras, números, puntos y guiones bajos (entre 3 y 50 caracteres).", 422);
        }

        return strtolower($valor);
    }

    /**
     * Valida contraseña (mínimo 6 u 8 caracteres).
     */
    public static function validarPassword(string $valor, int $min = 8): string
    {
        if (strlen($valor) < $min) {
            throw new AppException("La contraseña debe tener al menos {$min} caracteres.", 422);
        }

        return $valor;
    }

    /**
     * Valida importes monetarios (decimales positivos mayores a 0).
     */
    public static function validarMonto(mixed $valor, string $campo = 'monto'): float
    {
        if (!is_numeric($valor)) {
            throw new AppException("El campo {$campo} debe ser un valor numérico válido.", 422);
        }

        $monto = (float)$valor;
        if ($monto <= 0) {
            throw new AppException("El campo {$campo} debe ser mayor a cero.", 422);
        }

        // Redondear a 2 decimales
        return round($monto, 2);
    }

    /**
     * Valida fechas en formato AAAA-MM-DD con límites de coherencia de negocio.
     * Para nacimiento: no futura y edad máxima 120 años (año >= añoActual - 120).
     * Para fechas generales: rango razonable según el campo.
     */
    public static function validarFecha(string $valor, string $campo = 'fecha', array $opciones = []): string
    {
        $valor = trim($valor);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            throw new AppException("El campo {$campo} debe tener el formato AAAA-MM-DD.", 422);
        }

        [$anioStr, $mesStr, $diaStr] = explode('-', $valor);
        $anio = (int)$anioStr;
        $mes = (int)$mesStr;
        $dia = (int)$diaStr;

        if ($mes < 1 || $mes > 12) {
            throw new AppException("El mes del campo {$campo} debe estar entre 1 y 12.", 422);
        }

        if ($dia < 1 || $dia > 31) {
            throw new AppException("El día del campo {$campo} debe estar entre 1 y 31.", 422);
        }

        if (!checkdate($mes, $dia, $anio)) {
            throw new AppException("El campo {$campo} contiene una fecha inexistente en el calendario.", 422);
        }

        $hoy = new \DateTime();
        $anioActual = (int)$hoy->format('Y');
        $esNacimiento = $opciones['esNacimiento'] ?? (stripos($campo, 'nacimiento') !== false);

        if ($esNacimiento) {
            $anioMinimo = $anioActual - 120;
            if ($anio < $anioMinimo) {
                throw new AppException("La fecha de nacimiento no puede ser anterior al año {$anioMinimo} (edad máxima permitida: 120 años).", 422);
            }
            $fechaIngresada = new \DateTime($valor);
            $hoySinHora = new \DateTime($hoy->format('Y-m-d'));
            if ($fechaIngresada > $hoySinHora) {
                throw new AppException("La fecha de nacimiento no puede ser futura.", 422);
            }
        } else {
            $minAnio = $opciones['minAnio'] ?? 2020;
            $maxAnio = $opciones['maxAnio'] ?? ($anioActual + 1);
            if ($anio < $minAnio || $anio > $maxAnio) {
                throw new AppException("El año del campo {$campo} ({$anio}) debe encontrarse entre {$minAnio} y {$maxAnio}.", 422);
            }
        }

        return $valor;
    }

    /**
     * Sanitiza texto plano y valida su longitud máxima.
     */
    public static function validarTextoPlano(?string $valor, int $max = 1000, string $campo = 'texto'): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        $limpio = strip_tags(trim($valor));
        if (mb_strlen($limpio) > $max) {
            throw new AppException("El campo {$campo} excede la longitud máxima permitida de {$max} caracteres.", 422);
        }

        return $limpio;
    }
}
