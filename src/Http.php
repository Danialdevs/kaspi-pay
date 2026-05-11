<?php
declare(strict_types=1);

namespace Kaspi;

/** Minimal request/response helpers. */
final class Http
{
    public static function jsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $_SERVER[$key] ?? null;
        return $v !== null ? (string)$v : null;
    }

    public static function json(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $msg, int $status = 500): never
    {
        self::json(['error' => $msg], $status);
    }

    /**
     * Resolve user — first by PHP session cookie (web UI), then by X-Api-Key
     * header (external integrations). 401 if neither resolves.
     */
    public static function requireUser(): \Kaspi\User
    {
        $uid = $_SESSION['user_id'] ?? null;
        if ($uid) {
            $u = \Kaspi\User::findById((int)$uid);
            if ($u) return $u;
            // session points to a deleted user — drop it
            unset($_SESSION['user_id']);
        }
        $key = self::header('X-Api-Key');
        if (!$key) self::json(['error' => 'Not authenticated.'], 401);
        $u = \Kaspi\User::findByApiKey($key);
        if (!$u) self::json(['error' => 'Invalid API key.'], 401);
        return $u;
    }

    /**
     * Resolve a kaspi_session. Two auth modes:
     *
     *   1) X-Cashier-Token: <64-hex>             (для внешних интеграций — токен принадлежит кассе)
     *   2) cookie/X-Api-Key  + X-Session-Id      (для web UI магазина — выбор кассира по id)
     */
    public static function requireKaspiSession(): array
    {
        $ks = null;
        $token = self::header('X-Cashier-Token');
        if ($token) {
            $ks = \Kaspi\KaspiSession::findByApiToken($token);
            if (!$ks) self::json(['error' => 'Invalid cashier token.'], 401);
        } else {
            $user = self::requireUser();
            $sid  = (int)(self::header('X-Session-Id') ?? ($_GET['sessionId'] ?? 0));
            if ($sid <= 0) self::json(['error' => 'Missing X-Cashier-Token header (or X-Session-Id for shop auth).'], 400);
            $ks = \Kaspi\KaspiSession::find($user->id, $sid);
            if (!$ks) self::json(['error' => 'Session not found for current shop.'], 404);
        }

        if ($ks['status'] !== 'active' || empty($ks['token_sn']) || empty($ks['vtoken_secret'])) {
            self::json(['error' => 'Cashier not active. Re-authenticate via /api/auth.'], 401);
        }

        try {
            $decrypted = Crypto::decryptSecret($ks['vtoken_secret']);
        } catch (\Throwable) {
            self::json(['error' => 'vtokenSecret broken — re-authenticate this cashier.'], 401);
        }

        return [
            'sessionId'       => (int)$ks['id'],
            'userId'          => (int)$ks['user_id'],
            'tokenSN'         => $ks['token_sn'],
            'profileId'       => $ks['profile_id'],
            'organizationId'  => $ks['organization_id'],
            'orgName'         => $ks['org_name'],
            'phoneNumber'     => $ks['phone_number'],
            'name'            => $ks['name'],
            'decryptedSecret' => $decrypted,
            'vtokenSecret'    => $ks['vtoken_secret'],
        ];
    }
}
