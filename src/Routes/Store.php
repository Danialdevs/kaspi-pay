<?php
declare(strict_types=1);

namespace Kaspi\Routes;

use Kaspi\Crypto;
use Kaspi\Http;
use Kaspi\KaspiSession;
use Kaspi\Order;
use Kaspi\Product;
use Kaspi\User;

/**
 * Публичный storefront. URL: /api/store/<username>/{list,checkout,status}
 * Username приходит в `$_GET['shop']` (см. public/index.php).
 */
final class Store
{
    public static function dispatch(string $sub, string $method): never
    {
        $username = (string)($_GET['shop'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.@-]{3,64}$/', $username)) {
            Http::error('Shop not found', 404);
        }
        $user = User::findByUsername($username);
        if (!$user) Http::error('Shop not found', 404);

        match (true) {
            ($sub === '' || $sub === 'list') && $method === 'GET' => self::list($user),
            $sub === 'checkout' && $method === 'POST' => self::checkout($user),
            $sub === 'invoice'  && $method === 'POST' => self::invoiceFallback($user),
            $sub === 'status'   && $method === 'GET'  => self::status($user),
            default => Http::error('Store: Not Found', 404),
        };
    }

    private static function list(User $user): never
    {
        $products = Product::listActiveForUser($user->id);
        Http::json([
            'ok'    => true,
            'shop'  => ['username' => $user->username, 'name' => $user->username],
            'products' => array_map(static fn($p) => [
                'id'          => (int)$p['id'],
                'name'        => $p['name'],
                'description' => $p['description'],
                'price'       => (float)$p['price'],
                'image_url'   => $p['image_url'],
            ], $products),
        ]);
    }

