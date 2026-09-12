# Railway email setup

Railway Trial, Free, and Hobby block SMTP. Use Brevo's HTTPS API for approvals,
OTP messages, password resets, and other messages through `send_app_email`.
Local installations retain SMTP by default.

1. Create a Brevo account and complete its account/transactional sending activation.
2. Add and verify a sender address in Brevo. Follow any domain authentication
   requirements shown by Brevo; owning a Railway subdomain does not grant DNS control.
3. Generate an API key in Brevo's SMTP & API settings, under API Keys (not SMTP).
4. Add these variables to Railway's **vetrix_webapp** service:

| Variable | Value |
| --- | --- |
| `VETRIX_MAIL_TRANSPORT` | `brevo` |
| `VETRIX_BREVO_API_KEY` | Your API key, entered privately in Railway |
| `VETRIX_MAIL_FROM` | The sender email you verified in Brevo |
| `VETRIX_MAIL_FROM_NAME` | `Vetrix` |
| `VETRIX_MAIL_ENABLED` | `true` |

5. Deploy variable changes. Retry approving the pending client.
6. Check the recipient inbox/spam folder and Brevo's transactional delivery logs.

API acceptance is not proof of inbox delivery. Sender restrictions, account
activation, provider quotas, and recipient bounces must be checked in Brevo.
Failed sends still prevent approval; OTP checks have not been bypassed.
No emails are sent merely by deploying this integration.

Never put API keys in source control, screenshots, or chat. Existing outbox
entries are not automatically retried. The live test requires provider credentials.

References:
- https://docs.railway.com/networking/outbound-networking
- https://developers.brevo.com/docs/send-a-transactional-email
