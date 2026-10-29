<?php

namespace CJP\Config;

use PDO;
use PDOException;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Get a PDO database instance (Singleton pattern).
     *
     * @return PDO
     * @throws RuntimeException
     */
    public static function getInstance(): PDO
    {
        return self::getConnection();
    }

    /**
     * Alias kept for compatibility with repositories that call getConnection().
     *
     * @return PDO
     * @throws RuntimeException
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = Config::get('DB_HOST', 'localhost');
            $dbName = Config::get('DB_NAME');
            $username = Config::get('DB_USER');
            $password = Config::get('DB_PASS');

            if (empty($dbName)) {
                throw new RuntimeException("Database configuration error: DB_NAME is not set.");
            }

            $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Sincroniza la sesion de MySQL con la hora oficial de Argentina (UTC-3)
                // para que funciones SQL como NOW() o CURRENT_TIMESTAMP coincidan con date() en PHP
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-03:00'",
            ];

            try {
                self::$instance = new PDO($dsn, $username, $password, $options);
                self::$instance->exec("SET time_zone = '-03:00'");
            } catch (PDOException $e) {
                throw new RuntimeException("Error al conectar con la base de datos", (int)$e->getCode(), $e);
            }
        }

        return self::$instance;
    }
}
