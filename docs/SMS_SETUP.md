# SMSGate with GSM detection

SMS delivery uses SMSGate Cloud Server and the Android phone's SIM. The connected
SIM900A enables sending but does not send these texts.

Set username/password from the Android app's Cloud Server panel in
config/sms.local.php, set enabled to true, and keep the base URL
https://api.sms-gate.app. Keep credentials private. The Android service must be
running with SMS permission and an active SIM/SMS plan.

Keep XAMPP and the Arduino running. Close Arduino Serial Monitor. If the GSM
monitor is not already running, start it from the project folder:

powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\watch-gsm.ps1 -Port COM5 -BaudRate 115200

Every request is gated on the server. USB removal is polled every 250 ms, monitor
heartbeats expire after two seconds, and modem responses after 12 seconds.
Reconnection requires a fresh response from the existing diagnostic sketch.
Missing/busy ports, stale status, and missing credentials block new sending.
Already accepted texts may arrive after unplugging; blocked attempts are not replayed.
The monitor reads CSQ/CREG lines, not tamper-proof hardware attestation.
PHP and the monitor must share this Windows workspace.

There is no separate SMS Integration page or manual recipient form. Appointment
and vaccination notifications automatically attempt SMS using the recipient's
current users.phone value. This applies to web and mobile notification helpers.
Only active/approved client accounts are eligible; no SMS is sent merely on login.
Other notification types and email/OTP delivery are unchanged. In-app notifications
still work if SMS is blocked. No additional scheduled reminder job is created.

To test, save a valid Philippine number on a test client's profile, then use the
existing appointment update or vaccination reminder action for that client.
Check the Android Messages tab and receiving phone. API acceptance does not prove
delivery. Blocked reminders are logged but are not replayed upon reconnecting.

Run C:\xampp\php\php.exe tests\sms_gate.php for mock tests; these send no SMS.

Provider documentation: https://docs.sms-gate.app/getting-started/public-cloud-server/
