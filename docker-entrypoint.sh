#!/bin/sh
set -eu

DATA_DIR="${PM_DATA_DIR:-/var/data}"
mkdir -p "$DATA_DIR"

# Seed the persistent disk once. Later deploys never overwrite live records.
if [ ! -f "$DATA_DIR/pm_schedule_data.json" ]; then
  if [ ! -f /opt/pmsched-seed/pm_schedule_data.json ]; then
    echo "ERROR: Initial PM schedule JSON seed is missing." >&2
    exit 1
  fi
  cp /opt/pmsched-seed/pm_schedule_data.json "$DATA_DIR/pm_schedule_data.json"
fi

if [ ! -d "$DATA_DIR/pm_user_photos" ]; then
  if [ -d /opt/pmsched-seed/pm_user_photos ]; then
    cp -a /opt/pmsched-seed/pm_user_photos "$DATA_DIR/pm_user_photos"
  else
    mkdir -p "$DATA_DIR/pm_user_photos"
  fi
fi

# The application expects photos at /pm_user_photos, while the actual files
# live on the persistent disk.
if [ ! -e /var/www/html/pm_user_photos ]; then
  ln -s "$DATA_DIR/pm_user_photos" /var/www/html/pm_user_photos
fi

# Replace the known default admin password exactly once, before first launch.
# The secret is supplied through Render's environment settings, never Git.
if [ ! -f "$DATA_DIR/.admin-password-initialized" ]; then
  if [ -z "${PM_ADMIN_INITIAL_PASSWORD:-}" ]; then
    echo "ERROR: Set PM_ADMIN_INITIAL_PASSWORD in Render before first deployment." >&2
    exit 1
  fi
  if [ "${#PM_ADMIN_INITIAL_PASSWORD}" -lt 12 ]; then
    echo "ERROR: PM_ADMIN_INITIAL_PASSWORD must be at least 12 characters." >&2
    exit 1
  fi
  PM_DATA_DIR="$DATA_DIR" php -r '
    $path = getenv("PM_DATA_DIR") . "/pm_schedule_data.json";
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $found = false;
    if (!isset($data["users"]) || !is_array($data["users"])) { fwrite(STDERR, "ERROR: User records are missing.\n"); exit(1); }
    foreach ($data["users"] as &$user) {
      if (strtoupper((string)($user["employee_no"] ?? "")) === "ADMIN" && ($user["role"] ?? "") === "admin") {
        $user["password_hash"] = password_hash((string)getenv("PM_ADMIN_INITIAL_PASSWORD"), PASSWORD_DEFAULT);
        $found = true;
        break;
      }
    }
    unset($user);
    if (!$found) { fwrite(STDERR, "ERROR: No ADMIN account found in seeded data.\n"); exit(1); }
    $tmp = $path . ".tmp";
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $path);
  '
  touch "$DATA_DIR/.admin-password-initialized"
fi

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
  for name in PM_DATA_DIR PM_TIMEZONE BREVO_API_KEY BREVO_FROM_EMAIL BREVO_FROM_NAME; do
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
