<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = strtolower((string)env('DB_DRIVER', 'mysql'));
    if ($driver !== 'mysql') {
        throw new RuntimeException('Only MySQL/MariaDB is configured in this build.');
    }

    $dbHost = (string)env('DB_HOST', 'localhost');
    $dbPort = (string)env('DB_PORT', '3306');
    $dbName = (string)env('DB_DATABASE', '');
    $dbUser = (string)env('DB_USERNAME', '');
    $dbPass = (string)env('DB_PASSWORD', '');
    if ($dbName === '') {
        throw new RuntimeException('Database name is not configured.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $dbHost,
        $dbPort,
        $dbName,
        (string)env('DB_CHARSET', 'utf8mb4')
    );

    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]
    );

    return $pdo;
}
