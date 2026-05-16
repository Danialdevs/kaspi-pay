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

        $st = Db::pdo()->prepare(
            'INSERT INTO products (user_id, name, description, price, image_url, active)
             VALUES (:u, :n, :d, :p, :i, :a)'
        );
        $st->execute([
            ':u' => $userId,
            ':n' => $name,
            ':d' => isset($data['description']) ? (string)$data['description'] : null,
            ':p' => $price,
            ':i' => isset($data['image_url']) && $data['image_url'] !== '' ? (string)$data['image_url'] : null,
            ':a' => isset($data['active']) ? (int)(bool)$data['active'] : 1,
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
        return $row ?: null;
    }

    public static function findActive(int $userId, int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM products WHERE id = :id AND user_id = :u AND active = 1');
        $st->execute([':id' => $id, ':u' => $userId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function listForUser(int $userId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT id, name, description, price, image_url, active, created_at
             FROM products WHERE user_id = :u ORDER BY id DESC'
        );
        $st->execute([':u' => $userId]);
        return $st->fetchAll();
    }

    /** @return array<int, array<string,mixed>> */
    public static function listActiveForUser(int $userId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT id, name, description, price, image_url
             FROM products WHERE user_id = :u AND active = 1 ORDER BY id DESC'
        );
        $st->execute([':u' => $userId]);
        return $st->fetchAll();
    }
}
