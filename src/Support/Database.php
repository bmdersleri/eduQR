<?php

declare(strict_types=1);

namespace EduQR\Support;

use EduQR\Config;
use PDO;
use PDOException;

/**
 * PDO factory with locked, security-hardened settings.
 *
 * Settings per NFR-26:
 *   ATTR_EMULATE_PREPARES = false  — real prepared statements
 *   ATTR_ERRMODE = ERRMODE_EXCEPTION
 *   ATTR_DEFAULT_FETCH_MODE = FETCH_ASSOC
 *   charset utf8mb4
 */
final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    /** Reset the singleton — used only in integration tests. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private static function createConnection(): PDO
    {
        $host = Config::get('DB_HOST', 'localhost');
        $port = Config::int('DB_PORT', 3306);
        $name = Config::require('DB_NAME');
        $user = Config::require('DB_USER');
        $pass = Config::get('DB_PASS', '');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $name
        );

        $options = [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $mysqlInitCommandOption = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
            ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
            : PDO::MYSQL_ATTR_INIT_COMMAND;
        $options[$mysqlInitCommandOption] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'";

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Never leak credentials in the error message
            throw new \RuntimeException(
                'Database connection failed. Check DB_HOST, DB_NAME, DB_USER, DB_PASS in .env.',
                (int) $e->getCode()
            );
        }

        return $pdo;
    }
}
