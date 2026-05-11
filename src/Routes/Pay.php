<?php
declare(strict_types=1);

namespace Kaspi\Routes;

use Kaspi\Config;
use Kaspi\Helpers;
use Kaspi\Http;

/**
 * Унифицированный API для создания платежей (QR + invoice по номеру)
 * и проверки статуса. Все ответы в одном формате независимо от типа.
 *
 *   POST /api/pay/qr        { amount, latitude?, longitude? }                → создаёт QR
 *   POST /api/pay/invoice   { phoneNumber, amount, comment? }                → счёт по номеру
 *   GET  /api/pay/status?id=<opId>&type=qr|invoice                           → статус
 *   POST /api/pay/cancel    { id, type }                                     → отменить invoice
 *
 * Auth headers (как и в /api/qr и /api/invoice):
 *   X-Token-SN, X-Vtoken-Secret, X-Profile-Id
 */
final class Pay
{
    private const FINAL_OK     = ['Processed'];
    private const FINAL_FAILED = [
        'CancelledByUser', 'NotConfirmedByUser', 'CancelledByExternalSource',
        'ProcessingFailed', 'Rejected', 'InsufficientFunds', 'InsufficientFundsError',
        'Error', 'RemotePaymentCanceled', 'RemotePaymentRejected',
    ];
    private const FINAL_EXPIRED = ['QrTokenDiscarded', 'Expired'];

    public static function dispatch(string $sub, string $method): never
    {
        $session = Http::requireKaspiSession();
        match (true) {
            $sub === 'qr'      && $method === 'POST' => self::createQr($session),
            $sub === 'invoice' && $method === 'POST' => self::createInvoice($session),
            $sub === 'status'  && $method === 'GET'  => self::status($session),
            $sub === 'cancel'  && $method === 'POST' => self::cancelInvoice($session),
            $sub === 'refund'  && $method === 'POST' => self::refund($session),
            $sub === 'history' && $method === 'GET'  => self::history($session),
            default => Http::error('Pay: Not Found', 404),
        };
    }

    // ─── создать QR ───
    private static function createQr(array $session): never
    {
        $b = Http::jsonBody();
        $amount = $b['amount'] ?? null;
        if (!$amount) Http::error('amount required', 400);

        $url = Config::KASPI_QRPAY_URL . '/v01/qr-token/create';
        $headers = Helpers::signedQrPayHeaders($url, $session) + ['Content-Type' => 'application/json'];
        $payload = [
            'PaymentAmount'   => (float)$amount,
            'DeviceInterface' => 'Pos',
        ];
        // Lat/Long — отправляем только если клиент явно передал.
        if (isset($b['latitude']))  $payload['Latitude']  = (float)$b['latitude'];
        if (isset($b['longitude'])) $payload['Longitude'] = (float)$b['longitude'];
        $body = Helpers::jenc($payload);

        $resp = Helpers::httpRequest($url, 'POST', $body, $headers);
        $j = is_array($resp['body']) ? $resp['body'] : [];
        $d = $j['Data'] ?? null;

        if (!$d || empty($d['QrOperationId'])) {
            Http::json([
                'ok'    => false,
                'error' => $j['Message'] ?? $j['StatusDesc'] ?? 'Kaspi error',
                'kaspi' => $j,
            ], 502);
        }

        $qrToken = isset($d['QrToken'])
            ? str_replace('https://qr.kaspi.kz/', 'https://pay.kaspi.kz/pay/', (string)$d['QrToken'])
            : null;

        Http::json([
            'ok'         => true,
            'type'       => 'qr',
            'id'         => (string)$d['QrOperationId'],
            'amount'     => $d['Amount']     ?? (float)$amount,
            'qrToken'    => $qrToken,
            'expireDate' => $d['ExpireDate'] ?? null,
            'receiptUrl' => $d['ReceiptUrl'] ?? null,
            'status'     => 'pending',
            'kaspi'      => $j,
        ]);
    }

