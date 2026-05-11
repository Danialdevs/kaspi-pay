<?php
declare(strict_types=1);

namespace Kaspi\Routes;

use Kaspi\Http;
use Kaspi\KaspiSession;

/**
 *   GET    /api/sessions/list                       → список своих Kaspi-кассиров
 *   POST   /api/sessions/rename       {id, name}    → переименовать
 *   POST   /api/sessions/delete       {id}          → удалить
 *   POST   /api/sessions/rotate-token {id}          → перевыпустить X-Cashier-Token
 */
final class Sessions
{
    public static function dispatch(string $sub, string $method): never
    {
        $user = Http::requireUser();
        match (true) {
            $sub === 'list'         && $method === 'GET'  => self::list($user->id),
            $sub === 'rename'       && $method === 'POST' => self::rename($user->id),
            $sub === 'delete'       && $method === 'POST' => self::delete($user->id),
            $sub === 'rotate-token' && $method === 'POST' => self::rotateToken($user->id),
            default => Http::error('Sessions: Not Found', 404),
        };
    }

    private static function rotateToken(int $uid): never
    {
        $id = (int)(Http::jsonBody()['id'] ?? 0);
        if ($id <= 0) Http::error('id required', 400);
        $tok = KaspiSession::rotateApiToken($uid, $id);
        if (!$tok) Http::error('Cashier not found', 404);
        Http::json(['ok' => true, 'apiToken' => $tok]);
    }

    private static function list(int $uid): never
    {
        Http::json(['ok' => true, 'sessions' => KaspiSession::listForUser($uid)]);
    }

    private static function rename(int $uid): never
    {
        $b = Http::jsonBody();
        $id = (int)($b['id'] ?? 0);
        $name = trim((string)($b['name'] ?? ''));
        if ($id <= 0)    Http::error('id required', 400);
        if ($name === '') Http::error('name required', 400);
        $ok = KaspiSession::rename($uid, $id, $name);
        if (!$ok) Http::error('Session not found', 404);
        Http::json(['ok' => true]);
    }

    private static function delete(int $uid): never
    {
        $b = Http::jsonBody();
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) Http::error('id required', 400);
        $ok = KaspiSession::delete($uid, $id);
        if (!$ok) Http::error('Session not found', 404);
        Http::json(['ok' => true]);
    }
}
