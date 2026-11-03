#!/usr/bin/env sh
set -eu
# Caller selects a path outside the public webroot and applies its retention policy.
backup_path="${1:-var/backups/clinicflow-$(date -u +%Y%m%d-%H%M%S).dump}"
mkdir -p "$(dirname "$backup_path")"
docker compose exec -T database pg_dump -U clinicflow -d clinicflow -Fc > "$backup_path"
chmod 600 "$backup_path"
printf 'Backup saved: %s\n' "$backup_path"