    // ─── создать счёт по номеру ───
    private static function createInvoice(array $session): never
    {
        $b = Http::jsonBody();
        $phone  = $b['phoneNumber'] ?? null;
        $amount = $b['amount']      ?? null;
        if (!$phone || !$amount) Http::error('phoneNumber and amount required', 400);

        $url = Config::KASPI_QRPAY_URL . '/v01/remote/create';
        $headers = Helpers::signedQrPayHeaders($url, $session) + ['Content-Type' => 'application/json'];
        $body = Helpers::jenc([
            'PhoneNumber' => (string)$phone,
            'Amount'      => (float)$amount,
            'Comment'     => (string)($b['comment'] ?? ''),
        ]);

        $resp = Helpers::httpRequest($url, 'POST', $body, $headers);
        $j = is_array($resp['body']) ? $resp['body'] : [];
        $d = $j['Data'] ?? null;

        if (!$d || empty($d['Id'])) {
            Http::json([
                'ok'    => false,
                'error' => $j['Message'] ?? $j['StatusDesc'] ?? 'Kaspi error',
                'kaspi' => $j,
            ], 502);
        }

        Http::json([
            'ok'           => true,
            'type'         => 'invoice',
            'id'           => (string)$d['Id'],
            'amount'       => $d['Amount']       ?? (float)$amount,
            'phoneNumber'  => $d['ClientMobile'] ?? $phone,
            'orderNumber'  => $d['OrderNumber']  ?? null,
            'receiptUrl'   => $d['ReceiptUrl']   ?? null,
            'status'       => self::mapStatus('invoice', $d['Status'] ?? 'RemotePaymentCreated'),
            'rawStatus'    => $d['Status'] ?? null,
            'kaspi'        => $j,
        ]);
    }

    // ─── проверить статус (qr или invoice) ───
    private static function status(array $session): never
    {
        $id   = $_GET['id']   ?? null;
        $type = $_GET['type'] ?? 'qr';
        if (!$id) Http::error('id required', 400);
        if (!in_array($type, ['qr', 'invoice'], true)) Http::error('type must be "qr" or "invoice"', 400);

        if ($type === 'qr') {
            $url = Config::KASPI_QRPAY_URL . '/v02/kaspi-qr/status?qrOperationId=' . urlencode((string)$id);
        } else {
            $url = Config::KASPI_QRPAY_URL . '/v02/remote/details?operationId=' . urlencode((string)$id);
        }

        $resp = Helpers::httpRequest($url, 'GET', null, Helpers::signedQrPayHeaders($url, $session));
        $j = is_array($resp['body']) ? $resp['body'] : [];
        $d = $j['Data'] ?? null;

        if (!$d) {
            Http::json([
                'ok'    => false,
                'error' => $j['Message'] ?? 'Kaspi error',
                'kaspi' => $j,
            ], 502);
        }

        $raw = $d['Status'] ?? '';
        Http::json([
            'ok'          => true,
            'type'        => $type,
            'id'          => (string)$id,
            'status'      => self::mapStatus($type, $raw),
            'rawStatus'   => $raw,
            'statusDesc'  => $d['StatusDesc']  ?? null,
            'amount'      => $d['Amount']      ?? null,
            'paid'        => in_array($raw, self::FINAL_OK, true),
            'final'       => self::isFinal($raw),
            'receiptUrl'  => $d['ReceiptUrl']  ?? null,
            'orderNumber' => $d['OrderNumber'] ?? null,
            'kaspi'       => $j,
        ]);
    }

    // ─── отменить invoice ───
    private static function cancelInvoice(array $session): never
    {
        $b = Http::jsonBody();
        $id = $b['id'] ?? null;
        $type = $b['type'] ?? 'invoice';
        if (!$id) Http::error('id required', 400);
        if ($type !== 'invoice') Http::error('cancel поддерживается только для type=invoice (QR отменить нельзя)', 400);

        $url = Config::KASPI_QRPAY_URL . '/v01/remote/cancel';
        $headers = Helpers::signedQrPayHeaders($url, $session) + ['Content-Type' => 'application/json'];
        $resp = Helpers::httpRequest($url, 'POST', Helpers::jenc(['qrOperationId' => (int)$id]), $headers);
        $j = is_array($resp['body']) ? $resp['body'] : [];

        Http::json([
            'ok'    => ($j['StatusCode'] ?? 0) === 0,
            'type'  => 'invoice',
            'id'    => (string)$id,
            'kaspi' => $j,
        ]);
    }

