<?php

namespace CJP\Shared\Helpers;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

class DateHelper
{
    /**
     * Get current date and time in Y-m-d H:i:s format.
     *
     * @return string
     */
    public static function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * Get current date in Y-m-d format.
     *
     * @return string
     */
    public static function today(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d');
    }

    /**
     * Add days to a given date and return in Y-m-d H:i:s format.
     * Supports negative integers for subtracting days.
     *
     * @param string $date
     * @param int $days
     * @return string
     * @throws InvalidArgumentException
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
     * Check if a given datetime has expired (is in the past).
     *
     * @param string $datetime
     * @return bool
     * @throws InvalidArgumentException
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
