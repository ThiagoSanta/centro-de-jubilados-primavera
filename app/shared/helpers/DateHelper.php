<?php

namespace CJP\Shared\Helpers;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

class DateHelper
{
    /**
     * Obtiene la fecha y hora actual en zona horaria de Argentina formateada en 'Y-m-d H:i:s'.
     *
     * @return string Fecha y hora actual
     */
    public static function now(): string
    {
        // Fuerza la zona de Argentina (UTC-3) para garantizar coherencia incluso si se invoca desde CLI
        return (new DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s');
    }

    /**
     * Obtiene la fecha actual en zona horaria de Argentina formateada en 'Y-m-d'.
     *
     * @return string Fecha actual
     */
    public static function today(): string
    {
        return (new DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
    }

    /**
     * Suma (o resta si es negativo) una cantidad de días a una fecha dada, retornando en formato 'Y-m-d H:i:s'.
     *
     * @param string $date Fecha base en formato parseable por DateTime
     * @param int $days Cantidad de días a sumar o restar
     * @return string Fecha resultante
     * @throws InvalidArgumentException Si el formato de fecha proporcionado es inválido
     */
    public static function addDays(string $date, int $days): string
    {
        try {
            $dateTime = new DateTimeImmutable($date);
            $modifier = $days >= 0 ? "+{$days} days" : "{$days} days";
            return $dateTime->modify($modifier)->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid date format provided: {$date}");
        }
    }

    /**
     * Determina si una fecha y hora ha expirado (si se encuentra en el pasado respecto al momento actual).
     *
     * @param string $datetime Fecha y hora a evaluar
     * @return bool True si la fecha ya pasó, false en caso contrario
     * @throws InvalidArgumentException Si el valor no es una fecha válida
     */
    public static function isExpired(string $datetime): bool
    {
        try {
            $target = new DateTimeImmutable($datetime);
            $now = new DateTimeImmutable();
            return $target < $now;
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid datetime format provided: {$datetime}");
        }
    }
}
