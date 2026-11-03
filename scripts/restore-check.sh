#!/usr/bin/env sh
set -eu
backup_path="${1:?Usage: scripts/restore-check.sh backup.dump}"
# Always restore to a separate throwaway database, never to the live one.
restore_db="clinicflow_restore_$(date -u +%Y%m%d%H%M%S)"
docker compose exec -T database createdb -U clinicflow "$restore_db"
docker compose exec -T database pg_restore -U clinicflow -d "$restore_db" --no-owner --exit-on-error < "$backup_path"
docker compose exec -T database psql -U clinicflow -d "$restore_db" -v ON_ERROR_STOP=1 -c 'SELECT count(*) AS appointments FROM appointments; SELECT count(*) AS clinics FROM clinics; SELECT count(*) FROM appointments a JOIN patients p ON (p.clinic_id,p.id)=(a.clinic_id,a.patient_id);'
DATABASE_URL="postgresql://clinicflow:${POSTGRES_PASSWORD:-clinicflow_dev}@127.0.0.1:54329/$restore_db?serverVersion=16" php bin/console app:integrity:check
printf 'Restore verified in separate database %s (retained for inspection).\n' "$restore_db"
