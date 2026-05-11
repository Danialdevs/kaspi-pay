<?php
declare(strict_types=1);

namespace Kaspi;

/**
 * Crypto: ECDH (P-256), ECDSA sign, OCRA-1 TOTP, AES-256-GCM.
 * Direct port of Node `src/crypto.js`.
 */
final class Crypto
{
    private const VTOKEN_SUITE = 'OCRA-1:HOTP-SHA256-6:QH64-T1M';

    /** ── AES-256-GCM for vtokenSecret ── */

    private static function key(): string
    {
        $hex = Env::get('TOKEN_SECRET_KEY');
        if (!$hex) {
            fwrite(STDERR, "FATAL: TOKEN_SECRET_KEY env not set (generate: openssl rand -hex 32)\n");
            exit(1);
        }
        $bin = @hex2bin($hex);
        if ($bin === false || strlen($bin) !== 32) {
            fwrite(STDERR, "FATAL: TOKEN_SECRET_KEY must be 64 hex chars (32 bytes)\n");
            exit(1);
        }
        return $bin;
    }

    public static function encryptSecret(string $secret): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $enc = openssl_encrypt($secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($enc === false) throw new \RuntimeException('AES-GCM encrypt failed');
        return base64_encode($iv . $tag . $enc);
    }

    public static function decryptSecret(string $tokenB64): string
    {
        $buf = base64_decode($tokenB64, true);
        if ($buf === false || strlen($buf) < 28) throw new \RuntimeException('bad token');
        $iv  = substr($buf, 0, 12);
        $tag = substr($buf, 12, 16);
        $enc = substr($buf, 28);
        $dec = openssl_decrypt($enc, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($dec === false) throw new \RuntimeException('AES-GCM decrypt failed');
        return $dec;
    }

    /** ── ECDH (prime256v1) ── */

    public static function generateECDH(): string
    {
        $kp = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        openssl_pkey_export($kp, $privPem);
        $det = openssl_pkey_get_details($kp);
        $pubPem = $det['key'];

        $privDer = Device::pemPrivateToDer($privPem);
        $pubDer  = Device::pemPublicToDer($pubPem);

        Db::kvSet('ecdh_keypair', json_encode([
            'privateKey' => base64_encode($privDer),
            'publicKey'  => base64_encode($pubDer),
        ]));

        return base64_encode($pubDer);
    }

    /**
     * Derive shared secret using saved ECDH private key + server public X509.
     * Uses openssl CLI (`openssl pkeyutl -derive`) — PHP openssl ext has no native ECDH.
     */
    public static function completeECDHWithSaved(string $serverX509B64): string
    {
        $row = Db::kvGet('ecdh_keypair');
        if (!$row) throw new \RuntimeException('No saved ECDH keypair');
        $saved = json_decode($row, true);
        return self::ecdhDerive(base64_decode($saved['privateKey']), base64_decode($serverX509B64));
    }

    public static function completeECDH(string $serverX509B64): string
    {
        return self::completeECDHWithSaved($serverX509B64);
    }

    private static function ecdhDerive(string $privDer, string $pubDer): string
    {
        $priv = tempnam(sys_get_temp_dir(), 'ekp');
        $pub  = tempnam(sys_get_temp_dir(), 'ekP');
        $out  = tempnam(sys_get_temp_dir(), 'eko');
        try {
            file_put_contents($priv, Device::derToPemPrivate($privDer));
            file_put_contents($pub,  Device::derToPemPublic($pubDer));
            $cmd = 'openssl pkeyutl -derive -inkey ' . escapeshellarg($priv)
                 . ' -peerkey ' . escapeshellarg($pub)
                 . ' -out ' . escapeshellarg($out) . ' 2>&1';
            exec($cmd, $o, $rc);
            if ($rc !== 0) throw new \RuntimeException('ECDH derive failed: ' . implode("\n", $o));
            $secret = (string)file_get_contents($out);
            if (strlen($secret) !== 32) throw new \RuntimeException('ECDH bad length ' . strlen($secret));
            return $secret;
        } finally {
            @unlink($priv); @unlink($pub); @unlink($out);
        }
    }

    /** ── OCRA-1 TOTP (matches Kaspi vtoken) ── */

    public static function computeTokenSnMac(?string $tokenSN, ?string $secret): string
    {
        if (!$secret) return '000000';

        // timeStep = floor(ms / 30000), as 16-hex chars
        $ms = (int)(microtime(true) * 1000);
        $timeStep = intdiv($ms, 30000);
        $timeHex  = str_pad(dechex($timeStep), 16, '0', STR_PAD_LEFT);

        // Q = ascii(tokenSN || '00000000') as hex, take first 64 chars, pad-right to 256 with '0'
        $qSrc = bin2hex((string)($tokenSN ?: '00000000'));
        $qSrc = substr($qSrc, 0, 64);
        $qPadded = str_pad($qSrc, 256, '0', STR_PAD_RIGHT);

        $data = self::VTOKEN_SUITE
              . "\x00"
              . self::hexToBin($qPadded)
              . self::hexToBin($timeHex);

        $h = hash_hmac('sha256', $data, $secret, true);

        // RFC 4226 dynamic truncation
        $off = ord($h[strlen($h) - 1]) & 0x0f;
        $bin = ((ord($h[$off]) & 0x7f) << 24)
             | ((ord($h[$off + 1]) & 0xff) << 16)
             | ((ord($h[$off + 2]) & 0xff) << 8)
             |  (ord($h[$off + 3]) & 0xff);

        return str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function hexToBin(string $hex): string
    {
        $b = @hex2bin($hex);
        return $b === false ? '' : $b;
    }

    /** ── ECDSA sign (SHA-256, DER signature, base64) ── */

    public static function ecSign(string $data): string
    {
        $sig = '';
        $ok = openssl_sign($data, $sig, Device::instance()->signingKey, OPENSSL_ALGO_SHA256);
        if (!$ok) throw new \RuntimeException('ECDSA sign failed');
        return base64_encode($sig);
    }

    public static function signDataPayload(string $dataB64): string
    {
        return self::ecSign($dataB64);
    }

    public static function computeXSU(string $url): string
    {
        return md5(strtolower($url));
    }

    /**
     * Build X-Sign for a request, using the comma-separated X-SH header list.
     * 'url' → pathname + search; otherwise headers[name] || ''.
     */
    public static function computeXSign(string $url, array $headers, string $xshList): string
    {
        $parts = [];
        foreach (explode(',', $xshList) as $name) {
            $name = trim($name);
            if ($name === 'url') {
                $p = parse_url($url);
                $path = ($p['path'] ?? $url) . (isset($p['query']) ? '?' . $p['query'] : '');
                $parts[] = $path;
            } else {
                $parts[] = (string)($headers[$name] ?? '');
            }
        }
        return self::ecSign(implode('', $parts));
    }
}
