# Preventive Maintenance Electronic System — Render deployment

## What is included

- Existing PM application (`PMsched.php`), published as `index.php` inside the container.
- Existing `pm_schedule_data.json` and personnel photos as the one-time seed.
- Docker/Apache/PHP 8.4 runtime for Render.
- Persistent storage mounted at `/var/data`.
- Existing data is copied to the disk only on its first boot; subsequent deploys do not overwrite live records.
- CSRF tokens are added to POST forms and the schedule-frequency AJAX request.
- The company timezone remains `Asia/Kolkata` by default and can be changed with `PM_TIMEZONE`.

## Important production notes

This is a deployment-ready packaging baseline, not a claim that the application has undergone a full independent security or load audit. The existing app uses a single JSON file, so keep one web-service instance and do not scale horizontally. Render's attached disk is single-instance storage. Schedule regular off-platform backups of `/var/data/pm_schedule_data.json` and `/var/data/pm_user_photos/`.

Before inviting employees:

1. Set `PM_ADMIN_INITIAL_PASSWORD` in Render to a unique password of at least 12 characters. The entrypoint hashes it into the existing ADMIN account once and records a marker on the persistent disk. Do not commit this password to Git.
2. Sign in as employee number `ADMIN`, then change the password again through the application's profile/admin password controls. After confirming the new login works, remove `PM_ADMIN_INITIAL_PASSWORD` from Render Environment settings; the one-time marker means future boots do not need it.
3. Confirm all imported users are intended to have access. The included JSON contains existing employee/user records and audit history.
4. Confirm the company timezone. The current code's intended timezone is India Standard Time (`Asia/Kolkata`).
5. Configure Brevo SMTP in Render using the variables below. The application now sends due notifications through PHPMailer and Brevo SMTP; notifications will fail safely and be logged if SMTP is not configured.
6. Test signup, approval, login, PM completion, callback, schedule changes, equipment/checklist edits, reports, and photo uploads on the deployed service before production rollout.

## Brevo SMTP email configuration

This package uses PHPMailer with Brevo SMTP. The Docker build installs PHPMailer from `composer.json`; no SMTP password or API key is stored in the repository.

Render environment variables:

| Variable | Value |
|---|---|
| `SMTP_HOST` | `smtp-relay.brevo.com` |
| `SMTP_PORT` | `587` |
| `SMTP_ENCRYPTION` | `tls` |
| `SMTP_USERNAME` | Brevo SMTP login shown in Brevo SMTP settings |
| `SMTP_PASSWORD` | A newly generated Brevo SMTP key (not the Brevo API key) |
| `SMTP_FROM_EMAIL` | A sender email verified in Brevo |
| `SMTP_FROM_NAME` | `PM Scheduling System` |

The Blueprint preconfigures the host, port, encryption, and sender name. Render prompts for SMTP username, SMTP password, and sender email because those are private/account-specific values. Never commit SMTP credentials to GitHub. If an SMTP key was exposed, revoke it in Brevo and generate a replacement before deployment.

The application sends the existing daily PM due-list notification to approved users who have enabled email notifications. The admin **Send Due Email Now** action forces a send attempt. A message is counted as sent only when the SMTP server accepts it; check Render logs and Brevo logs for delivery/bounce status. A successful SMTP handoff does not guarantee inbox placement.

## Deploy to Render

1. Create a private GitHub repository and upload the contents of this folder to its root. Do not publish the repository publicly because it contains employee records and photos.
2. In Render, choose **New → Blueprint** and connect the private repository. Render reads `render.yaml`.
3. When prompted, set `PM_ADMIN_INITIAL_PASSWORD` to a unique password (at least 12 characters). Never use `Admin@123`.
4. Create the service. The Blueprint configures a paid Starter web service in Singapore and a 1 GB persistent disk at `/var/data`.
5. Wait for the deploy to become Live, open the generated `onrender.com` URL, and sign in with employee number `ADMIN` and the password you supplied.
6. Change the administrator password again inside the app, verify the records, and test all critical workflows.

## Email testing

1. In Brevo, verify the sender address you plan to use and generate an SMTP key. Use the SMTP login and SMTP key, not the API key.
2. In Render, confirm all `SMTP_*` environment variables are populated without spaces or quotation marks around the secret.
3. Deploy/redeploy the service after setting the variables.
4. Sign in as an administrator and use **Send Due Email Now** when the current work week has due/overdue equipment and approved users have enabled email notifications.
5. Check the admin result, Render service logs, and Brevo transactional email logs. If there are no due items or no eligible recipients, no email will be sent.

## Backups and updates

- Back up the JSON file and photo directory before every code deployment that changes data-handling behavior.
- The disk survives normal deploys/restarts, but it is not a substitute for independent backups.
- Keep the service at one instance while it uses JSON file storage.
- Code updates are deployed by pushing to the connected Git branch. Never replace the persistent JSON file with the repository copy after initial seeding.

## Local Docker test

```bash
docker build -t pmsched .
docker run --rm -p 10000:10000 \
  -e PM_ADMIN_INITIAL_PASSWORD='Use-A-Unique-Password-Here' \
  -e PM_DATA_DIR=/var/data \
  -v pmsched-data:/var/data \
  pmsched
```

Open http://localhost:10000.
