<?php
declare(strict_types=1);

namespace Kaspi;

/**
 * MySQL storage via PDO. Single connection, key-value style.
 * Tables initialized automatically on first connect.
 */
final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo) return self::$pdo;

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME', 'kaspi');
        $user = Env::get('DB_USER', 'root');
        $pass = Env::get('DB_PASS', '');
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        try {
            self::$pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            fwrite(STDERR, "FATAL: MySQL connect failed: " . $e->getMessage() . "\n");
            exit(1);
        }

        self::ensureSchema();
        return self::$pdo;
    }

    private static function ensureSchema(): void
    {
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS kv_store (
                k VARCHAR(128) PRIMARY KEY,
                v LONGTEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS auth_sessions (
                process_id VARCHAR(128) PRIMARY KEY,
                payload    LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                username      VARCHAR(64)  NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                api_key       VARCHAR(64)  NOT NULL UNIQUE,
                created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_users_api (api_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS kaspi_sessions (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                user_id         INT NOT NULL,
                name            VARCHAR(128) NULL,
                phone_number    VARCHAR(20)  NULL,
                token_sn        VARCHAR(255) NULL,
                vtoken_secret   TEXT NULL,
                profile_id      BIGINT NULL,
                organization_id BIGINT NULL,
                org_name        VARCHAR(255) NULL,
                api_token       VARCHAR(64) NULL UNIQUE,
                status          ENUM('pending','active','expired') NOT NULL DEFAULT 'pending',
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ks_user (user_id),
                INDEX idx_ks_token (api_token),
                CONSTRAINT fk_ks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Backfill column for older deployments
        try {
            self::$pdo->exec("ALTER TABLE kaspi_sessions ADD COLUMN api_token VARCHAR(64) NULL UNIQUE AFTER org_name");
            self::$pdo->exec("CREATE INDEX idx_ks_token ON kaspi_sessions (api_token)");
        } catch (\Throwable) { /* already exists */ }

        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS products (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT NOT NULL,
                name        VARCHAR(255) NOT NULL,
                description TEXT NULL,
                price       DECIMAL(12,2) NOT NULL,
                image_url   VARCHAR(512) NULL,
                active      TINYINT(1) NOT NULL DEFAULT 1,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_products_user (user_id, active),
                CONSTRAINT fk_products_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS orders (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                user_id          INT NOT NULL,
                product_id       INT NULL,
                kaspi_session_id INT NULL,
                customer_name    VARCHAR(128) NOT NULL,
                customer_phone   VARCHAR(20)  NOT NULL,
                product_name     VARCHAR(255) NOT NULL,
                amount           DECIMAL(12,2) NOT NULL,
                qr_operation_id  VARCHAR(64) NULL,
                qr_token         TEXT NULL,
                status           ENUM('pending','success','failed','expired','unknown') NOT NULL DEFAULT 'pending',
                raw_status       VARCHAR(64) NULL,
                created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_orders_user (user_id, created_at),
                INDEX idx_orders_status (status),
                INDEX idx_orders_qrop (qr_operation_id),
                CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    public static function kvGet(string $key): ?string
    {
        $st = self::pdo()->prepare('SELECT v FROM kv_store WHERE k = :k');
        $st->execute([':k' => $key]);
        $row = $st->fetch();
        return $row ? (string)$row['v'] : null;
    }

    public static function kvSet(string $key, string $value): void
    {
        $st = self::pdo()->prepare(
            'INSERT INTO kv_store (k, v) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        $st->execute([':k' => $key, ':v' => $value]);
    }

    public static function kvDel(string $key): void
    {
        self::pdo()->prepare('DELETE FROM kv_store WHERE k = :k')->execute([':k' => $key]);
    }

    public static function authPut(string $processId, string $payload): void
    {
        $st = self::pdo()->prepare(
            'INSERT INTO auth_sessions (process_id, payload) VALUES (:p, :v)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), created_at = CURRENT_TIMESTAMP'
        );
        $st->execute([':p' => $processId, ':v' => $payload]);
        // Prune older than 15 min
        self::pdo()->exec('DELETE FROM auth_sessions WHERE created_at < (NOW() - INTERVAL 15 MINUTE)');
    }

    public static function authGet(string $processId): ?string
    {
        $st = self::pdo()->prepare('SELECT payload FROM auth_sessions WHERE process_id = :p');
        $st->execute([':p' => $processId]);
        $row = $st->fetch();
        return $row ? (string)$row['payload'] : null;
    }

    public static function authDel(string $processId): void
    {
        self::pdo()->prepare('DELETE FROM auth_sessions WHERE process_id = :p')->execute([':p' => $processId]);
    }
}