    // ─── возврат по QR-операции ───
    private static function refund(array $session): never
    {
        $b = Http::jsonBody();
        $id = $b['id'] ?? null;
        $amount = $b['amount'] ?? null;
        if (!$id)     Http::error('id required (qrOperationId)', 400);
        if (!$amount) Http::error('amount required', 400);

        $url = Config::KASPI_QRPAY_URL . '/v01/kaspi-qr/history-pos-return';
        $headers = Helpers::signedQrPayHeaders($url, $session) + ['Content-Type' => 'application/json'];
        $body = Helpers::jenc([
            'ReturnAmount'    => (float)$amount,
            'QrOperationId'   => (int)$id,
            'DeviceInterface' => 'Pos',
        ]);
        $resp = Helpers::httpRequest($url, 'POST', $body, $headers);
        $j = is_array($resp['body']) ? $resp['body'] : [];

        if (($j['StatusCode'] ?? null) !== 0 && empty($j['Data'])) {
            Http::json([
                'ok'    => false,
                'error' => $j['Message'] ?? $j['StatusDesc'] ?? 'Kaspi error',
                'kaspi' => $j,
            ], 502);
        }

        Http::json([
            'ok'     => true,
            'type'   => 'refund',
            'id'     => (string)$id,
            'amount' => (float)$amount,
            'data'   => $j['Data'] ?? null,
            'kaspi'  => $j,
        ]);
    }

    // ─── история операций ───
    private static function history(array $session): never
    {
        $endDate = $_GET['endDate'] ?? date('Y-m-d', strtotime('+1 day'));
        $lastDate = $_GET['lastDate'] ?? '';
        $period = (int)($_GET['period'] ?? 0);

        $url = Config::KASPI_QRPAY_URL . '/v02/history/operations';
        $headers = Helpers::signedQrPayHeaders($url, $session) + ['Content-Type' => 'application/json'];
        $body = Helpers::jenc([
            'EndDate'             => $endDate,
            'LastTransactionDate' => $lastDate,
            'StatementPeriodCode' => $period,
        ]);
        $resp = Helpers::httpRequest($url, 'POST', $body, $headers);
        $j = is_array($resp['body']) ? $resp['body'] : [];

        if (($j['StatusCode'] ?? null) !== 0 || empty($j['Data'])) {
            Http::json([
                'ok'    => false,
                'error' => $j['Message'] ?? 'Kaspi error',
                'kaspi' => $j,
            ], 502);
        }

        $data = $j['Data'];
        $items = [];
        foreach (($data['Items'] ?? $data['Operations'] ?? []) as $row) {
            $raw = $row['Status'] ?? '';
            $type = ($row['OperationMethod'] ?? null) === 1 ? 'invoice' : 'qr';
            $items[] = [
                'id'         => (string)($row['Id'] ?? $row['OperationId'] ?? ''),
                'type'       => $type,
                'amount'     => $row['Amount'] ?? null,
                'date'       => $row['Date'] ?? $row['OperationDate'] ?? null,
                'status'     => self::mapStatus($type, $raw),
                'rawStatus'  => $raw,
                'statusDesc' => $row['StatusDesc'] ?? null,
                'clientName' => $row['ClientName'] ?? null,
                'phoneNumber'=> $row['ClientMobile'] ?? null,
                'final'      => self::isFinal($raw),
                'raw'        => $row,
            ];
        }

        Http::json([
            'ok'       => true,
            'items'    => $items,
            'lastDate' => $data['LastTransactionDate'] ?? null,
            'hasMore'  => !empty($data['HasMore']),
        ]);
    }

    // ─── единый маппинг статусов Каспи → наш формат ───
    private static function mapStatus(string $type, string $raw): string
    {
        if (in_array($raw, self::FINAL_OK, true))      return 'success';
        if (in_array($raw, self::FINAL_FAILED, true))  return 'failed';
        if (in_array($raw, self::FINAL_EXPIRED, true)) return 'expired';
        // intermediate
        if (in_array($raw, ['QrTokenCreated', 'Wait', 'RemotePaymentCreated'], true)) return 'pending';
        return strtolower($raw ?: 'unknown');
    }

    private static function isFinal(string $raw): bool
    {
        return in_array($raw, self::FINAL_OK, true)
            || in_array($raw, self::FINAL_FAILED, true)
            || in_array($raw, self::FINAL_EXPIRED, true);
    }
}
