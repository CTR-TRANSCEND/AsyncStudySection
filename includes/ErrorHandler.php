<?php
declare(strict_types=1);

class ErrorHandler
{
    public static function register(): void
    {
        if (self::isTestEnvironment()) {
            return;
        }

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    public static function handleException(\Throwable $exception): void
    {
        self::logException($exception);

        if (headers_sent()) {
            echo self::formatError($exception);
            return;
        }

        http_response_code(500);

        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo self::formatDebugError($exception);
        } else {
            echo self::formatProductionError();
        }
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $exception = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            self::handleException($exception);
        }
    }

    private static function isTestEnvironment(): bool
    {
        if (defined('PHPUNIT_RUNNING')) {
            return true;
        }

        $appEnv = defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: '');
        if ($appEnv === 'testing') {
            return true;
        }

        if (PHP_SAPI === 'cli' && class_exists('PHPUnit\Framework\TestCase', false)) {
            return true;
        }

        return false;
    }

    private static function logException(\Throwable $exception): void
    {
        $message = sprintf(
            '[%s] %s in %s:%d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
        error_log($message);
    }

    private static function formatDebugError(\Throwable $exception): string
    {
        $class = htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $exception->getLine();
        $trace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html><head><title>Error</title></head><body>
<h1>Error: {$class}</h1>
<p><strong>Message:</strong> {$message}</p>
<p><strong>File:</strong> {$file}:{$line}</p>
<pre>{$trace}</pre>
</body></html>
HTML;
    }

    private static function formatProductionError(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html><head><title>Error</title></head><body>
<h1>Something went wrong</h1>
<p>An unexpected error occurred. Please try again later.</p>
</body></html>
HTML;
    }

    private static function formatError(\Throwable $exception): string
    {
        if (defined('APP_DEBUG') && APP_DEBUG) {
            return htmlspecialchars(
                sprintf('[%s] %s', get_class($exception), $exception->getMessage()),
                ENT_QUOTES,
                'UTF-8'
            );
        }
        return 'An unexpected error occurred.';
    }
}
