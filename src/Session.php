<?php
declare(strict_types=1);

namespace Kaspi;

final class Session
{
    public const FIELDS = [
        'processId', 'userToken', 'tokenSN', 'tokenSnMac', 'qrPayTokenSnMac',
        'profileId', 'phoneNumber', 'orgName', 'userId',
        'organizationId', 'organizationIdn', 'organizationKbe',
        'empId', 'accessLevelType', 'isCashier', 'payerType',
        'categoryName', 'possiblePaymentMethods', 'showFakeCard',
    ];

    public static function empty(): array
    {
        return array_fill_keys(self::FIELDS, null);
    }

    public static function applyOrgContext(array &$session, array $data): void
    {
        $cur = $data['Current'] ?? [];
        $map = [
            'profileId'             => $cur['ProfileId']            ?? null,
            'orgName'               => $cur['OrganizationName']     ?? null,
            'organizationId'        => $cur['OrganizationId']       ?? null,
            'organizationIdn'       => $cur['OrganizationIdn']      ?? null,
            'organizationKbe'       => $cur['OrganizationKbe']      ?? null,
            'empId'                 => $cur['EmpId']                ?? null,
            'accessLevelType'       => $cur['AccessLevelType']      ?? null,
            'isCashier'             => $cur['IsCashier']            ?? null,
            'payerType'             => $cur['PayerType']            ?? null,
            'categoryName'          => $cur['CategoryName']         ?? null,
            'possiblePaymentMethods'=> $cur['PossiblePaymentMethods']?? null,
            'showFakeCard'          => $cur['ShowFakeCard']         ?? null,
            'userId'                => $data['UserId']              ?? null,
            'phoneNumber'           => $data['PhoneNumber']         ?? null,
        ];
        foreach ($map as $k => $v) {
            if ($v !== null) $session[$k] = $v;
        }
    }

    /** Auth-in-flight store keyed by processId (DB-backed). */

    public static function putAuth(string $processId, array $session): void
    {
        Db::authPut($processId, json_encode($session, JSON_UNESCAPED_UNICODE));
    }

    public static function getAuth(string $processId): ?array
    {
        $raw = Db::authGet($processId);
        if (!$raw) return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    public static function delAuth(string $processId): void
    {
        Db::authDel($processId);
    }
}
