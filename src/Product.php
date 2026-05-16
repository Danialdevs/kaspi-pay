<?php
declare(strict_types=1);

namespace Kaspi;

final class Product
{
    public static function create(int $userId, array $data): int
    {
        $name  = trim((string)($data['name'] ?? ''));
        $price = (float)($data['price'] ?? 0);
        if ($name === '')   throw new \InvalidArgumentException('name required');
        if ($price <= 0)    throw new \InvalidArgumentException('price must be > 0');

        $images = self::normalizeImages($data['images'] ?? null);
        $cover  = isset($data['image_url']) && $data['image_url'] !== ''
            ? (string)$data['image_url']
            : ($images[0] ?? null);

        $st = Db::pdo()->prepare(
            'INSERT INTO products (user_id, name, description, price, image_url, images, active)
             VALUES (:u, :n, :d, :p, :i, :imgs, :a)'
        );
        $st->execute([
            ':u'    => $userId,
            ':n'    => $name,
            ':d'    => isset($data['description']) ? (string)$data['description'] : null,
            ':p'    => $price,
            ':i'    => $cover,
            ':imgs' => $images ? json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':a'    => isset($data['active']) ? (int)(bool)$data['active'] : 1,
        ]);
        return (int)Db::pdo()->lastInsertId();
    }

    public static function update(int $userId, int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id, ':u' => $userId];

        foreach (['name' => 'name', 'description' => 'description', 'image_url' => 'image_url'] as $col => $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "$col = :$col";
                $params[":$col"] = $data[$key] === '' ? null : (string)$data[$key];
            }
        }
        if (array_key_exists('images', $data)) {
            $images = self::normalizeImages($data['images']);
            $fields[] = 'images = :images';
            $params[':images'] = $images ? json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            // Если cover не задан явно — берём первое из images
            if (!array_key_exists('image_url', $data) && $images) {
                $fields[] = 'image_url = :image_url';
                $params[':image_url'] = $images[0];
            }
        }
        if (array_key_exists('price', $data)) {
            $fields[] = 'price = :price';
            $params[':price'] = (float)$data['price'];
        }
        if (array_key_exists('active', $data)) {
            $fields[] = 'active = :active';
            $params[':active'] = (int)(bool)$data['active'];
        }
        if (!$fields) return false;

        $st = Db::pdo()->prepare(
            'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :u'
        );
        $st->execute($params);
        return $st->rowCount() > 0;
    }

    /** @param mixed $raw — array | JSON string | null. Returns clean array of URLs. */
    public static function normalizeImages(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $raw);
        }
        if (!is_array($raw)) return [];
        $out = [];
        foreach ($raw as $u) {
            $u = trim((string)$u);
            if ($u === '') continue;
            if (strlen($u) > 1024) continue;
            $out[] = $u;
        }
        return array_values(array_unique($out));
    }

    /** Add decoded `images` array to each row. */
    public static function decodeImages(array $row): array
    {
        if (isset($row['images']) && is_string($row['images']) && $row['images'] !== '') {
            $j = json_decode($row['images'], true);
            $row['images'] = is_array($j) ? $j : [];
        } else {
            $row['images'] = [];
        }
        // Fallback: ensure cover is first
        if (empty($row['images']) && !empty($row['image_url'])) {
            $row['images'] = [$row['image_url']];
        }
        return $row;
    }

    public static function delete(int $userId, int $id): bool
    {
        $st = Db::pdo()->prepare('DELETE FROM products WHERE id = :id AND user_id = :u');
        $st->execute([':id' => $id, ':u' => $userId]);
        return $st->rowCount() > 0;
    }

    public static function find(int $userId, int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM products WHERE id = :id AND user_id = :u');
        $st->execute([':id' => $id, ':u' => $userId]);
        $row = $st->fetch();
        return $row ? self::decodeImages($row) : null;
    }

    public static function findActive(int $userId, int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM products WHERE id = :id AND user_id = :u AND active = 1');
        $st->execute([':id' => $id, ':u' => $userId]);
        $row = $st->fetch();
        return $row ? self::decodeImages($row) : null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function listForUser(int $userId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT id, name, description, price, image_url, images, active, created_at
             FROM products WHERE user_id = :u ORDER BY id DESC'
        );
        $st->execute([':u' => $userId]);
        return array_map([self::class, 'decodeImages'], $st->fetchAll());
    }

    /** @return array<int, array<string,mixed>> */
    public static function listActiveForUser(int $userId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT id, name, description, price, image_url, images
             FROM products WHERE user_id = :u AND active = 1 ORDER BY id DESC'
        );
        $st->execute([':u' => $userId]);
        return array_map([self::class, 'decodeImages'], $st->fetchAll());
    }
}
