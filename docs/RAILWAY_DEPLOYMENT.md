# Deploy Vetrix on Railway

The PHP pages and backend run together in one web service, alongside a MySQL
service. Netlify is not required. This starts with an empty database; local
records and uploads are not copied.

## Set up the project

1. Create a Railway project and add a **MySQL** database service. Wait until it is running.
2. Add a service from the GitHub repository **ubas520/vetrix_webapp**, branch **main**.
   Railway uses the root Dockerfile. An initial deployment may fail until variables are configured.
3. In the web service's **Variables**, enter the following. The references assume
   the database service is named `MySQL`; change that prefix if you renamed it.

| Variable | Value |
| --- | --- |
| `VETRIX_DB_HOST` | `${{MySQL.MYSQLHOST}}` |
| `VETRIX_DB_PORT` | `${{MySQL.MYSQLPORT}}` |
| `VETRIX_DB_USER` | `${{MySQL.MYSQLUSER}}` |
| `VETRIX_DB_PASS` | `${{MySQL.MYSQLPASSWORD}}` |
| `VETRIX_DB_NAME` | `${{MySQL.MYSQLDATABASE}}` |
| `VETRIX_APP_ENV` | `production` |
| `VETRIX_ADMIN_EMAIL` | Your administrator email |
| `VETRIX_ADMIN_PASSWORD` | A unique password of 12-72 bytes |

Enter passwords directly in Railway, never in GitHub or chat.

4. Attach a volume to the **web service** at `/var/www/html/uploads`.
   This preserves uploaded photos and payment files across deployments.
5. Deploy the pending changes. Leave build/start command overrides empty.
   The startup script creates the 20 base tables and one administrator only when
   the database is empty. Subsequent starts retain existing data and passwords.
   It refuses incomplete existing schemas; it is not a migration runner.
   Do not import `vetrix.sql` into Railway: that file resets a local database
   and contains published demo passwords.
6. Under **Settings > Networking**, generate a public domain with target port **80**.
7. Set `VETRIX_PUBLIC_BASE_URL` to that full HTTPS URL, without a trailing slash.
8. Open the URL and sign in with your administrator credentials. After the first
   successful setup, remove `VETRIX_ADMIN_PASSWORD` and `VETRIX_ADMIN_EMAIL` from
   Railway variables; they are only needed to initialize an empty database.

## Email and testing

Configure the SMTP variables listed in `README.txt` directly in Railway before
testing email verification or password reset. Do not copy the Windows CA-file
path; the container uses its Linux certificate store. Verify your Railway plan
allows outbound SMTP; otherwise use a supported email transport/provider.

- Sign in and sign out, and open the dashboard from a phone over HTTPS.
- Create veterinarian/staff accounts and check their role access.
- Create a test client, pet, appointment, and medical record.
- Upload a pet photo, redeploy, and confirm the photo and database record remain.
- Verify QR links, receipts, email delivery, and password reset.
- Confirm `/vetrix.sql`, `/config/database.php`, and `/deploy/init-db.php` return 403.

Keep one web replica initially: PHP sessions are stored in the container, so
restarts can require users to sign in again. Uploaded files persist on the volume.
Configure database and upload backups before storing real clinic records.

## Troubleshooting

- **Missing environment variable:** add all database references to the web service.
- **Connection refused:** wait for MySQL and check the internal host/port references.
- **Incomplete schema:** review the database; initialization deliberately never drops existing tables.
- **Application failed to respond:** check deployment logs and domain target port 80.
- **Photos disappear:** check the web volume's exact mount path.

References: [Dockerfiles](https://docs.railway.com/builds/dockerfiles),
[MySQL](https://docs.railway.com/databases/mysql),
[volumes](https://docs.railway.com/volumes).
