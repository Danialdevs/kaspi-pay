#!/usr/bin/env php
<?php
/**
 * Создать магазин (shop) с логином, паролем и API-ключом.
 *
 *   php bin/create-shop.php <username> [<password>]
 *
 * Если password не указан — сгенерируется случайный и распечатается.
 * API-ключ генерируется автоматически и тоже печатается в stdout.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Bootstrap.php';
Kaspi\Bootstrap::init(dirname(__DIR__));

$args = array_slice($argv, 1);
if (count($args) < 1) {
    fwrite(STDERR, "Usage: php bin/create-shop.php <username> [<password>]\n");
    exit(2);
}
$username = $args[0];
$password = $args[1] ?? bin2hex(random_bytes(6)); // 12 hex chars

try {
    $u = Kaspi\User::create($username, $password);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Магазин создан:\n";
echo "  id:        {$u->id}\n";
echo "  username:  {$u->username}\n";
echo "  password:  {$password}\n";
echo "  apiKey:    {$u->apiKey}\n";
