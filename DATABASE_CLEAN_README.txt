VETRIX CLEAN-CHECKING DATABASE
==============================

File
----
vetrix.sql

What it does
------------
- Deletes and recreates the application's main database named vetrix.
- Installs the current 20-table schema, including mobile_api_tokens.
- Leaves all operational/business tables empty.
- Adds only three minimal clinic accounts so every staff role can be checked.

Local checking accounts
-----------------------
Administrator: admin@vetclinic.test
Veterinarian:  vet@vetclinic.test
Staff:         staff@vetclinic.test
Password:      VetrixDemo!2026

These credentials are for local checking only. Change or remove them before
using the database in any public or production environment.

Import with phpMyAdmin
----------------------
1. Open phpMyAdmin.
2. Choose Import. You do not need to select or create a database first.
3. Select vetrix.sql and run the import.
4. Confirm that vetrix contains 20 tables.

Use the clean database with Vetrix
----------------------------------
No database-name override is required. The application defaults to vetrix,
which is now the clean main database. Remove any VETRIX_DB_NAME override that
still points to vetrix_clean, then restart Apache if you changed that setting.

Quick validation
----------------
Run these queries in phpMyAdmin after import:

SELECT COUNT(*) AS table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'vetrix';

SELECT role, COUNT(*) AS account_count
FROM vetrix.users
GROUP BY role
ORDER BY role;

SELECT
  (SELECT COUNT(*) FROM vetrix.pets) AS pets,
  (SELECT COUNT(*) FROM vetrix.appointments) AS appointments,
  (SELECT COUNT(*) FROM vetrix.medical_records) AS medical_records,
  (SELECT COUNT(*) FROM vetrix.pos_transactions) AS pos_transactions;

Expected results
----------------
- table_count: 20
- users: one admin, one veterinarian, and one staff account
- pets, appointments, medical_records, and pos_transactions: all 0
