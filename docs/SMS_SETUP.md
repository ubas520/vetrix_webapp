# SMSGate Cloud

SMS delivery uses SMSGate Cloud Server and the Android phone's SIM. The connected
SIM900A is not required for sending.

Set username/password from the Android app's Cloud Server panel in
config/sms.local.php, set enabled to true, and keep the base URL
https://api.sms-gate.app. Keep credentials private. The Android service must be
running with SMS permission and an active SIM/SMS plan.

Keep XAMPP running with PHP cURL enabled and internet access. SMS uses the cloud
directly; no Arduino, COM port, or GSM monitor is required. Disconnecting local
GSM hardware does not block sending. The Android SMSGate service still needs
internet access and its phone's working SIM. Missing cloud credentials or a
disabled SMS configuration prevents sending.

There is no separate SMS Integration page or manual recipient form. Appointment
and vaccination notifications automatically attempt SMS using the recipient's
current users.phone value. This applies to web and mobile notification helpers.
Only active/approved client accounts are eligible; no SMS is sent merely on login.
Other notification types and email/OTP delivery are unchanged. In-app notifications
still work if SMS is blocked. No additional scheduled reminder job is created.

To test, save a valid Philippine number on a test client's profile, then use the
existing appointment update or vaccination reminder action for that client.
Check the Android Messages tab and receiving phone. API acceptance does not prove
delivery. Failed attempts are logged but are not automatically replayed.

Run C:\xampp\php\php.exe tests\sms_gate.php for mock tests; these send no SMS.

Provider documentation: https://docs.sms-gate.app/getting-started/public-cloud-server/
