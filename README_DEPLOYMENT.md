# PM Scheduling System — GitHub to Render deployment

## What this package does

- Includes the latest `pm_schedule_data.json` supplied for the initial migration.
- During container startup, imports that file into PostgreSQL only if `pm_app_state` is empty.
- Verifies the imported counts for equipment, logs, users, audit logs, auxiliary information, and checklist templates.
- If PostgreSQL already contains application data, the importer skips the seed and does not overwrite it.
- Preserves existing password hashes; deployment does not reset the ADMIN password.
- Keeps uploaded personnel photos on the existing Render persistent disk at `/var/data/pm_user_photos`.
- Runs reminder processing daily at 08:00 `Asia/Kolkata` (Ahmedabad time).
- Uses Brevo API settings from Render environment variables; no API key is included.

## CRITICAL DATA SECURITY WARNING

The bundled JSON contains employee/user records, password hashes, logs, and audit history. **Only use this package with a private GitHub repository that you control.** Do not use a public repository, publish a release, or share the ZIP. Password hashes are not plaintext passwords, but they are still sensitive. GitHub commit history retains the file even if it is deleted in a later commit. If your organization does not permit employee data in GitHub, do not use this GitHub-seed method; use a secure one-time data transfer instead.

## Deploy to your existing Render service

1. Download and extract this ZIP on your computer.
2. Back up the existing Render PostgreSQL database and the `/var/data` persistent disk before deploying. Do not delete or recreate either resource.
3. Confirm your GitHub repository is **private**.
4. Copy the extracted package contents into the root of the existing repository, replacing the matching application/deployment files. Keep unrelated repository files if they are needed by your workflow.
5. Confirm `pm_schedule_data.json` is present in the repository root.
6. Commit and push the files to the same branch connected to your existing Render web service.
7. Render should build and deploy automatically. Do not create a new Render Blueprint from `render.yaml` if you already have a working service and database; keep using your existing resources.
8. In Render, open the web service **Logs**. Look for `Initial JSON-to-PostgreSQL import completed` and the verified record counts. If the logs say PostgreSQL application data already exists and import was skipped, that is intentional protection against overwriting the database.
9. Open the website and test login using your existing credentials. Check equipment, PM logs, users, audit history, auxiliary information, checklist templates, and email settings.

## Render environment variables

Keep these on the existing web service:

| Variable | Value |
|---|---|
| `DATABASE_URL` | Render PostgreSQL **Internal Database URL** |
| `PM_DATA_DIR` | `/var/data` |
| `PM_TIMEZONE` | `Asia/Kolkata` |
| `BREVO_API_KEY` | Your Brevo API v3 key |
| `BREVO_FROM_EMAIL` | Your verified Brevo sender email |
| `BREVO_FROM_NAME` | `PM Scheduling System` |

Do not put database credentials or Brevo keys in GitHub. Do not add `PM_ADMIN_INITIAL_PASSWORD`; this package preserves the password hashes in the latest JSON.

## After the first successful import

For ongoing security, the preferred next step is to remove the seed JSON from the repository and from Git history (history rewriting is required; a normal delete commit is not enough), after confirming PostgreSQL has the full dataset and taking a backup. The Docker build and bootstrap will then need a small follow-up adjustment so later deploys no longer expect the seed file. Do not remove it before the successful import has been verified and backed up.

## Important limitations

- This is a one-time seed migration into a single JSONB row in PostgreSQL, preserving the application's existing data structure. It is not a normalized relational-table redesign.
- The importer is intentionally non-destructive: it never overwrites an existing PostgreSQL state. If a previous import partially succeeded, inspect the database and logs before trying again.
- A successful import log verifies stored JSON section counts; it does not replace functional testing of every application workflow.
- Keep one Render web-service instance because the app uses one shared application state document and a persistent disk for uploaded photos.
- Verify the Render PostgreSQL plan and expiry/backup policy in the dashboard.
