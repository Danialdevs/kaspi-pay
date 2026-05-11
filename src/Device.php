<?php
declare(strict_types=1);

namespace Kaspi;

final class Device
{
    public string $deviceId;
    public string $installId;
    public string $pinHash;

    /** base64 of full SPKI DER */
    public string $x509;
    /** base64 of uncompressed EC point (last 65 bytes of SPKI DER) */
    public string $pk;
    /** md5 hex of pk */
    public string $pkTag;

    /** OpenSSL key resource (ECDSA P-256 keypair) */
    public mixed $signingKey;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= self::load();
    }

    private static function load(): self
    {
        $self = new self();
        $self->loadOrCreateEcdsa();
        $self->loadOrCreateDevice();
        return $self;
    }

    private function loadOrCreateEcdsa(): void
    {
        $row = Db::kvGet('keypair');
        if ($row) {
            $saved = json_decode($row, true);
            $pem = self::derToPemPrivate(base64_decode($saved['privateKey']));
            $this->signingKey = openssl_pkey_get_private($pem);
        } else {
            $this->signingKey = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_EC,
                'curve_name'       => 'prime256v1',
            ]);
            openssl_pkey_export($this->signingKey, $privPem);
            $details = openssl_pkey_get_details($this->signingKey);
            $privDer = self::pemPrivateToDer($privPem);
            $pubDer  = self::pemPublicToDer($details['key']);
            Db::kvSet('keypair', json_encode([
                'privateKey' => base64_encode($privDer),
                'publicKey'  => base64_encode($pubDer),
            ]));
        }
        $details = openssl_pkey_get_details($this->signingKey);
        $pubDer  = self::pemPublicToDer($details['key']);

        $this->x509  = base64_encode($pubDer);
        // last 65 bytes — uncompressed EC point (0x04 || X || Y)
        $point       = substr($pubDer, -65);
        $this->pk    = base64_encode($point);
        $this->pkTag = md5($this->pk);
    }

    private function loadOrCreateDevice(): void
    {
        $row = Db::kvGet('device');
        if ($row) {
            $saved = json_decode($row, true);
            $this->deviceId  = $saved['deviceId'];
            $this->installId = $saved['installId'];
            $this->pinHash   = $saved['pinHash'];
        } else {
            $this->deviceId  = strtoupper(Helpers::uuid());
            $this->installId = strtoupper(Helpers::uuid());
            $this->pinHash   = md5(random_bytes(16));
            Db::kvSet('device', json_encode([
                'deviceId'  => $this->deviceId,
                'installId' => $this->installId,
                'pinHash'   => $this->pinHash,
            ]));
        }
    }

    public static function pemPrivateToDer(string $pem): string
    {
        $clean = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem);
        return base64_decode($clean);
    }

    public static function pemPublicToDer(string $pem): string
    {
        $clean = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem);
        return base64_decode($clean);
    }

    public static function derToPemPrivate(string $der): string
    {
        $b = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN PRIVATE KEY-----\n{$b}-----END PRIVATE KEY-----\n";
    }

    public static function derToPemPublic(string $der): string
    {
        $b = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN PUBLIC KEY-----\n{$b}-----END PUBLIC KEY-----\n";
    }
}
