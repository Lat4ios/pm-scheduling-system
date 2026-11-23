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

chown -R www-data:www-data "$DATA_DIR"
exec "$@"
