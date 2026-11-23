# Preventive Maintenance Electronic System — Render deployment

## What is included

- Existing PM application (`PMsched.php`), published as `index.php` inside the container.
- Existing `pm_schedule_data.json` and personnel photos as the one-time seed.
- Docker/Apache/PHP 8.4 runtime for Render.
- Persistent storage mounted at `/var/data`.
- Existing data is copied to the disk only on its first boot; subsequent deploys do not overwrite live records.
- CSRF tokens are added to POST forms and the schedule-frequency AJAX request.
- The company timezone remains `Asia/Manila` by default and can be changed with `PM_TIMEZONE`.

## Important production notes

This is a deployment-ready packaging baseline, not a claim that the application has undergone a full independent security or load audit. The existing app uses a single JSON file, so keep one web-service instance and do not scale horizontally. Render's attached disk is single-instance storage. Schedule regular off-platform backups of `/var/data/pm_schedule_data.json` and `/var/data/pm_user_photos/`.

Before inviting employees:

1. Set `PM_ADMIN_INITIAL_PASSWORD` in Render to a unique password of at least 12 characters. The entrypoint hashes it into the existing ADMIN account once and records a marker on the persistent disk. Do not commit this password to Git.
2. Sign in as employee number `ADMIN`, then change the password again through the application's profile/admin password controls. After confirming the new login works, remove `PM_ADMIN_INITIAL_PASSWORD` from Render Environment settings; the one-time marker means future boots do not need it.
3. Confirm all imported users are intended to have access. The included JSON contains existing employee/user records and audit history.
4. Confirm the company timezone. The current code's intended timezone is Philippine Time (`Asia/Manila`).
5. Configure the Brevo transactional email API in Render using the variables below. The application sends email over HTTPS (port 443), not SMTP.
6. Test signup, approval, login, PM completion, callback, schedule changes, equipment/checklist edits, reports, and photo uploads on the deployed service before production rollout.

## Brevo API email configuration

This package sends transactional emails through Brevo API v3 over HTTPS using PHP cURL. No API key is stored in the repository.

Render environment variables:

| Variable | Value |
|---|---|
| `BREVO_API_KEY` | A Brevo API v3 key (not the SMTP key) |
| `BREVO_FROM_EMAIL` | A sender email verified in Brevo |
| `BREVO_FROM_NAME` | `PM Scheduling System` |

The Blueprint prompts for the API key and verified sender email. Never commit the API key to GitHub. If a key was exposed, revoke it in Brevo and generate a replacement before deployment.

The application sends the existing daily PM due-list notification to approved users who have enabled email notifications. The admin **Send Due Email Now** action forces a send attempt. A message is counted as sent only when Brevo accepts the API request; check Render logs and Brevo transactional logs for delivery/bounce status. An API acceptance response does not guarantee inbox placement.

## Deploy to Render

1. Create a private GitHub repository and upload the contents of this folder to its root. Do not publish the repository publicly because it contains employee records and photos.
2. In Render, choose **New → Blueprint** and connect the private repository. Render reads `render.yaml`.
3. When prompted, set `PM_ADMIN_INITIAL_PASSWORD` to a unique password (at least 12 characters). Never use `Admin@123`.
4. Create the service. The Blueprint configures a paid Starter web service in Singapore and a 1 GB persistent disk at `/var/data`.
5. Wait for the deploy to become Live, open the generated `onrender.com` URL, and sign in with employee number `ADMIN` and the password you supplied.
6. Change the administrator password again inside the app, verify the records, and test all critical workflows.

## Email testing

1. In Brevo, verify the sender address you plan to use and create an API v3 key. Do not use the SMTP key.
2. In Render, set `BREVO_API_KEY` and `BREVO_FROM_EMAIL`.
3. Deploy/redeploy the service after setting the variables.
4. Sign in as an administrator and use **Send Brevo API Test Email to Admin**. This test does not depend on PM items being due.
5. After the test succeeds, use **Send Due Email Now** to test scheduled notifications when there are due/overdue items and eligible users.
6. Check the admin result, Render service logs, and Brevo transactional email logs.

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

## Email configuration and testing

Set these Render environment variables on the web service:

- `BREVO_API_KEY` = a Brevo API v3 key (not an SMTP key)
- `BREVO_FROM_EMAIL` = a sender address verified in Brevo
- `BREVO_FROM_NAME=PM Scheduling System`
- `PM_TIMEZONE=Asia/Manila`

After deploying, sign in as Admin and open the Admin page. Use **Send Brevo API Test Email to Admin** to send a test message to the email address saved in the Admin profile. This test does not depend on PM items being due. If it fails, the application displays a diagnostic message and writes details to the Render service logs. Do not share API keys in screenshots or chat.

Automatic PM due-list emails are separate from the Brevo API test. They are sent only when an open PM item is due within two days or overdue, and only to approved employees with a valid email address and the email-notification preference enabled.


## Automated PM reminder emails (Ahmedabad time)

The container runs a daily cron task at **08:00 Asia/Kolkata (Ahmedabad, India)**. It runs independently of user logins and checks the persistent PM schedule data file. It sends separate transactional email reminders through the Brevo HTTPS API:

- 7 calendar days before each incomplete PM task's due date
- 2 calendar days before each incomplete PM task's due date
- On the PM task's due date

Only approved users with email notifications enabled and a valid email address receive reminders. Successful deliveries are recorded per recipient, equipment item, due date, and reminder stage to prevent duplicate sends. Failed API attempts are logged and are not marked as delivered, allowing a later manual rerun; the daily task itself checks the exact reminder day.

### Required Render environment variables

Set these on the existing Render web service under **Environment**:

- `PM_DATA_DIR=/var/data`
- `PM_TIMEZONE=Asia/Kolkata`
- `BREVO_API_KEY` = the regular Brevo API key (not an SMTP key or MCP key)
- `BREVO_FROM_EMAIL` = a verified sender address in Brevo
- `BREVO_FROM_NAME=PM Scheduling System`

Keep the existing persistent disk mounted at `/var/data`. Do not create a separate Render Cron Job: the daily cron daemon is included in the same web-service container so it can access the same mounted persistent disk. The schedule is **08:00 India Standard Time (UTC+05:30)** daily.

### Verify the scheduler

After deployment, inspect the Render service logs for cron output. The task appends its run results to `/var/data/pm-reminders.log`. The cron task starts with the web container and does not require an administrator to log in. A running web service is required for the scheduled task to execute.
