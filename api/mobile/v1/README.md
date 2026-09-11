# Vetrix Mobile API v1

This directory is a staging copy of the client API. It does not change the active
XAMPP website until it is reviewed and copied into the live `vetrix/api/mobile/v1`
directory. The API uses the website's existing database connection and does not
scrape HTML or reuse browser sessions.

## Setup

1. Select the Vetrix database and run `migrations/mobile_api_tokens.sql`.
   The API also checks that the table exists, but production database users should
   receive only the permissions they need after migration.
2. Deploy this directory below the website root so the base URL is similar to:
   `https://example.test/vetrix/api/mobile/v1`.
3. Set `EXPO_PUBLIC_VETRIX_API_URL` in the Expo app to that base URL. A physical
   device cannot use the development computer's `localhost`; use its Wi-Fi address
   during LAN testing or use an HTTPS development tunnel. Do not put credentials
   or tokens in an `EXPO_PUBLIC_` value.

Optional server environment variables:

- `VETRIX_MOBILE_CORS_ORIGINS`: comma-separated exact Expo web origins, such as
  `http://localhost:8081,http://127.0.0.1:8081`. Native requests normally have no
  browser `Origin` header. When this variable is unset, HTTP loopback origins and
  the same private-LAN host on another port are accepted for local development;
  HTTPS deployments remain exact-origin only. Use `*` only for deliberate public
  bearer-token access.
- `VETRIX_MOBILE_TOKEN_TTL_DAYS`: bearer-token lifetime from 1 to 90 days; default 30.
- `VETRIX_PUBLIC_BASE_URL`: public website base used to make uploaded media URLs
  absolute, for example `https://example.test/vetrix`.

Keep database and mail credentials outside the public document root. Do not deploy
database dumps or source ZIP archives below `htdocs`.

## Contract

Errors consistently use:

```json
{"success":false,"message":"Readable message","errors":{"field":"Field message"}}
```

Successful responses use `{"success":true,"data":{...}}`. Send JSON with
`Content-Type: application/json`. Protected endpoints require:

```text
Authorization: Bearer <access_token>
```

Store the token in Expo SecureStore on native platforms. Never log it or place it
in source code. Tokens are random opaque values; only their SHA-256 hashes are kept
in the database.

## Endpoints

- `POST register.php` — submit a pending client registration. Fields: `full_name`
  (or `name`), `email`, `phone`, `address`, `password`, and optional
  `confirm_password`.
- `POST verify_otp.php` — verify an approved client with `{ "email": "...", "otp": "123456" }`.
  It activates the account but does not issue a token.
- `POST login.php` — sign in an active, OTP-verified client with `email`, `password`,
  and optional `device_name`; returns `access_token` once.
- `GET me.php` — current client profile and token expiry.
- `POST logout.php` — revoke the current bearer token.
- `GET bootstrap.php` — owner-scoped profile, pets, active QR tokens, appointments,
  medical records, vaccinations, notifications, edit requests, live client-visible
  inventory products, and summary counts.
- `POST appointments.php` — create an appointment with `pet_id`, `service_type`,
  `concern`, and `requested_date`. The API stores the website-readable reason as
  `Service type — concern`; older rows are returned as `Clinic Visit`.
- `PATCH appointments.php` or `POST appointments.php` with `action=cancel` — cancel
  an owned pending or approved appointment; include `id`.
- `POST notifications.php` with `action=mark_read` and `id` — mark one owned alert.
- `POST notifications.php` with `action=mark_all` — mark all owned alerts read.
- `PATCH profile.php` or `POST profile.php` — partially update `full_name`, `email`,
  `phone`, and `address`.

For a quick local check, replace the placeholders and use a client account that the
clinic has approved and OTP-verified:

```powershell
$base = 'http://YOUR-WIFI-IP/vetrix/api/mobile/v1'
$body = @{ email = 'client@example.test'; password = 'REDACTED'; device_name = 'Local test' } | ConvertTo-Json
Invoke-RestMethod -Method Post -Uri "$base/login.php" -ContentType 'application/json' -Body $body
```
