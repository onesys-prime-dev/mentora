# Sending email (SMTP)

Mentora emails the **sign-in code** a system administrator must enter after
their password, and later the onboarding invitations. Out of the box the app
uses PHP's `mail()`, which does nothing useful on a laptop or most servers.
Point it at a real mail account with SMTP.

## Settings

They go in the `.env` file of the machine (never in git). The `env` template in
the repo has the same block, commented out.

```ini
email.protocol = smtp
email.SMTPHost = smtp.gmail.com
email.SMTPPort = 587
email.SMTPCrypto = tls
email.SMTPUser = you@gmail.com
email.SMTPPass = your-16-letter-app-password
email.fromEmail = you@gmail.com
email.fromName = "Mentora LMS"
email.SMTPTimeout = 15
```

- `fromEmail` should be the same address as `SMTPUser`. Gmail and most
  providers rewrite or refuse any other sender.
- Port `587` with `tls` (STARTTLS) is the usual choice. Some providers use
  `465` with `email.SMTPCrypto = ssl`.
- Put the password in quotes if it contains `#` or spaces.

## Gmail

Gmail does not accept your normal password for SMTP. Use an **app password**:

1. Turn on 2-Step Verification for the account (Google Account > Security).
2. Open https://myaccount.google.com/apppasswords, create one named "Mentora".
3. Copy the 16 letters (spaces do not matter) into `email.SMTPPass`.

A personal Gmail account is fine for sign-in codes and a handful of invitations
(it limits you to a few hundred messages a day). For a real customer-facing
launch, switch to a transactional provider (Brevo, Mailgun, Amazon SES, ...)
with a sending domain; only the five lines above change.

## Check it works

```bash
php spark mail:test you@yourdomain.com
```

It prints the settings in use (the password only as "(set)"), sends one
message and says whether it was accepted. On a server use `php8.5 spark ...`.
If the message does not arrive, look in the spam folder first.

| It says | Usually means |
|---|---|
| `protocol is "mail"` | `email.protocol = smtp` is missing or the `.env` line is still commented out |
| `Unable to connect` / timeout | Wrong host or port, or the host blocks outgoing SMTP (some cloud hosts block port 25/587: ask the provider or use 465) |
| `Username and Password not accepted` | Not an app password, or 2-Step Verification is off |
| `Sender address rejected` | `fromEmail` differs from the account in `SMTPUser` |
| `STARTTLS` / certificate error | `SMTPCrypto` does not match the port (587 = `tls`, 465 = `ssl`) |

## During development

In the `development` environment the sign-in code is also written to
`writable/logs/`, and a failed send does not block signing in, so you can work
without mail. Elsewhere (staging, production) a failed send blocks the sign-in,
which is why `admin:create` warns when mail is not set up.
