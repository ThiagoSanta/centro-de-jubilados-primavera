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
     * Valida documento nacional de identidad (DNI). Solo dígitos numéricos (6 a 10 dígitos).
     */
    public static function validarDni(string $valor): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            throw new AppException("El DNI es obligatorio.", 422);
        }

        // Quitar posibles puntos de separación de miles para normalizar
        $limpio = str_replace(['.', ' ', '-'], '', $valor);

        if (!ctype_digit($limpio) || strlen($limpio) < 6 || strlen($limpio) > 10) {
            throw new AppException("El DNI debe contener entre 6 y 10 dígitos numéricos.", 422);
        }

        return $limpio;
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
     * Valida fechas en formato AAAA-MM-DD.
     */
    public static function validarFecha(string $valor, string $campo = 'fecha'): string
    {
        $valor = trim($valor);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            throw new AppException("El campo {$campo} debe tener el formato AAAA-MM-DD.", 422);
        }

        [$anio, $mes, $dia] = explode('-', $valor);
        if (!checkdate((int)$mes, (int)$dia, (int)$anio)) {
            throw new AppException("El campo {$campo} contiene una fecha inexistente en el calendario.", 422);
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
