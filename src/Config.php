<?php
declare(strict_types=1);

namespace Kaspi;

final class Config
{
    public const KASPI_ENTRANCE_URL = 'https://entrance-pay.kaspi.kz';
    public const KASPI_MTOKEN_URL   = 'https://mtoken.kaspi.kz';
    public const KASPI_QRPAY_URL    = 'https://qrpay.kaspi.kz';

    public const APP = [
        'version'     => '4.105',
        'build'       => '1070',
        'platform'    => 'iOS',
        'platformVer' => '18.5',
        'locale'      => 'ru-RU',
        'model'       => 'iPhone17,3',
        'brand'       => 'Apple',
        'deviceName'  => 'iPhone',
        'screenW'     => '393.0',
        'screenH'     => '852.0',
        'cfNetwork'   => 'CFNetwork/3826.500.131',
        'darwin'      => 'Darwin/24.5.0',
    ];

    public static string $rootDir = '';
    public static string $dataDir = '';

    public static function init(string $rootDir): void
    {
        self::$rootDir = $rootDir;
        self::$dataDir = $rootDir . '/data';
        if (!is_dir(self::$dataDir)) mkdir(self::$dataDir, 0775, true);
    }

    public static function port(): int
    {
        return (int)(Env::get('PORT', '8080'));
    }

    public static function uaNative(): string
    {
        return 'Kaspi%20Pay/' . self::APP['build'] . ' ' . self::APP['cfNetwork'] . ' ' . self::APP['darwin'];
    }

    public static function uaBrowser(): string
    {
        $pv = str_replace('.', '_', self::APP['platformVer']);
        return "Mozilla/5.0 (iPhone; CPU iPhone OS {$pv} like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148";
    }

    /** @return array<string,string> */
    public static function entranceHeadersBase(): array
    {
        return [
            'Accept'           => 'application/json, text/plain, */*',
            'Content-Type'     => 'application/json',
            'Accept-Language'  => 'ru',
            'Accept-Encoding'  => 'gzip, deflate, br',
            'Origin'           => self::KASPI_ENTRANCE_URL,
            'Sec-Fetch-Site'   => 'same-origin',
            'Sec-Fetch-Mode'   => 'cors',
            'Sec-Fetch-Dest'   => 'empty',
            'User-Agent'       => self::uaBrowser(),
        ];
    }
}
