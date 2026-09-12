# GCash QR and bank receiving accounts

Staff can open **Payment Settings** to add GCash or bank accounts, upload their
receiving QR image (PNG/JPEG, up to 3 MB), replace an account, or disable it for new
orders. Multiple bank and GCash accounts are supported. A bank account without a QR
can still receive manual transfers using its displayed account details.

Mobile clients choose a receiving account during product checkout. The order page
shows its account name, number, bank/wallet, and QR. **Open QR image** opens the image
in the browser so clients can save it and import it into their payment app when
supported. The customer enters the transfer reference and uploads payment proof.
Staff must check the actual transfer before verifying it and recording a paid sale.

Account versions and QR images are stored in MySQL, so they survive host restarts.
Replacing/disabling an account does not change the recipient or QR for existing
orders. Old account QR URLs intentionally remain available for those orders.
QR URLs contain random tokens. Proof screenshots remain behind the existing
authenticated proof endpoint.

## Website deployment

Apply these files to the actual `vetrix_webapp` repository, preserving its existing
database configuration and any unrelated changes:

- `includes/product_orders.php`
- `includes/payment_accounts.php` (new)
- `migrations/payment_accounts.sql` (new)
- `api/mobile/v1/payment_qr.php` (new)
- `staff/payment_settings.php`
- `staff/product_orders.php`
- `staff/notifications.php` (payment wording only)
- `includes/staff_sidebar.php` (rename GCash Settings to Payment Settings only)

The existing `migrations/product_orders.sql` and mobile API must already be present.
Run `migrations/payment_accounts.sql` against the website database before enabling
the feature. It adds the bank payment method and three new tables without deleting
existing orders or payment settings. The existing schema initializer also applies
this migration if the new tables are absent, provided the DB user has DDL access.

Deploy the backend before distributing the new APK. The app tolerates an older
backend by showing its existing GCash/pay-at-clinic options, but QR and bank options
only appear after the new backend is deployed and staff saves receiving accounts.
No real payment account details are seeded or invented.

## Verification

`php tests/product_orders_test.php` creates and removes a randomly named test database.
Use `--live-schema` to also verify the newer electronic POS receipt schema.
`tests/payment_accounts_cases.php` adds account authorization/validation, recipient
snapshot, reference reuse, disabled-account, proof review, and bank receipt checks.

Validated locally: 67 original-schema checks, 77 newer-schema checks, staff HTTP login,
CSRF enforcement, multipart QR upload, QR retrieval, invalid token handling, and forged
image rejection. Mobile ESLint and Android bundle export passed.

Live Railway deployment and payment testing against a real clinic account remain
separate from these isolated synthetic tests.
