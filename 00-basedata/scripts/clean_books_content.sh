#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-$(cd "$(dirname "$0")" && pwd)}"
DEFAULT_SQL="${ROOT}/clean_books_content.sql"

echo "This will clean the catalog content in the local database but leaves SystemInfo, AuthEvents and UserPreferences unchanged."
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

  read -r -p "Database name [books]: " db_name
  db_name="${db_name:-books}"

  read -r -p "Cleanup SQL file [${DEFAULT_SQL}]: " loc_cleansql
  loc_cleansql="${loc_cleansql:-$DEFAULT_SQL}"

  if [[ ! -f "$loc_cleansql" ]]; then
    echo "ERROR: Cleanup SQL file not found: $loc_cleansql" >&2
    exit 1
  fi

  echo "Cleaning content tables in database '${db_name}' on ${db_host}:${db_port} ..."
  # -p prompts securely; no password in process args
  mysql -h "$db_host" -P "$db_port" -u "$db_user" -p "$db_name" < "$loc_cleansql"
else
  echo "mysql client not found; cannot clean DB content."
  exit 1
fi

echo "Catalog content cleaned."

