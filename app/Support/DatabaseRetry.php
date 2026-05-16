<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class DatabaseRetry
{
    public static function run(callable $callback, int $attempts = 5, bool $retryUniqueConstraint = false): mixed
    {
        $attempts = max(1, $attempts);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $callback();
            } catch (QueryException $exception) {
                $shouldRetry = self::isDeadlockOrLockTimeout($exception)
                    || ($retryUniqueConstraint && self::isUniqueConstraintViolation($exception));

                if (! $shouldRetry || $attempt === $attempts) {
                    throw $exception;
                }

                usleep((50 * $attempt) * 1000);
            }
        }

        throw new \RuntimeException('Database retry loop exited unexpectedly.');
    }

    private static function isDeadlockOrLockTimeout(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '40001'
            || $driverCode === 1205
            || $driverCode === 1213
            || str_contains($message, 'deadlock found')
            || str_contains($message, 'lock wait timeout exceeded');
    }

    private static function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === 1062
            || str_contains($message, 'duplicate entry');
    }
}
