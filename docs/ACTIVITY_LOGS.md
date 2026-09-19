# Structured activity logs

Activity Logs remain administrator-only. Expand a row using its summary (mouse,
Enter or Space) to view submitted data, result, recorded errors and optional
transfer information. All displayed data is HTML-escaped. Search includes the
JSON column as well as the original text summary.

## Database

`audit_logs.event_data JSON NULL` stores one event object with arrays and nested
objects. The original `details` text and existing rows are retained for reports
and existing queries. Earlier entries show their saved text and explicitly state
that submitted data and errors were not recorded; historical payloads are not
invented or backfilled.

For another installation, run before deploying the updated application:

```text
php migrations/20260916_activity_json.php
```

The CLI migration is idempotent and only adds the nullable column. It has been
applied locally after backing up `audit_logs` to
`artifacts/enterprise-qa/audit-before-json.sql`. The fresh-install schema in
`vetrix.sql` includes the column. The local MariaDB server represents JSON as
LONGTEXT with a `json_valid(event_data)` check, as verified with SHOW CREATE TABLE.

## Data captured

- Existing web `log_action` calls: method, route, query fields, submitted form
  fields, action summary/result and optional structured context.
- Authenticated web `flash('error', ...)`: submitted data and application error.
- Mobile API responses: submitted form/JSON data, response body, HTTP status,
  errors and transfer direction. The actual API response is unchanged.
- In-system notification writes: recipient, message, saved notification ID or
  storage error. “Saved” describes delivery to the database inbox, not an email
  delivery or read receipt.
- Product-order and POS audit entries: structured request and result information.
- Existing pet-update helper: supplied before/after values.
- Fatal errors after the audit helper loads: error type, basename and line; full
  stack traces and SQL text stay in the server error log.

Example context for an existing logger:

```php
log_action($conn, 'Updated record', 'pet', $id, 'Changes saved.', [
    'outcome' => 'success',
    'result' => ['record_id' => $id, 'changed_fields' => ['name', 'breed']],
    'errors' => [],
]);
```

The default outcome is `recorded`; a generic existing log call does not prove
success of the whole request. Errors appear as their own events when reported.
`No errors were recorded` means exactly that, not a guarantee that no issue
occurred outside the instrumented code.

Passwords, secrets, authorization/token fields, cookies, CSRF, OTP and
verification/confirmation codes are redacted recursively. Request headers and
uploaded file contents are not captured. Values, nesting and total JSON size
are bounded; omitted data is identified explicitly. The new event size limit
is 64 KB. Original text details and older log data retain their existing handling.

Logging cannot record a database outage into the same unavailable database.
Generic logger failures go to the server error log and do not replace an API
response or fail an otherwise completed operation. Existing transactional order
audit inserts keep their transaction behavior. This does not capture every
network packet, external provider response, or arbitrary nonfatal PHP warning.

## Verification

September 19 recheck: PHP syntax and database-independent checks passed. The live
database recheck was blocked by the current MySQL/InnoDB storage errors and
connection interruptions. Earlier successful database/browser results below are
from September 16; they are not a claim that the current database is healthy.

- `php tests/audit_events_unit.php`: validates payloads without a database.

- `php tests/audit_events.php`: uses a temporary table, leaving the real audit
  history intact; checks multi-value JSON, redaction, error recording, limits,
  legacy rendering, and HTML injection escaping.
- PHP syntax checks on changed files and idempotent migration rerun passed.
- Live rejected mobile requests retained their normal 405/401 responses and
  produced expandable error entries; JSON submitted fields and redaction verified.
- Browser checks passed for expansion, keyboard collapse, search, and 390px
  mobile layout. The verification requests appear in the real audit history.
