<?php
declare(strict_types=1);

namespace Kaspi\Routes;

use Kaspi\Http;
use Kaspi\User;

/**
 * Магазины создаются ТОЛЬКО админом через CLI (bin/create-shop.php),
 * публичной регистрации нет. Токен (apiKey) принадлежит магазину.
 *
 *   POST /api/account/login          публично, логинит
 *   POST /api/account/logout         сессионный logout
 *   GET  /api/account/me             текущий магазин (сессия ИЛИ apiKey)
 *   POST /api/account/regenerate-key создаёт новый apiKey, инвалидирует старый
 */
final class Account
{
    public static function dispatch(string $sub, string $method): never
    {
        match (true) {
            $sub === 'login'           && $method === 'POST' => self::login(),
            $sub === 'logout'          && $method === 'POST' => self::logout(),
            $sub === 'me'              && $method === 'GET'  => self::me(),
            $sub === 'regenerate-key'  && $method === 'POST' => self::regenerateKey(),
            default => Http::error('Account: Not Found', 404),
        };
    }

    private static function login(): never
    {
        $b = Http::jsonBody();
        $username = (string)($b['username'] ?? '');
        $password = (string)($b['password'] ?? '');
        try {
            $u = User::login($username, $password);
        } catch (\Throwable $e) {
            Http::error($e->getMessage(), 401);
        }
        self::startSession($u->id);
        Http::json([
            'ok'       => true,
            'id'       => $u->id,
            'username' => $u->username,
        ]);
    }

    private static function logout(): never
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
        Http::json(['ok' => true]);
    }

    private static function me(): never
    {
        $u = Http::requireUser();
        Http::json([
            'ok'       => true,
            'id'       => $u->id,
            'username' => $u->username,
            'apiKey'   => $u->apiKey,
        ]);
    }

    private static function regenerateKey(): never
    {
        $u = Http::requireUser();
        $newKey = User::regenerateApiKey($u->id);
        Http::json(['ok' => true, 'apiKey' => $newKey]);
    }

    private static function startSession(int $userId): void
    {
        @session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }
}
