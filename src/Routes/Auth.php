<?php
declare(strict_types=1);

namespace Kaspi\Routes;

use Kaspi\Config;
use Kaspi\Crypto;
use Kaspi\Device;
use Kaspi\Helpers;
use Kaspi\Http;
use Kaspi\Session;

final class Auth
{
    public static function dispatch(string $sub, string $method): never
    {
        match (true) {
            $sub === 'init'       && $method === 'POST' => self::init(),
            $sub === 'send-phone' && $method === 'POST' => self::sendPhone(),
            $sub === 'verify-otp' && $method === 'POST' => self::verifyOtp(),
            $sub === 'refresh'    && $method === 'POST' => self::refresh(),
            $sub === 'session'    && $method === 'POST' => Http::json(['authenticated' => (bool)(Http::jsonBody()['tokenSN'] ?? false), 'tokenSN' => Http::jsonBody()['tokenSN'] ?? null]),
            $sub === 'logout'     && $method === 'POST' => Http::json(['success' => true]),
            default => Http::error('Auth: Not Found', 404),
        };
    }

    /** Step 1 — init entrance (get processId, owned by current user) */
    private static function init(): never
    {
        $user = Http::requireUser();
        $name = trim((string)(Http::jsonBody()['name'] ?? ''));
        $kaspiSessionId = \Kaspi\KaspiSession::createPending($user->id, $name !== '' ? $name : null);

        $d = Device::instance();
        $a = Config::APP;
        $session = Session::empty();
        $session['_userId']         = $user->id;
        $session['_kaspiSessionId'] = $kaspiSessionId;

        $url = Config::KASPI_ENTRANCE_URL . '/api/v1/entrance/step';
        $referer = Config::KASPI_ENTRANCE_URL
            . "/process/entrance/?auth=2&appBuild={$a['build']}&appVersion={$a['version']}"
            . "&platformVersion={$a['platformVer']}&platformType=IOS&deviceBrand={$a['brand']}"
            . "&deviceModel={$a['model']}&deviceId={$d->deviceId}&installId={$d->installId}"
            . "&frontCameraAvailable=true&sf=registration&pc=KPEntrance&noPass=0";

        $headers = Config::entranceHeadersBase() + ['Referer' => $referer, 'Cookie' => Helpers::entranceCookie()];
        $body = Helpers::jenc([
            'data' => new \stdClass(),
            'Data' => [
                'auth'                  => '2',
                'appBuild'              => $a['build'],
                'appVersion'            => $a['version'],
                'platformVersion'       => $a['platformVer'],
                'platformType'          => 'IOS',
                'deviceBrand'           => $a['brand'],
                'deviceModel'           => $a['model'],
                'deviceId'              => $d->deviceId,
                'installId'             => $d->installId,
                'frontCameraAvailable'  => 'true',
                'sf'                    => 'registration',
                'pc'                    => 'KPEntrance',
                'noPass'                => '0',
            ],
            'actType' => 'Success',
        ]);

        $resp = Helpers::httpRequest($url, 'POST', $body, $headers);
        $ut = Helpers::extractUserToken($resp['setCookie']);
        if ($ut) $session['userToken'] = $ut;

        $bodyJson = is_array($resp['body']) ? $resp['body'] : [];
        $pId = $bodyJson['meta']['pId'] ?? null;
        if ($pId) {
            $session['processId'] = $pId;
            Session::putAuth($pId, $session);
        }

        Http::json([
            'success'   => (bool)$pId,
            'processId' => $pId,
            'sessionId' => $kaspiSessionId,
            'view'      => $bodyJson['view']['code'] ?? null,
            'body'      => $bodyJson,
        ]);
    }

