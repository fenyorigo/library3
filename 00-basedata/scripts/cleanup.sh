#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-$(cd "$(dirname "$0")" && pwd)}"

echo "This will drop the local database and remove local data/build/config files."
read -r -p "Continue? (y/N): " confirm
confirm="${confirm:-n}"
if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
  echo "Aborted."
  exit 0
fi

if command -v mysql >/dev/null 2>&1; then
  read -r -p "MySQL host [127.0.0.1]: " db_host
  db_host="${db_host:-127.0.0.1}"

  read -r -p "MySQL port [3306]: " db_port
  db_port="${db_port:-3306}"

  read -r -p "MySQL admin user [root]: " db_user
  db_user="${db_user:-root}"

  read -r -s -p "MySQL admin password: " db_pass
  echo ""

  read -r -p "Database name [books]: " db_name
  db_name="${db_name:-books}"

  echo "Dropping database if it exists: ${db_name}"
  MYSQL_PWD="$db_pass" mysql -h "$db_host" -P "$db_port" -u "$db_user" \
    -e "DROP DATABASE IF EXISTS \`$db_name\`;"
else
  echo "mysql client not found; skipping DB drop."
fi

echo "Removing local data/build/config files..."
rm -rf "${ROOT}/public/uploads"
rm -rf "${ROOT}/public/user-assets"
rm -rf "${ROOT}/public/dist"
rm -f "${ROOT}/config.php"

echo "Cleanup complete."
