<?php
/**
 * Central error boundary. Emits structured JSON to stderr/Azure logs while
 * returning only a correlation ID to callers.
 */
final class ErrorHandler
{
    private static string $requestId = '';

    public static function register(): void
    {
        if (self::$requestId !== '') return;

        self::$requestId = self::resolveRequestId();
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        header('X-Request-ID: ' . self::$requestId);

        // Some legacy admin templates still declare a permissive CSP. Replace
        // it at the final header boundary so unsafe-eval can never reach the
        // browser while the inline-script refactor is completed incrementally.
        if (function_exists('header_register_callback')
            && str_contains($_SERVER['REQUEST_URI'] ?? '', '/public/admin/')) {
            header_register_callback(static function (): void {
                if (headers_sent()) return;
                header_remove('Content-Security-Policy');
                header(
                    "Content-Security-Policy: default-src 'self' data: blob:; " .
                    "script-src 'self' 'unsafe-inline' blob:; " .
                    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
                    "font-src 'self' https://fonts.gstatic.com data:; " .
                    "connect-src 'self' data: blob: ws: wss:; " .
                    "img-src 'self' data: https:; " .
                    "object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'"
                );
            });
        }

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) return false;
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(static function (Throwable $error): void {
            self::report($error, 'uncaught_exception');
            if (!headers_sent()) {
                http_response_code(500);
                header('Cache-Control: no-store');
                if (self::expectsJson()) {
                    header('Content-Type: application/json; charset=utf-8');
                } else {
                    header('Content-Type: text/plain; charset=utf-8');
                }
            }
            $message = 'Unexpected server error. Reference: ' . self::$requestId;
            echo self::expectsJson()
                ? json_encode(['ok' => false, 'error' => $message, 'request_id' => self::$requestId])
                : $message;
        });

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            self::write([
                'level' => 'critical',
                'event' => 'fatal_error',
                'error_type' => $error['type'],
                'message' => $error['message'],
                'file' => self::relativePath($error['file']),
                'line' => $error['line'],
            ]);
        });
    }

    public static function report(Throwable $error, string $event = 'handled_exception', array $context = []): void
    {
        self::write(array_merge([
            'level' => 'error',
            'event' => $event,
            'exception' => get_class($error),
            'message' => $error->getMessage(),
            'file' => self::relativePath($error->getFile()),
            'line' => $error->getLine(),
        ], $context));
    }

    public static function requestId(): string
    {
        return self::$requestId;
    }

    private static function write(array $entry): void
    {
        $base = [
            'timestamp' => gmdate('c'),
            'service' => 'hercule-license-server',
            'request_id' => self::$requestId ?: 'startup',
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
        ];
        error_log(json_encode(array_merge($base, $entry), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function resolveRequestId(): string
    {
        $incoming = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        if (is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,80}$/', $incoming)) {
            return $incoming;
        }
        return bin2hex(random_bytes(12));
    }

    private static function expectsJson(): bool
    {
        return str_contains($_SERVER['REQUEST_URI'] ?? '', '/public/api/')
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private static function relativePath(string $path): string
    {
        $root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
    }
}
