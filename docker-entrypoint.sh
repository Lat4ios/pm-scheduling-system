#!/bin/sh
set -eu

DATA_DIR="${PM_DATA_DIR:-/var/data}"
mkdir -p "$DATA_DIR"

# Never seed or overwrite production records from the Git repository.
# Existing JSON remains in place as the one-time migration source and rollback copy.
mkdir -p "$DATA_DIR/pm_user_photos"

# The application expects photos at /pm_user_photos, while the actual files
# live on the persistent disk.
if [ ! -e /var/www/html/pm_user_photos ]; then
  ln -s "$DATA_DIR/pm_user_photos" /var/www/html/pm_user_photos
fi

# Preserve the password hashes already present in the supplied dataset.
# No password reset or mutation is performed during deployment.

# Import the bundled GitHub seed dataset once. If PostgreSQL already has
# state, the importer deliberately skips it and never overwrites newer records.
php /var/www/html/database-bootstrap.php

# Configure the container clock for Ahmedabad, India (Asia/Kolkata).
TIMEZONE="${PM_TIMEZONE:-Asia/Kolkata}"
if [ -f "/usr/share/zoneinfo/$TIMEZONE" ]; then
  ln -snf "/usr/share/zoneinfo/$TIMEZONE" /etc/localtime
  printf '%s\n' "$TIMEZONE" > /etc/timezone
else
  echo "ERROR: Unknown PM_TIMEZONE: $TIMEZONE" >&2
  exit 1
fi

# Cron does not inherit the web process environment. Safely export only the
# settings needed by the reminder task into a root-only runtime file.
/usr/bin/bash -c '
  set -eu
  output=/usr/local/bin/pm-cron-env.sh
  printf "%s\n" "#!/usr/bin/env bash" > "$output"
  for name in PM_DATA_DIR PM_TIMEZONE DATABASE_URL BREVO_API_KEY BREVO_FROM_EMAIL BREVO_FROM_NAME; do
    printf "export %s=%q\n" "$name" "${!name-}" >> "$output"
  done
  chmod 700 "$output"
'

cat > /usr/local/bin/run-pm-reminders <<'RUNNER'
#!/bin/bash
set -euo pipefail
source /usr/local/bin/pm-cron-env.sh
export PM_RUN_REMINDERS=1
exec php /var/www/html/index.php
RUNNER
chmod 700 /usr/local/bin/run-pm-reminders

# Run every day at 08:00 Ahmedabad time. The container timezone is set above.
printf '%s\n' '0 8 * * * root /usr/local/bin/run-pm-reminders >> /var/data/pm-reminders.log 2>&1' > /etc/cron.d/pm-reminders
chmod 644 /etc/cron.d/pm-reminders
cron

chown -R www-data:www-data "$DATA_DIR"
exec "$@"
