<?php
declare(strict_types=1);

namespace Kaspi\Routes;

use Kaspi\Http;
use Kaspi\Product;

final class Products
{
    public static function dispatch(string $sub, string $method): never
    {
        $user = Http::requireUser();
        match (true) {
            $sub === 'list'   && $method === 'GET'  => self::list($user->id),
            $sub === 'create' && $method === 'POST' => self::create($user->id),
            $sub === 'update' && $method === 'POST' => self::update($user->id),
            $sub === 'delete' && $method === 'POST' => self::delete($user->id),
            default => Http::error('Products: Not Found', 404),
        };
    }

    private static function list(int $uid): never
    {
        Http::json(['ok' => true, 'products' => Product::listForUser($uid)]);
    }

    private static function create(int $uid): never
    {
        $b = Http::jsonBody();
        try {
            $id = Product::create($uid, $b);
        } catch (\InvalidArgumentException $e) {
            Http::error($e->getMessage(), 400);
        }
        Http::json(['ok' => true, 'id' => $id]);
    }

    private static function update(int $uid): never
    {
        $b = Http::jsonBody();
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) Http::error('id required', 400);
        unset($b['id']);
        $ok = Product::update($uid, $id, $b);
        if (!$ok) Http::error('Product not found', 404);
        Http::json(['ok' => true]);
    }

    private static function delete(int $uid): never
    {
        $b = Http::jsonBody();
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) Http::error('id required', 400);
        $ok = Product::delete($uid, $id);
        if (!$ok) Http::error('Product not found', 404);
        Http::json(['ok' => true]);
    }
}