    private static function checkout(User $user): never
    {
        $b = Http::jsonBody();
        $productId    = (int)($b['productId'] ?? 0);
        $customerName = trim((string)($b['customerName']  ?? ''));
        $customerPh   = preg_replace('/\D/', '', (string)($b['customerPhone'] ?? ''));
        if ($productId <= 0)          Http::error('productId required', 400);
        if ($customerName === '')     Http::error('customerName required', 400);
        if (strlen($customerPh) < 10) Http::error('customerPhone must be at least 10 digits', 400);

        $product = Product::findActive($user->id, $productId);
        if (!$product) Http::error('Product not found', 404);

        $ks = KaspiSession::firstActiveForUser($user->id);
        if (!$ks) Http::error('No active cashier — store is offline', 503);

        // Order created upfront so we have an id even if Kaspi call fails
        $orderId = Order::create([
            'user_id'         => $user->id,
            'product_id'      => $productId,
            'kaspi_session_id'=> (int)$ks['id'],
            'customer_name'   => $customerName,
            'customer_phone'  => $customerPh,
            'product_name'    => (string)$product['name'],
            'amount'          => (float)$product['price'],
            'pay_type'        => 'qr',
        ]);

        try {
            $session = self::sessionContext($ks);
            $r = Pay::doCreateQr($session, (float)$product['price']);
            if (empty($r['ok'])) {
                Http::json([
                    'ok'      => false,
                    'orderId' => $orderId,
                    'error'   => $r['error'] ?? 'Kaspi error',
                    'kaspi'   => $r['kaspi'] ?? null,
                ], 502);
            }
            Order::setQr($orderId, (string)$r['id'], $r['qrToken']);
            Http::json([
                'ok'           => true,
                'orderId'      => $orderId,
                'amount'       => (float)$product['price'],
                'productName'  => (string)$product['name'],
                'customerPhone'=> $customerPh,
                'qrToken'      => $r['qrToken'],
                'expireDate'   => $r['expireDate'],
            ]);
        } catch (\Throwable $e) {
            Http::json([
                'ok'      => false,
                'orderId' => $orderId,
                'error'   => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Фолбэк «не получается с QR — выставите счёт на телефон».
     * Берёт существующий заказ, перевыпускает операцию через invoice flow.
     */
    private static function invoiceFallback(User $user): never
    {
        $b = Http::jsonBody();
        $orderId = (int)($b['orderId'] ?? 0);
        if ($orderId <= 0) Http::error('orderId required', 400);
        $order = Order::find($orderId);
        if (!$order || (int)$order['user_id'] !== $user->id) {
            Http::error('Order not found', 404);
        }
        if ($order['status'] !== 'pending') {
            Http::error('Order is not pending', 400);
        }
        if (empty($order['kaspi_session_id'])) {
            Http::error('Order has no cashier bound', 400);
        }
        $ks = KaspiSession::find($user->id, (int)$order['kaspi_session_id']);
        if (!$ks) Http::error('Cashier not found', 404);

        $session = self::sessionContext($ks);
        $r = Pay::doCreateInvoice(
            $session,
            (string)$order['customer_phone'],
            (float)$order['amount'],
            (string)$order['product_name']
        );
        if (empty($r['ok'])) {
            Http::json([
                'ok'      => false,
                'orderId' => $orderId,
                'error'   => $r['error'] ?? 'Kaspi error',
                'kaspi'   => $r['kaspi'] ?? null,
            ], 502);
        }
        // Switch order to invoice mode + new operationId
        Order::setQr($orderId, (string)$r['id'], null);
        Order::setPayType($orderId, 'invoice');
        Http::json([
            'ok'           => true,
            'orderId'      => $orderId,
            'amount'       => (float)$order['amount'],
            'productName'  => (string)$order['product_name'],
            'customerPhone'=> (string)$order['customer_phone'],
        ]);
    }

    private static function sessionContext(array $ks): array
    {
        return [
            'tokenSN'         => $ks['token_sn'],
            'profileId'       => $ks['profile_id'],
            'decryptedSecret' => Crypto::decryptSecret($ks['vtoken_secret']),
        ];
    }

    private static function status(User $user): never
    {
        $orderId = (int)($_GET['orderId'] ?? 0);
        if ($orderId <= 0) Http::error('orderId required', 400);
        $order = Order::find($orderId);
        if (!$order || (int)$order['user_id'] !== $user->id) {
            Http::error('Order not found', 404);
        }

        // If still pending — poll Kaspi
        if ($order['status'] === 'pending' && !empty($order['qr_operation_id']) && !empty($order['kaspi_session_id'])) {
            $ks = KaspiSession::find($user->id, (int)$order['kaspi_session_id']);
            if ($ks && $ks['status'] === 'active' && !empty($ks['vtoken_secret'])) {
                try {
                    $session = [
                        'tokenSN'         => $ks['token_sn'],
                        'profileId'       => $ks['profile_id'],
                        'decryptedSecret' => Crypto::decryptSecret($ks['vtoken_secret']),
                    ];
                    $payType = $order['pay_type'] ?? 'invoice';
                    $st = Pay::doStatus($session, $payType, (string)$order['qr_operation_id']);
                    if (!empty($st['ok'])) {
                        if (!empty($st['final']) || ($st['status'] !== 'pending')) {
                            Order::updateStatus($orderId, (string)$st['status'], (string)($st['rawStatus'] ?? ''));
                            $order['status'] = $st['status'];
                            $order['raw_status'] = $st['rawStatus'];
                        }
                    }
                } catch (\Throwable) { /* keep order as-is */ }
            }
        }

        Http::json([
            'ok'         => true,
            'orderId'    => (int)$order['id'],
            'status'     => $order['status'],
            'rawStatus'  => $order['raw_status'],
            'paid'       => $order['status'] === 'success',
            'final'      => in_array($order['status'], ['success', 'failed', 'expired'], true),
            'amount'     => (float)$order['amount'],
            'productName'=> (string)$order['product_name'],
            'qrToken'    => $order['qr_token'],
        ]);
    }
}
