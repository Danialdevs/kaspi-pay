-- Kaspi POS Automation — MySQL schema
-- usage: mysql -u root -p kaspi < schema.sql

CREATE DATABASE IF NOT EXISTS kaspi
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE kaspi;

CREATE TABLE IF NOT EXISTS kv_store (
    k          VARCHAR(128) PRIMARY KEY,
    v          LONGTEXT     NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
    process_id VARCHAR(128) PRIMARY KEY,
    payload    LONGTEXT     NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
