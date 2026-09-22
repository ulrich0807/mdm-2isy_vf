#!/usr/bin/env bash
set -euo pipefail

: "${BACKUP_DIR:?BACKUP_DIR est obligatoire}"
: "${DB_HOST:?DB_HOST est obligatoire}"
: "${DB_DATABASE:?DB_DATABASE est obligatoire}"
: "${DB_USERNAME:?DB_USERNAME est obligatoire}"
: "${DB_PASSWORD:?DB_PASSWORD est obligatoire}"

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
work_dir="$(mktemp -d)"
archive="$BACKUP_DIR/mdm-2isy-$timestamp.tar.gz"
trap 'rm -rf -- "$work_dir"' EXIT

mkdir -p "$BACKUP_DIR" "$work_dir/private-storage"
MYSQL_PWD="$DB_PASSWORD" mysqldump \
  --host="$DB_HOST" \
  --port="${DB_PORT:-3306}" \
  --user="$DB_USERNAME" \
  --single-transaction \
  --routines \
  --triggers \
  "$DB_DATABASE" > "$work_dir/database.sql"

if [[ -d "$project_dir/mdm-2isy-api/storage/app/private" ]]; then
  cp -a "$project_dir/mdm-2isy-api/storage/app/private/." "$work_dir/private-storage/"
fi

tar -czf "$archive" -C "$work_dir" database.sql private-storage
sha256sum "$archive" > "$archive.sha256"
echo "Sauvegarde créée : $archive"
