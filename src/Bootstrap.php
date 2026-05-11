<?php
declare(strict_types=1);

namespace Kaspi;

final class Bootstrap
{
    public static function init(string $rootDir): void
    {
        // PSR-4 autoloader (no Composer required)
        spl_autoload_register(function (string $class) use ($rootDir): void {
            $prefix = 'Kaspi\\';
            if (!str_starts_with($class, $prefix)) return;
            $rel = substr($class, strlen($prefix));
            $path = $rootDir . '/src/' . str_replace('\\', '/', $rel) . '.php';
            if (is_file($path)) require_once $path;
        });

        // ── Error handling: never let PHP leak HTML to the API client ──
        $logDir = $rootDir . '/data/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', $logDir . '/error.log');
        error_reporting(E_ALL);

        set_exception_handler(static function (\Throwable $e): void {
            self::emitFatal($e->getMessage(), $e->getFile() . ':' . $e->getLine(), $e->getTraceAsString());
        });

        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) return false;
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                self::emitFatal($err['message'], $err['file'] . ':' . $err['line'], '');
            }
        });

        Env::load($rootDir . '/.env');
        Config::init($rootDir);
        date_default_timezone_set('Asia/Almaty');

        // ── PHP session (cookie-based shop auth) ──
        // apiKey никогда не уезжает в браузер; вместо него — обычный сессионный cookie.
        if (session_status() === PHP_SESSION_NONE) {
            session_name('kaspi_shop');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'secure'   => !empty($_SERVER['HTTPS']),
                'samesite' => 'Lax',
            ]);
            @session_start();
        }

        // Force MySQL connection + schema once
        Db::pdo();
    }

    private static function emitFatal(string $message, string $where, string $trace): void
    {
        error_log("[uncaught] $message at $where\n$trace");
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => $message, 'where' => $where], JSON_UNESCAPED_UNICODE);
    }
}
