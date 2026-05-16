<?php
declare(strict_types=1);

namespace Kaspi;

final class User
{
    public int $id;
    public string $username;
    public string $apiKey;

    public static function create(string $username, string $password): self
    {
        $username = trim($username);
        if (!preg_match('/^[a-zA-Z0-9_.@-]{3,64}$/', $username)) {
            throw new \InvalidArgumentException('Username must be 3–64 chars (letters, digits, _ . @ -)');
        }
        if (strlen($password) < 6) {
            throw new \InvalidArgumentException('Password must be at least 6 chars');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $apiKey = bin2hex(random_bytes(32));

        $st = Db::pdo()->prepare(
            'INSERT INTO users (username, password_hash, api_key) VALUES (:u, :p, :k)'
        );
        try {
            $st->execute([':u' => $username, ':p' => $hash, ':k' => $apiKey]);
        } catch (\PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                throw new \RuntimeException('Username already taken');
            }
            throw $e;
        }
        $id = (int)Db::pdo()->lastInsertId();

        $u = new self();
        $u->id = $id;
        $u->username = $username;
        $u->apiKey = $apiKey;
        return $u;
    }

    public static function login(string $username, string $password): self
    {
        $st = Db::pdo()->prepare('SELECT id, username, password_hash, api_key FROM users WHERE username = :u');
        $st->execute([':u' => trim($username)]);
        $row = $st->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            throw new \RuntimeException('Invalid username or password');
        }
        $u = new self();
        $u->id = (int)$row['id'];
        $u->username = $row['username'];
        $u->apiKey = $row['api_key'];
        return $u;
    }

    public static function findByApiKey(string $apiKey): ?self
    {
        $st = Db::pdo()->prepare('SELECT id, username, api_key FROM users WHERE api_key = :k');
        $st->execute([':k' => $apiKey]);
        $row = $st->fetch();
        if (!$row) return null;
        $u = new self();
        $u->id = (int)$row['id'];
        $u->username = $row['username'];
        $u->apiKey = $row['api_key'];
        return $u;
    }

    public static function findById(int $id): ?self
    {
        $st = Db::pdo()->prepare('SELECT id, username, api_key FROM users WHERE id = :i');
        $st->execute([':i' => $id]);
        $row = $st->fetch();
        if (!$row) return null;
        $u = new self();
        $u->id = (int)$row['id'];
        $u->username = $row['username'];
        $u->apiKey = $row['api_key'];
        return $u;
    }

    public static function findByUsername(string $username): ?self
    {
        $st = Db::pdo()->prepare('SELECT id, username, api_key FROM users WHERE username = :u');
        $st->execute([':u' => $username]);
        $row = $st->fetch();
        if (!$row) return null;
        $u = new self();
        $u->id = (int)$row['id'];
        $u->username = $row['username'];
        $u->apiKey = $row['api_key'];
        return $u;
    }

    public static function regenerateApiKey(int $id): string
    {
        $newKey = bin2hex(random_bytes(32));
        $st = Db::pdo()->prepare('UPDATE users SET api_key = :k WHERE id = :i');
        $st->execute([':k' => $newKey, ':i' => $id]);
        return $newKey;
    }
}
