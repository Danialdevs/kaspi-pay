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
            $sub === 'upload' && $method === 'POST' => self::upload($user->id),
            default => Http::error('Products: Not Found', 404),
        };
    }

    /** Принимает multipart `file`, сохраняет в data/uploads/products/<uid>/<uuid>.<ext>, отдаёт public URL. */
    private static function upload(int $uid): never
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Http::error('No file', 400);
        }
        if (($f['size'] ?? 0) > 8 * 1024 * 1024) Http::error('File too large (max 8 MB)', 413);

        $tmp = $f['tmp_name'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($extMap[$mime])) Http::error('Only JPG, PNG, WebP, GIF allowed', 415);

        $ext = $extMap[$mime];
        $rel = "products/{$uid}/" . bin2hex(random_bytes(12)) . '.' . $ext;
        $dir = \Kaspi\Config::$dataDir . '/uploads/' . dirname($rel);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) Http::error('Cannot create dir', 500);

        $dst = \Kaspi\Config::$dataDir . '/uploads/' . $rel;
        if (!move_uploaded_file($tmp, $dst)) Http::error('Save failed', 500);
        @chmod($dst, 0644);

        Http::json(['ok' => true, 'url' => '/uploads/' . $rel]);
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
