<?php
declare(strict_types=1);

namespace Kaspi;

final class Order
{
    public static function create(array $data): int
    {
        $st = Db::pdo()->prepare(
            'INSERT INTO orders (user_id, product_id, kaspi_session_id, customer_name, customer_phone,
                                 product_name, amount, qr_operation_id, qr_token, pay_type, status, raw_status)
             VALUES (:u, :p, :ks, :cn, :cp, :pn, :a, :qop, :qt, :pt, :s, :rs)'
        );
        $st->execute([
            ':u'   => $data['user_id'],
            ':p'   => $data['product_id']       ?? null,
            ':ks'  => $data['kaspi_session_id'] ?? null,
            ':cn'  => $data['customer_name'],
            ':cp'  => $data['customer_phone'],
            ':pn'  => $data['product_name'],
            ':a'   => (float)$data['amount'],
            ':qop' => $data['qr_operation_id']  ?? null,
            ':qt'  => $data['qr_token']         ?? null,
            ':pt'  => $data['pay_type']         ?? 'invoice',
            ':s'   => $data['status']           ?? 'pending',
            ':rs'  => $data['raw_status']       ?? null,
        ]);
        return (int)Db::pdo()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM orders WHERE id = :i');
        $st->execute([':i' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function findForUser(int $userId, int $id): ?array
    {
        $st = Db::pdo()->prepare('SELECT * FROM orders WHERE id = :i AND user_id = :u');
        $st->execute([':i' => $id, ':u' => $userId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function listForUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $st = Db::pdo()->prepare(
            "SELECT id, product_id, customer_name, customer_phone, product_name, amount,
                    qr_operation_id, status, raw_status, created_at, updated_at
             FROM orders WHERE user_id = :u ORDER BY id DESC LIMIT $limit"
        );
        $st->execute([':u' => $userId]);
        return $st->fetchAll();
    }

    public static function updateStatus(int $id, string $status, ?string $rawStatus): void
    {
        $st = Db::pdo()->prepare(
            'UPDATE orders SET status = :s, raw_status = :rs WHERE id = :id'
        );
        $st->execute([':s' => $status, ':rs' => $rawStatus, ':id' => $id]);
    }

    public static function setQr(int $id, string $qrOperationId, ?string $qrToken): void
    {
        $st = Db::pdo()->prepare(
            'UPDATE orders SET qr_operation_id = :q, qr_token = :t WHERE id = :id'
        );
        $st->execute([':q' => $qrOperationId, ':t' => $qrToken, ':id' => $id]);
    }
}
