# Kaspi POS Automation — PHP

Чистый PHP-порт Node-проекта [tapter-dev/kaspi-pos-automation](https://github.com/tapter-dev/kaspi-pos-automation).

Сервер прикидывается iOS-приложением Kaspi Pay для кассира на уровне HTTPS-протокола: ECDH, ECDSA-подпись каждого запроса, OCRA-1 TOTP (vtoken), AES-256-GCM для секрета сессии. Эмулятор телефона не нужен — SMS-авторизация делается программно, дальше работа идёт через сохранённый `tokenSN + vtokenSecret` в БД.

Мульти-юзер: один аккаунт может держать несколько Kaspi-кассиров; токены хранятся только на сервере, клиенту выдаются `apiKey` (для пользователя) и `sessionId` (для конкретного кассира).

## Требования

- PHP ≥ 8.1 с `openssl`, `curl`, `pdo_mysql`, `json`, `hash`
- Бинарь `openssl` в `$PATH` (для ECDH-derive)
- MySQL 5.7+ / MariaDB 10.3+

## Быстрый старт

```bash
cp .env.example .env
echo "TOKEN_SECRET_KEY=$(openssl rand -hex 32)" >> .env
# заполнить DB_* в .env

mysql -u root -p < schema.sql      # таблицы (или поднимутся сами при первом запросе)
php -S 0.0.0.0:8080 -t public public/index.php
```

- UI: <http://localhost:8080>
- Документация API (Swagger UI): <http://localhost:8080/docs>

## Архитектура

```
Browser UI (public/)            Swagger UI (public/docs.html)
   │                                       │
   └─►  public/index.php  ── роутер ───────┘
            │
            └─►  src/
                ├─ Bootstrap.php   автозагрузчик + error handlers
                ├─ Config.php      версии iOS-приложения, URLs Kaspi
                ├─ Device.php      deviceId/installId/pinHash/ECDSA keypair (в БД)
                ├─ Crypto.php      ECDH (prime256v1), ECDSA, OCRA-1, AES-256-GCM
                ├─ Helpers.php     UUID, ISO-время, cookie, curl-обёртка, signedQrPayHeaders
                ├─ Session.php     модель сессии Kaspi-flow + in-flight store
                ├─ Db.php          PDO + миграции
                ├─ Env.php         .env loader
                ├─ Http.php        json/error/requireUser/requireKaspiSession
                ├─ User.php        регистрация/логин/findByApiKey
                ├─ KaspiSession.php  CRUD Kaspi-кассиров пользователя
                └─ Routes/
                    ├─ Account.php   /api/account/{register, login, me}
                    ├─ Sessions.php  /api/sessions/{list, rename, delete}
                    ├─ Auth.php      /api/auth/{init, send-phone, verify-otp, refresh}
                    └─ Pay.php       /api/pay/{qr, invoice, status, cancel, refund, history}
```

## API

Полная документация с интерактивной «Try it out» — на `/docs` (Swagger UI).
Исходник спецификации: [docs/openapi.yaml](docs/openapi.yaml).

Кратко:

| Группа | Назначение |
| --- | --- |
| `/api/account/*` | регистрация, логин, выдача `apiKey` |
| `/api/sessions/*` | список/удаление своих Kaspi-кассиров |
| `/api/auth/*` | 3-шаговый SMS-флоу подключения нового кассира |
| `/api/pay/*` | платежи: `qr`, `invoice`, `status`, `cancel`, `refund`, `history` |

Заголовки авторизации:

| Заголовок | Где брать | Когда нужен |
| --- | --- | --- |
| `X-Api-Key` | `/api/account/register` или `/api/account/login` | везде кроме register/login |
| `X-Session-Id` | `/api/sessions/list` или `/api/auth/verify-otp` | для `/api/pay/*` и `/api/auth/refresh` |

## Хранилище (MySQL)

| Таблица | Назначение |
| --- | --- |
| `users` | пользователи + bcrypt-хеш пароля + `api_key` |
| `kaspi_sessions` | per-user Kaspi-кассиры: `token_sn`, `vtoken_secret` (AES-GCM), `profile_id`, `organization_id`, `org_name`, `status` |
| `kv_store` | глобальная Device-identity (deviceId/installId/ECDSA-keypair) |
| `auth_sessions` | in-flight SMS-процессы (TTL 15 мин) |

Схема поднимается автоматически на первом подключении. Ручной импорт — [`schema.sql`](schema.sql).

## Заметки по реализации

- ECDH в PHP openssl-расширении отсутствует — секрет выводится через CLI `openssl pkeyutl -derive` (временные файлы в `sys_get_temp_dir`, удаляются сразу после).
- OCRA-1 suite `OCRA-1:HOTP-SHA256-6:QH64-T1M` повторяет точную раскладку iOS-приложения Kaspi Pay.
- `X-Sign` — ECDSA-P256-SHA256 (DER, base64), считается над конкатенацией значений в порядке `X-SH`.
- `nowISO()` возвращает **UTC-время + локальный offset** (то же, что `Date.prototype.toISOString()` в Node + offset суффикс). Каспи валидирует с точностью до минут.
- `X-Api-Key` — 32 случайных байта в hex (64 символа). `password_hash()` по умолчанию = bcrypt.

## Юридический статус

Неофициальная интеграция, формально нарушает ToS Kaspi Pay. Использовать на свой страх.