    /** Step 2 — send phone number (triggers SMS) */
    private static function sendPhone(): never
    {
        $user = Http::requireUser();
        $b = Http::jsonBody();
        $phone     = $b['phoneNumber'] ?? null;
        $processId = $b['processId']   ?? null;
        if (!$phone)     Http::error('phoneNumber required (e.g. 7XXXXXXXXX)', 400);
        if (!$processId) Http::error('processId required (from /api/auth/init)', 400);

        $session = Session::getAuth($processId);
        if (!$session) Http::error('Unknown processId. Call /api/auth/init first', 400);
        if (($session['_userId'] ?? null) !== $user->id) Http::error('processId not owned by current user', 403);

        $session['phoneNumber'] = $phone;

        $url = Config::KASPI_ENTRANCE_URL . '/api/v1/entrance/step';
        $referer = Config::KASPI_ENTRANCE_URL
            . "/process/universal-enter-phone-number?pId={$processId}&firstPage=KPUniversalEnterPhoneNumber";
        $headers = Config::entranceHeadersBase() + [
            'Referer' => $referer,
            'Cookie'  => Helpers::entranceCookie($session['userToken'] ?? null),
        ];
        $body = Helpers::jenc([
            'meta'    => ['pId' => $processId, 'sn' => 'EnterPhoneNumber'],
            'data'    => ['phoneNumber' => $phone],
            'actType' => 'Success',
        ]);

        $resp = Helpers::httpRequest($url, 'POST', $body, $headers);
        $ut = Helpers::extractUserToken($resp['setCookie']);
        if ($ut) $session['userToken'] = $ut;

        $bodyJson = is_array($resp['body']) ? $resp['body'] : [];
        $smsSent = ($bodyJson['view']['code'] ?? null) === 'EnterOtp';

        Session::putAuth($processId, $session);

        Http::json([
            'success'   => $smsSent,
            'processId' => $processId,
            'desc'      => $bodyJson['data']['desc'] ?? null,
            'view'      => $bodyJson['view']['code'] ?? null,
            'body'      => $bodyJson,
        ]);
    }

    /** Step 3 — submit OTP, then finalize */
    private static function verifyOtp(): never
    {
        $user = Http::requireUser();
        $b = Http::jsonBody();
        $otp       = $b['otp']       ?? null;
        $processId = $b['processId'] ?? null;
        if (!$otp)       Http::error('otp required', 400);
        if (!$processId) Http::error('processId required', 400);

        $session = Session::getAuth($processId);
        if (!$session) Http::error('Unknown processId', 400);
        if (($session['_userId'] ?? null) !== $user->id) Http::error('processId not owned by current user', 403);

        $url = Config::KASPI_ENTRANCE_URL . '/api/v1/entrance/step';
        $referer = Config::KASPI_ENTRANCE_URL
            . "/process/universal-enter-phone-number?pId={$processId}&firstPage=KPUniversalEnterPhoneNumber";
        $headers = Config::entranceHeadersBase() + [
            'Referer' => $referer,
            'Cookie'  => Helpers::entranceCookie($session['userToken'] ?? null),
        ];
        $body = Helpers::jenc([
            'meta'    => ['pId' => $processId, 'sn' => 'ViewEnterOtp'],
            'data'    => ['userOtp' => $otp, 'inputType' => 'auto'],
            'actType' => 'Success',
        ]);

        $resp = Helpers::httpRequest($url, 'POST', $body, $headers);
        $ut = Helpers::extractUserToken($resp['setCookie']);
        if ($ut) $session['userToken'] = $ut;

        $bodyJson = is_array($resp['body']) ? $resp['body'] : [];

        $shouldFinish = (($bodyJson['data']['type'] ?? null) === 'kpDeviceRegistration')
                     || (($bodyJson['view']['code'] ?? null) === 'KPMobileCall');

        if ($shouldFinish) {
            try {
                $finish = self::doFinish($session);
            } catch (\Throwable $e) {
                Session::putAuth($processId, $session);
                Http::json([
                    'success'   => false,
                    'processId' => $processId,
                    'step'      => 'finish_failed',
                    'error'     => $e->getMessage(),
                    'otpBody'   => $bodyJson,
                ], 502);
            }
            // Persist tokens to DB session row — secrets never leave the server.
            $kaspiSessionId = (int)($session['_kaspiSessionId'] ?? 0);
            if ($kaspiSessionId > 0 && !empty($finish['tokenSN']) && !empty($finish['vtokenSecret'])) {
                \Kaspi\KaspiSession::activate($kaspiSessionId, [
                    'tokenSN'        => $finish['tokenSN'],
                    'vtokenSecret'   => $finish['vtokenSecret'],
                    'profileId'      => $finish['profileId']      ?? null,
                    'organizationId' => $finish['organizationId'] ?? null,
                    'orgName'        => $finish['orgName']        ?? null,
                    'phoneNumber'    => $finish['phone']          ?? null,
                ]);
            }
            Session::delAuth($processId);
            Http::json([
                'success'        => true,
                'step'           => 'finished',
                'sessionId'      => $kaspiSessionId,
                'profileId'      => $finish['profileId']      ?? null,
                'organizationId' => $finish['organizationId'] ?? null,
                'orgName'        => $finish['orgName']        ?? null,
                'phoneNumber'    => $finish['phone']          ?? null,
            ]);
        } else {
            Session::putAuth($processId, $session);
            Http::json([
                'success'   => false,
                'processId' => $processId,
                'step'      => 'otp_response',
                'body'      => $bodyJson,
            ]);
        }
    }

