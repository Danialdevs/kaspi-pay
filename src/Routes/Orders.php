<?php
declare(strict_types=1);

namespace Kaspi\Routes;

use Kaspi\Crypto;
use Kaspi\Http;
use Kaspi\KaspiSession;
use Kaspi\Order;

final class Orders
{
    public static function dispatch(string $sub, string $method): never
    {
        $user = Http::requireUser();
        match (true) {
            $sub === 'list'    && $method === 'GET' => self::list($user->id),
            $sub === 'details' && $method === 'GET' => self::details($user->id),
            default => Http::error('Orders: Not Found', 404),
        };
    }

    private static function list(int $uid): never
    {
        Http::json(['ok' => true, 'orders' => Order::listForUser($uid)]);
    }

    /** Возвращает заказ + актуальный статус (если ещё pending — запросить у Каспи и обновить в БД). */
    private static function details(int $uid): never
    {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) Http::error('id required', 400);
        $order = Order::findForUser($uid, $id);
        if (!$order) Http::error('Order not found', 404);

        if ($order['status'] === 'pending' && !empty($order['qr_operation_id']) && !empty($order['kaspi_session_id'])) {
            $ks = KaspiSession::find($uid, (int)$order['kaspi_session_id']);
            if ($ks && $ks['status'] === 'active' && !empty($ks['vtoken_secret'])) {
                try {
                    $session = [
                        'tokenSN'         => $ks['token_sn'],
                        'profileId'       => $ks['profile_id'],
                        'decryptedSecret' => Crypto::decryptSecret($ks['vtoken_secret']),
                    ];
                    $st = Pay::doStatus($session, 'qr', (string)$order['qr_operation_id']);
                    if (!empty($st['ok']) && !empty($st['final'])) {
                        Order::updateStatus($id, (string)$st['status'], (string)($st['rawStatus'] ?? ''));
                        $order['status'] = $st['status'];
                        $order['raw_status'] = $st['rawStatus'];
                    }
                } catch (\Throwable) { /* keep order as-is */ }
            }
        }

        Http::json(['ok' => true, 'order' => $order]);
    }
}
