<?php
declare(strict_types=1);

namespace Kaspi;

final class Helpers
{
    /** RFC-4122 v4 UUID, 8-4-4-4-12 = 32 hex chars, upper-case (matches crypto.randomUUID().toUpperCase()). */
    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return strtoupper(sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            substr($h, 12, 4),
            substr($h, 16, 4),
            substr($h, 20, 12)
        ));
    }

    /**
     * Matches Node `nowISO()` from helpers.js byte-for-byte:
     * UTC date/time (from toISOString) + LOCAL timezone offset suffix without colon.
     * Example (Asia/Almaty): 2025-05-11T04:30:12.345+0500
     */
    public static function nowISO(): string
    {
        $now = microtime(true);
        $sec = (int)$now;
        $ms  = (int)round(($now - $sec) * 1000);
        if ($ms === 1000) { $sec++; $ms = 0; }

        // UTC base (mirrors JS Date.toISOString without 'Z')
        $utc = gmdate('Y-m-d\TH:i:s', $sec) . '.' . str_pad((string)$ms, 3, '0', STR_PAD_LEFT);

        // LOCAL offset suffix
        $offSec = (new \DateTimeImmutable('@' . $sec))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->getOffset();
        $sign = $offSec >= 0 ? '+' : '-';
        $abs  = abs($offSec);
        $hh   = str_pad((string)intdiv($abs, 3600), 2, '0', STR_PAD_LEFT);
        $mm   = str_pad((string)intdiv($abs % 3600, 60), 2, '0', STR_PAD_LEFT);

        return $utc . $sign . $hh . $mm;
    }

    public static function entranceCookie(?string $userToken = null): string
    {
        $d = Device::instance();
        $a = Config::APP;
        $c = "deviceId={$d->deviceId}; installId={$d->installId}; is_mobile_app=true; "
           . "locale={$a['locale']}; ma_bld={$a['build']}; ma_platform_type={$a['platform']}; "
           . "ma_platform_ver={$a['platformVer']}; ma_ver={$a['version']}; "
           . "pk={$d->pk}; pkTag={$d->pkTag}; xs=R:0|E:0|RH:0|N:0";
        if ($userToken) $c .= "; user_token={$userToken}";
        return $c;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:array|string, raw:string, setCookie:string[]}
     */
    public static function httpRequest(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
    {
        self::logHttp(">>> $method $url");
        self::logHttp(">>> Headers: " . json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if ($body !== null) self::logHttp(">>> Body: $body");

        $ch = curl_init($url);
        $hdr = [];
        foreach ($headers as $k => $v) $hdr[] = "$k: $v";

        $setCookies = [];
        $rawHeaders = '';

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_HTTPHEADER      => $hdr,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_ENCODING        => '',
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_HEADERFUNCTION  => function ($c, $h) use (&$setCookies, &$rawHeaders) {
                $rawHeaders .= $h;
                if (stripos($h, 'set-cookie:') === 0) {
                    $setCookies[] = trim(substr($h, 11));
                }
                return strlen($h);
            },
        ]);

        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            self::logHttp("<<< curl error: $err");
            throw new \RuntimeException("curl: $err");
        }

        self::logHttp("<<< $status");
        self::logHttp("<<< Response: " . (string)$raw);

        $parsed = json_decode((string)$raw, true);
        $parsed = is_array($parsed) ? $parsed : (string)$raw;

        return ['status' => $status, 'body' => $parsed, 'raw' => (string)$raw, 'setCookie' => $setCookies];
    }

    private static function logHttp(string $line): void
    {
        $dir = \Kaspi\Config::$dataDir . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents($dir . '/http.log', '[' . date('Y-m-d H:i:s') . "] $line\n", FILE_APPEND | LOCK_EX);
    }

    /** Encode payload for Kaspi: no slash-escaping, no unicode-escaping (matches Node JSON.stringify). */
    public static function jenc(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function extractUserToken(array $setCookies): ?string
    {
        foreach ($setCookies as $c) {
            if (preg_match('/user_token=([^;]+)/', $c, $m)) return $m[1];
        }
        return null;
    }

    /**
     * Build the signed QR-pay headers (used for every qrpay.kaspi.kz request).
     * Session: ['tokenSN'=>..., 'decryptedSecret'=>raw bytes, 'profileId'=>int|string|null]
     * @return array<string,string>
     */
    public static function signedQrPayHeaders(string $url, array $session): array
    {
        $d = Device::instance();
        $a = Config::APP;
        $xsh = 'url,X-Request-ID,X-Device-ID,X-Platform-Ver,X-App-Bld,X-Time,X-Kb-TokenSn,X-App-Ver,X-Kb-TokenSnMac,X-Call,X-PI,X-Install-ID,X-Platform-Type,X-Locale,X-SV';

        $h = [
            'X-Kb-TokenSn'    => $session['tokenSN'] ?? '',
            'X-Kb-TokenSnMac' => Crypto::computeTokenSnMac($session['tokenSN'] ?? null, $session['decryptedSecret'] ?? null),
            'X-PI'            => $session['profileId'] !== null ? (string)$session['profileId'] : '',
            'X-Install-ID'    => $d->installId,
            'X-Device-ID'     => $d->deviceId,
            'X-App-Ver'       => $a['version'],
            'X-App-Bld'       => $a['build'],
            'X-Platform-Type' => $a['platform'],
            'X-Platform-Ver'  => $a['platformVer'],
            'X-Locale'        => $a['locale'],
            'X-Time'          => self::nowISO(),
            'X-Request-ID'    => self::uuid(),
            'X-Call'          => 'notConnected',
            'X-SV'            => '2',
            'X-SH'            => $xsh,
            'User-Agent'      => Config::uaNative(),
            'Accept'          => '*/*',
            'Accept-Language' => 'ru',
            'Accept-Encoding' => 'gzip, deflate, br',
        ];
        $h['X-Sign'] = Crypto::computeXSign($url, $h, $xsh);
        return $h;
    }
}
