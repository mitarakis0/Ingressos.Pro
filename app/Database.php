<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = trim((string) ($_ENV['DB_HOST'] ?? '127.0.0.1'));
        $port = trim((string) ($_ENV['DB_PORT'] ?? '3306'));
        $database = trim((string) ($_ENV['DB_NAME'] ?? 'ingressos_abacatepay'));
        $username = (string) ($_ENV['DB_USER'] ?? 'root');
        $password = (string) ($_ENV['DB_PASS'] ?? '');
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        // Mantém NOW(), DATETIME e os prazos de reserva alinhados ao fuso da aplicação.
        $timezoneOffset = (new \DateTimeImmutable('now'))->format('P');
        self::$connection->exec('SET time_zone = ' . self::$connection->quote($timezoneOffset));

        return self::$connection;
    }
}
