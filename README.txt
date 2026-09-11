VETRIX CLINIC MANAGEMENT AND MONITORING SYSTEM
==========================================================

INSTALLATION
------------
1. Extract the vetrix folder into the web server document root.
   XAMPP example: C:\xampp\htdocs\vetrix
2. Start Apache and MySQL or MariaDB.
3. In phpMyAdmin, import vetrix.sql. The script deletes and recreates the vetrix
   database with the clean 20-table schema and three minimal clinic accounts.
   Back up any database records that must be retained before importing it.
4. Confirm these folders are writable by the web server:
   uploads/pets/
   uploads/profiles/
   uploads/products/
   uploads/proofs/
5. Open:
   http://localhost/vetrix/

DATABASE CONFIGURATION
----------------------
Default values are suitable for a standard XAMPP installation:
- Host: localhost
- Port: 3306
- User: root
- Password: blank
- Database: vetrix

Optional environment variables:
- VETRIX_APP_ENV=development (shows demo access away from localhost; production hosts hide it by default)
- VETRIX_DB_HOST
- VETRIX_DB_PORT
- VETRIX_DB_USER
- VETRIX_DB_PASS
- VETRIX_DB_NAME

DATABASE TABLES
---------------
The packaged SQL contains exactly 20 application tables:
- users
- account_verification_tokens
- pets
- edit_requests
- appointments
- medical_records
- mobile_api_tokens
- vaccinations
- qr_tokens
- notifications
- email_outbox
- feedback
- inventory_items
- inventory_movements
- inventory_restock_alerts
- pos_transactions
- pos_transaction_items
- pos_receipts
- staff_availability
- audit_logs

Pet update history and vaccination reminder actions are recorded through audit_logs. Notifications remain in-system and email delivery attempts remain in email_outbox. No GSM or SMS delivery queue is included.

EMAIL CONFIGURATION
-------------------
Email credentials are not stored in the project source. Every email attempt is recorded in email_outbox even when live SMTP delivery is disabled. Queued outbox rows are audit records and are not retried automatically.

Preferred setup (environment variables):
- VETRIX_MAIL_FROM=clinic@gmail.com
- VETRIX_MAIL_FROM_NAME=Vetrix
- VETRIX_SMTP_HOST=smtp.gmail.com
- VETRIX_SMTP_PORT=587
- VETRIX_SMTP_SECURE=tls
- VETRIX_SMTP_USERNAME=clinic@gmail.com
- VETRIX_SMTP_PASSWORD=your 16-character Google App Password
- VETRIX_SMTP_TIMEOUT=30
- VETRIX_SMTP_CA_FILE=C:\xampp\apache\bin\curl-ca-bundle.crt (optional; PHP's openssl.cafile is used by default)
- VETRIX_MAIL_ENABLED=true (optional; auto-enabled when valid credentials are present)

For a local-file setup, copy config/mail.local.php.example to config/mail.local.php and replace both credential placeholders. The local file and common .env secret files are excluded by .gitignore; examples remain trackable.

Use a Google App Password rather than a normal Gmail password. Enable 2-Step Verification on the Gmail account first, keep the password out of source control, and restart Apache after changing environment variables. Resend the OTP after configuration because existing queued rows are not delivered later.

CLIENT RECORDS
--------------
Client/customer records remain in the database because pet ownership, appointments, medical records, vaccination records, POS history, and clinic reporting depend on them. The separate client portal user type and its pages are not included. Client/customer records cannot sign in to the clinic staff interface.

LOCAL CHECKING ACCOUNTS
-----------------------
Seeded clinic accounts use this password:
VetrixDemo!2026

Administrator
- admin@vetclinic.test

Veterinarian
- vet@vetclinic.test

Staff
- staff@vetclinic.test

No client/customer, pet, appointment, clinical, inventory, sales, schedule,
notification, feedback, token, or audit rows are preloaded.

ROLE WORKFLOWS
--------------
Administrator
- Manage clinic client/customer records
- Deactivate, restore, or soft-delete clinic staff and veterinarian accounts
- Review and verify pet profiles and pet edit requests
- Schedule appointments and assign veterinarians
- Manage workforce availability, unavailability, and clinic schedules
- Manage notifications, inventory, POS pricing, vaccinations, QR retrieval, reports, exports, feedback, users, and activity logs

Veterinarian
- View assigned and permitted clinic appointments
- Review pet health information
- Complete consultations and appointments
- Create medical records and vaccination records
- Set the next vaccination due date
- Record personal availability or valid unavailability

Staff
- Assist clients and encode pet profiles
- Review and schedule appointment requests within role access
- Assign veterinarians where permitted
- Process POS transactions
- Record inventory stock movements
- Scan or retrieve pet QR records

IMPORTANT BUSINESS RULES
------------------------
- Client/customer rows are clinic-managed records used for ownership and transaction relationships, not a portal login role.
- Pending, rejected, in-person-confirmation, or otherwise unapproved pets cannot be used for appointment booking.
- Inventory owns stock quantity, unit, reorder level, and stock movements.
- POS owns selling price, product sales presentation, and transaction history. POS cannot directly change stock.
- Appointment scheduling checks approved appointment conflicts and valid workforce unavailability.
- Staff, veterinarian, and administrator access remains role based.
- State-changing forms use CSRF protection.
- Uploaded profile, pet, product, and proof images accept supported image formats subject to the application upload checks.

REPORTS AND EXPORTS
-------------------
Reports and exports are grouped under Admin > Reports. Printable report pages use one Print / Save PDF action. PDF output is handled through the browser print destination with report-only styling. CSV exports remain available from the reports workspace.

UPDATING AN EXISTING DATABASE
-----------------------------
For a fresh installation or a complete clean reset, import vetrix.sql. This
recreates the main vetrix database and removes its existing records.

For an existing installation:
1. Back up the current database.
2. Review the migration files in migrations/ in version order.
3. Apply only the migrations required by the existing schema.
4. Verify the resulting schema before using it in production.

VALIDATION COMPLETED FOR THIS PACKAGE
-------------------------------------
- PHP syntax validation passed for all packaged PHP files.
- JavaScript syntax validation passed for the consolidated assets/js/app.js.
- The main SQL file contains exactly 20 CREATE TABLE statements.
- The clean schema contains 20 InnoDB tables and 37 foreign-key constraints.
- Admin, veterinarian, and staff login/dashboard checks passed against Apache.
- Static scans found no application references to pet_update_logs, sms_outbox, or vaccination_reminders.

A full browser, SMTP, camera, and live database workflow test still requires Apache, MySQL or MariaDB, and the target machine configuration.
