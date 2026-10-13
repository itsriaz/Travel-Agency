<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    public static function connection(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $connection = new PDO($dsn, $config['username'], $config['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => 3,
                ]);

                self::applySessionTimezone($connection);

                return $connection;
            } catch (PDOException $exception) {
                $driverCode = (int) ($exception->errorInfo[1] ?? 0);
                $transient = in_array($driverCode, [2002, 2003, 2006, 2013], true);
                if ($transient && $attempt < 3) {
                    usleep($attempt * 100000);
                    continue;
                }

                error_log('app.database.connection_failed ' . json_encode([
                    'request_id' => function_exists('app_request_id') ? app_request_id() : null,
                    'sqlstate' => (string) $exception->getCode(),
                    'driver_code' => $driverCode,
                    'attempts' => $attempt,
                    'transient' => $transient,
                ], JSON_UNESCAPED_SLASHES));
                throw new PDOException('Database connection failed.', (int) $exception->getCode(), $exception);
            }
        }

        throw new PDOException('Database connection failed.');
    }

    private static function applySessionTimezone(PDO $connection): void
    {
        $timezoneName = (string) config('app.timezone', 'UTC');

        try {
            $timezone = new \DateTimeZone($timezoneName);
            $offsetSeconds = $timezone->getOffset(new \DateTimeImmutable('now', $timezone));
            $sign = $offsetSeconds >= 0 ? '+' : '-';
            $absoluteOffset = abs($offsetSeconds);
            $hours = str_pad((string) intdiv($absoluteOffset, 3600), 2, '0', STR_PAD_LEFT);
            $minutes = str_pad((string) intdiv($absoluteOffset % 3600, 60), 2, '0', STR_PAD_LEFT);
            $mysqlOffset = $sign . $hours . ':' . $minutes;

            $statement = $connection->prepare('SET time_zone = :time_zone');
            $statement->execute(['time_zone' => $mysqlOffset]);
        } catch (\Throwable $exception) {
            app_log_exception($exception, 'app.database.session_timezone_failed');
        }
    }
}
