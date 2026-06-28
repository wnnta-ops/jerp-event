#!/usr/bin/env bash
# Backup SQLite Java Core System (konsisten, pakai .backup; aman saat app jalan).
# Pasang cron tiap jam:
#   crontab -e
#   0 * * * * /var/www/javacore/deploy/backup-sqlite.sh >/dev/null 2>&1
set -euo pipefail

DB=/var/www/javacore/database/database.sqlite
DEST=/var/www/javacore/storage/backups
KEEP=48   # simpan 48 backup terakhir (≈ 2 hari bila per jam)

mkdir -p "$DEST"
TS=$(date +%Y%m%d-%H%M%S)
sqlite3 "$DB" ".backup '$DEST/db-$TS.sqlite'"
gzip -f "$DEST/db-$TS.sqlite"

# buang yang lama
ls -1t "$DEST"/db-*.sqlite.gz 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
echo "[$(date)] backup OK → $DEST/db-$TS.sqlite.gz"