    /** Shared finalize: kpentrance/finish + org-context-otp */
    private static function doFinish(array &$session): array
    {
        $d = Device::instance();
        $a = Config::APP;

        $ecdhX509 = Crypto::generateECDH();

        $signedDataObj = [
            'installId' => $d->installId,
            'time'      => Helpers::nowISO(),
            'auth'      => [['value' => '', 'type' => 'pincode']],
            'userIdHash'=> '',
        ];
        $signedDataB64 = base64_encode(Helpers::jenc($signedDataObj));

        $finishUrl = Config::KASPI_ENTRANCE_URL . '/api/v1/kpentrance/finish';
        $finishHeaders = [
            'Content-Type'    => 'application/json',
            'Accept'          => '*/*',
            'Accept-Language' => 'ru',
            'Accept-Encoding' => 'gzip, deflate, br',
            'User-Agent'      => Config::uaNative(),
            'X-Time'          => Helpers::nowISO(),
            'X-Call'          => 'notConnected',
            'X-Platform-Type' => $a['platform'],
            'X-PkTag'         => $d->pkTag,
            'X-SU'            => Crypto::computeXSU($finishUrl),
            'X-Net-Type'      => 'WIFI/ETHERNET',
            'X-Emulator'      => '0',
            'X-Locale'        => $a['locale'],
            'X-SV'            => '2',
            'X-Request-ID'    => Helpers::uuid(),
            'X-Time-Zone'     => 'GMT+05:00',
            'X-SH'            => 'url,X-Time-Zone,X-Request-ID,X-Net-Type,X-Emulator,X-Call,X-Platform-Type,X-Locale,X-Time,X-SV',
        ];
        $finishHeaders['X-Sign'] = Crypto::computeXSign($finishUrl, $finishHeaders, $finishHeaders['X-SH']);

        $finishBody = Helpers::jenc([
            'signed'    => ['sign' => Crypto::signDataPayload($signedDataB64), 'data' => $signedDataB64],
            'guard'     => ['pinHash' => $d->pinHash, 'x509' => $ecdhX509],
            'processId' => $session['processId'],
        ]);

        $resp = Helpers::httpRequest($finishUrl, 'POST', $finishBody, $finishHeaders);
        $body = is_array($resp['body']) ? $resp['body'] : [];

        if (!empty($body['success']) && !empty($body['data']['tokenSN'])) {
            $session['tokenSN'] = $body['data']['tokenSN'];

            $vtokenSecret = null;
            $rawSecret = null;
            if (!empty($body['data']['x509'])) {
                try {
                    $rawSecret = Crypto::completeECDH($body['data']['x509']);
                    $vtokenSecret = Crypto::encryptSecret($rawSecret);
                } catch (\Throwable $e) {
                    // log but continue
                }
            }

            // Fetch org context
            $orgUrl = Config::KASPI_MTOKEN_URL . '/v08/organizations/org-context-otp';
            $pi = $session['profileId'] !== null ? (string)$session['profileId'] : '';
            $orgHeaders = [
                'Content-Type'    => 'application/json',
                'Accept'          => '*/*',
                'Accept-Language' => 'ru',
                'Accept-Encoding' => 'gzip, deflate, br',
                'User-Agent'      => Config::uaNative(),
                'X-Kb-TokenSn'    => $session['tokenSN'],
                'X-Kb-TokenSnMac' => Crypto::computeTokenSnMac($session['tokenSN'], $rawSecret),
                'X-Install-ID'    => $d->installId,
                'X-App-Ver'       => $a['version'],
                'X-App-Bld'       => $a['build'],
                'X-Locale'        => $a['locale'],
                'X-Call'          => 'notConnected',
                'X-Time'          => Helpers::nowISO(),
                'X-S'             => 'R:0|E:0|RH:0|N:0',
                'X-SV'            => '2',
                'X-Kb-Client-Ip'  => '192.168.1.96',
                'X-PkTag'         => $d->pkTag,
                'X-SU'            => Crypto::computeXSU($orgUrl),
                'X-SH'            => $pi
                    ? 'url,X-Kb-Client-Ip,X-App-Bld,X-S,X-Kb-TokenSn,X-Time,X-App-Ver,X-Kb-TokenSnMac,X-Call,X-PI,X-Install-ID,X-Locale,X-SV'
                    : 'url,X-Kb-Client-Ip,X-Time,X-App-Ver,X-SV,X-Locale,X-App-Bld,X-Install-ID,X-Kb-TokenSn,X-S,X-Kb-TokenSnMac,X-Call',
                'X-Request-ID'    => Helpers::uuid(),
            ];
            if ($pi !== '') $orgHeaders['X-PI'] = $pi;
            $orgHeaders['X-Sign'] = Crypto::computeXSign($orgUrl, $orgHeaders, $orgHeaders['X-SH']);

            $orgBody = Helpers::jenc([
                'DeviceInformation' => self::deviceInformation(),
                'OrganizationId'    => 0,
            ]);
            $orgResp = Helpers::httpRequest($orgUrl, 'POST', $orgBody, $orgHeaders);
            $orgJson = is_array($orgResp['body']) ? $orgResp['body'] : [];

            if (!empty($orgJson['Data']['Current']['ProfileId'])) {
                Session::applyOrgContext($session, $orgJson['Data']);
            }

            return [
                'tokenSN'         => $session['tokenSN'],
                'vtokenSecret'    => $vtokenSecret,
                'profileId'       => $session['profileId'],
                'organizationId'  => $session['organizationId'],
                'orgName'         => $session['orgName'],
                'phone'           => $session['phoneNumber'],
                'organizations'   => $orgJson['Data']['Organizations'] ?? null,
            ];
        }

        throw new \RuntimeException('Finish failed: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    /** SignInLite — refresh tokenSN + vtokenSecret using saved ECDH key.
     *  Takes sessionId from X-Session-Id (or body) and re-saves tokens to DB. */
    private static function refresh(): never
    {
        $user = Http::requireUser();
        $b = Http::jsonBody();
        $sessionId = (int)(Http::header('X-Session-Id') ?? $b['sessionId'] ?? 0);
        if ($sessionId <= 0) Http::error('sessionId required', 400);

        $ks = \Kaspi\KaspiSession::find($user->id, $sessionId);
        if (!$ks) Http::error('Session not found', 404);
        if (empty($ks['token_sn']) || empty($ks['vtoken_secret'])) {
            Http::error('Session has no tokens — re-authenticate via SMS', 400);
        }

        $tokenSN      = $ks['token_sn'];
        $vtokenSecret = $ks['vtoken_secret'];
        $orgId        = (int)($ks['organization_id'] ?? 0);

        $d = Device::instance();
        $a = Config::APP;

        $rawSecret = Crypto::decryptSecret($vtokenSecret);

        $liteUrl = Config::KASPI_MTOKEN_URL . '/v03/auth/sign-in-lite';
        $liteHeaders = [
            'Content-Type'    => 'application/json',
            'Accept'          => '*/*',
            'Accept-Language' => 'ru',
            'Accept-Encoding' => 'gzip, deflate, br',
            'User-Agent'      => Config::uaNative(),
            'X-Kb-TokenSn'    => $tokenSN,
            'X-Kb-TokenSnMac' => Crypto::computeTokenSnMac($tokenSN, $rawSecret),
            'X-Install-ID'    => $d->installId,
            'X-App-Ver'       => $a['version'],
            'X-App-Bld'       => $a['build'],
            'X-Locale'        => $a['locale'],
            'X-Call'          => 'notConnected',
            'X-Time'          => Helpers::nowISO(),
            'X-S'             => 'R:0|E:0|RH:0|N:0',
            'X-SV'            => '2',
            'X-Kb-Client-Ip'  => '192.168.1.96',
            'X-PkTag'         => $d->pkTag,
            'X-SU'            => Crypto::computeXSU($liteUrl),
            'X-SH'            => 'url,X-Kb-Client-Ip,X-Time,X-App-Ver,X-SV,X-Locale,X-App-Bld,X-Install-ID,X-Kb-TokenSn,X-S,X-Kb-TokenSnMac,X-Call',
            'X-Request-ID'    => Helpers::uuid(),
        ];
        $liteHeaders['X-Sign'] = Crypto::computeXSign($liteUrl, $liteHeaders, $liteHeaders['X-SH']);

        $liteBody = Helpers::jenc([
            'OrganizationId'    => (int)$orgId,
            'DeviceInformation' => self::deviceInformation(),
        ]);
        $resp = Helpers::httpRequest($liteUrl, 'POST', $liteBody, $liteHeaders);
        $body = is_array($resp['body']) ? $resp['body'] : [];

        if (($body['StatusCode'] ?? null) !== 0 || empty($body['Data'])) {
            Http::json([
                'success'    => false,
                'statusCode' => $body['StatusCode'] ?? null,
                'message'    => $body['Message'] ?? $body['Description'] ?? 'SignInLite failed — re-auth via SMS required',
                'body'       => $body,
            ]);
        }

        $newTokenSN     = $body['Data']['TokenSn'] ?? $body['Data']['tokenSN'] ?? $tokenSN;
        $newVtokenSecret = $vtokenSecret;
        $newRawSecret   = null;
        $serverX509     = $body['Data']['X509'] ?? $body['Data']['x509'] ?? null;

        if ($serverX509) {
            try {
                $newRawSecret = Crypto::completeECDHWithSaved($serverX509);
                $newVtokenSecret = Crypto::encryptSecret($newRawSecret);
            } catch (\Throwable) {}
        }
        $activeRaw = $newRawSecret ?? Crypto::decryptSecret($newVtokenSecret);

        $session = Session::empty();
        $session['tokenSN'] = $newTokenSN;
        if (!empty($body['Data']['OrganizationContext']) || !empty($body['Data']['OrganizationContextLite'])) {
            Session::applyOrgContext($session, $body['Data']['OrganizationContext'] ?? $body['Data']['OrganizationContextLite']);
        }

        // org-context-otp
        $orgUrl = Config::KASPI_MTOKEN_URL . '/v08/organizations/org-context-otp';
        $orgHeaders = [
            'Content-Type'    => 'application/json',
            'Accept'          => '*/*',
            'Accept-Language' => 'ru',
            'Accept-Encoding' => 'gzip, deflate, br',
            'User-Agent'      => Config::uaNative(),
            'X-Kb-TokenSn'    => $newTokenSN,
            'X-Kb-TokenSnMac' => Crypto::computeTokenSnMac($newTokenSN, $activeRaw),
            'X-Install-ID'    => $d->installId,
            'X-App-Ver'       => $a['version'],
            'X-App-Bld'       => $a['build'],
            'X-Locale'        => $a['locale'],
            'X-Call'          => 'notConnected',
            'X-Time'          => Helpers::nowISO(),
            'X-S'             => 'R:0|E:0|RH:0|N:0',
            'X-SV'            => '2',
            'X-Kb-Client-Ip'  => '192.168.1.96',
            'X-PkTag'         => $d->pkTag,
            'X-PI'            => (string)($session['profileId'] ?? ''),
            'X-SU'            => Crypto::computeXSU($orgUrl),
            'X-SH'            => 'url,X-Kb-Client-Ip,X-Time,X-App-Ver,X-SV,X-Locale,X-App-Bld,X-Install-ID,X-Kb-TokenSn,X-S,X-Kb-TokenSnMac,X-Call',
            'X-Request-ID'    => Helpers::uuid(),
        ];
        $orgHeaders['X-Sign'] = Crypto::computeXSign($orgUrl, $orgHeaders, $orgHeaders['X-SH']);

        $orgBody = Helpers::jenc([
            'OrganizationId'    => (int)($orgId ?: ($session['organizationId'] ?? 0)),
            'DeviceInformation' => self::deviceInformation(),
        ]);
        $orgContextOk = false;
        try {
            $orgResp = Helpers::httpRequest($orgUrl, 'POST', $orgBody, $orgHeaders);
            $orgJson = is_array($orgResp['body']) ? $orgResp['body'] : [];
            if (($orgJson['StatusCode'] ?? null) === 0 && !empty($orgJson['Data'])) {
                Session::applyOrgContext($session, $orgJson['Data']);
                $orgContextOk = true;
            }
        } catch (\Throwable) {}

        \Kaspi\KaspiSession::updateTokens($sessionId, $newTokenSN, $newVtokenSecret);

        Http::json([
            'success'        => true,
            'sessionId'      => $sessionId,
            'profileId'      => $session['profileId'],
            'organizationId' => $session['organizationId'],
            'orgName'        => $session['orgName'],
            'orgContext'     => $orgContextOk,
            'message'        => 'Session refreshed via SignInLite + org-context',
        ]);
    }

    private static function deviceInformation(): array
    {
        $d = Device::instance();
        $a = Config::APP;
        return [
            'SdkVersion'           => 'AOTP service',
            'DeviceId'             => $d->deviceId,
            'ApplicationId'        => 'kz.kaspi.business',
            'ScreenWidth'          => $a['screenW'],
            'Model'                => $a['model'],
            'ScreenHeight'         => $a['screenH'],
            'DeviceName'           => $a['deviceName'],
            'VersionName'          => $a['version'],
            'BuildRelease'         => "{$a['platform']} {$a['platformVer']}",
            'Brand'                => $a['brand'],
            'Board'                => $a['platformVer'],
            'Platform'             => $a['platform'],
            'Product'              => 'Kaspi Pay',
            'frontCameraAvailable' => true,
            'VersionCode'          => $a['build'],
            'InstallId'            => $d->installId,
        ];
    }
}
