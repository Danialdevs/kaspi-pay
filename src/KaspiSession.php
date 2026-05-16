<?php
declare(strict_types=1);

namespace Kaspi;

/**
 * Kaspi-cashier session bound to a User. One user may own many.
 */
final class KaspiSession
{
    public static function createPending(int $userId, ?string $name = null): int
    {
        $st = Db::pdo()->prepare(
            'INSERT INTO kaspi_sessions (user_id, name, status) VALUES (:u, :n, "pending")'
        );
        $st->execute([':u' => $userId, ':n' => $name]);
        return (int)Db::pdo()->lastInsertId();
    }

    public static function find(int $userId, int $id): ?array
    {
        $st = Db::pdo()->prepare(
            'SELECT * FROM kaspi_sessions WHERE id = :i AND user_id = :u'
        );
        $st->execute([':i' => $id, ':u' => $userId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function listForUser(int $userId): array
    {
        $st = Db::pdo()->prepare(
            'SELECT id, name, phone_number, profile_id, organization_id, org_name,
                    api_token, status, created_at, updated_at
             FROM kaspi_sessions WHERE user_id = :u ORDER BY id DESC'
        );
        $st->execute([':u' => $userId]);
        return $st->fetchAll();
    }

    public static function activate(int $id, array $data): void
    {
        // Сгенерировать api_token при первой активации (или оставить существующий)
        $st = Db::pdo()->prepare('SELECT api_token FROM kaspi_sessions WHERE id = :id');
        $st->execute([':id' => $id]);
        $existing = $st->fetchColumn();
        $apiToken = $existing ?: bin2hex(random_bytes(32));

        $st = Db::pdo()->prepare(
            'UPDATE kaspi_sessions
             SET token_sn = :ts, vtoken_secret = :vs, profile_id = :pi,
                 organization_id = :oi, org_name = :on, phone_number = :ph,
                 api_token = :at, status = "active"
             WHERE id = :id'
        );
        $st->execute([
            ':ts' => $data['tokenSN']        ?? null,
            ':vs' => $data['vtokenSecret']   ?? null,
            ':pi' => $data['profileId']      ?? null,
            ':oi' => $data['organizationId'] ?? null,
            ':on' => $data['orgName']        ?? null,
            ':ph' => $data['phoneNumber']    ?? null,
            ':at' => $apiToken,
            ':id' => $id,
        ]);
    }

    public static function findByApiToken(string $token): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM kaspi_sessions WHERE api_token = :t');
        $st->execute([':t' => $token]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function firstActiveForUser(int $userId): ?array
    {
        $st = Db::pdo()->prepare(
            'SELECT * FROM kaspi_sessions
             WHERE user_id = :u AND status = "active" AND token_sn IS NOT NULL AND vtoken_secret IS NOT NULL
             ORDER BY id ASC LIMIT 1'
        );
        $st->execute([':u' => $userId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function rotateApiToken(int $userId, int $id): ?string
    {
        $newToken = bin2hex(random_bytes(32));
        $st = Db::pdo()->prepare(
            'UPDATE kaspi_sessions SET api_token = :t WHERE id = :i AND user_id = :u'
        );
        $st->execute([':t' => $newToken, ':i' => $id, ':u' => $userId]);
        return $st->rowCount() > 0 ? $newToken : null;
    }

    public static function updateTokens(int $id, string $tokenSN, string $vtokenSecret): void
    {
        $st = Db::pdo()->prepare(
            'UPDATE kaspi_sessions SET token_sn = :t, vtoken_secret = :v WHERE id = :id'
        );
        $st->execute([':t' => $tokenSN, ':v' => $vtokenSecret, ':id' => $id]);
    }

    public static function rename(int $userId, int $id, string $name): bool
    {
        $st = Db::pdo()->prepare(
            'UPDATE kaspi_sessions SET name = :n WHERE id = :i AND user_id = :u'
        );
        $st->execute([':n' => $name, ':i' => $id, ':u' => $userId]);
        return $st->rowCount() > 0;
    }

    public static function delete(int $userId, int $id): bool
    {
        $st = Db::pdo()->prepare(
            'DELETE FROM kaspi_sessions WHERE id = :i AND user_id = :u'
        );
        $st->execute([':i' => $id, ':u' => $userId]);
        return $st->rowCount() > 0;
    }
}
