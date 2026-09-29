#!/bin/bash

set -e

DEPLOY_ENV=${1:-production}
# 允许通过环境变量覆盖部署根目录（CI 与脚本目录命名不一致时使用）
DEPLOY_DIR=${DEPLOY_DIR:-/var/www/${DEPLOY_ENV}.muucmf.cc}
BACKUP_DIR="${DEPLOY_DIR}/backups"
DB_USER=${2:-root}
DB_PASS=${3:-""}
DB_NAME=${4:-muucmf}

echo "========================================="
echo "MuuCmf T6 Backup Script"
echo "Environment: ${DEPLOY_ENV}"
echo "========================================="

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_PATH="${BACKUP_DIR}/${TIMESTAMP}"
mkdir -p "$BACKUP_PATH"

echo "[1/4] Backing up files..."
if [ -L "${DEPLOY_DIR}/current" ]; then
    CURRENT_PATH=$(readlink -f "${DEPLOY_DIR}/current")
    cp -r "$CURRENT_PATH" "${BACKUP_PATH}/files"
    echo "Files backed up to: ${BACKUP_PATH}/files"
else
    echo "Warning: No current release to backup"
fi

echo "[2/4] Backing up database..."
if [ -n "$DB_PASS" ]; then
    mysqldump -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" > "${BACKUP_PATH}/db_backup.sql"
else
    mysqldump -u"$DB_USER" "$DB_NAME" > "${BACKUP_PATH}/db_backup.sql"
fi
echo "Database backed up to: ${BACKUP_PATH}/db_backup.sql"

echo "[3/4] Backing up attachments..."
if [ -d "${DEPLOY_DIR}/current/public/attachment" ]; then
    cp -r "${DEPLOY_DIR}/current/public/attachment" "${BACKUP_PATH}/"
    echo "Attachments backed up to: ${BACKUP_PATH}/attachment"
fi

echo "[4/4] Compressing backup..."
cd "$BACKUP_DIR"
tar -czf "backup_${TIMESTAMP}.tar.gz" "$TIMESTAMP"
rm -rf "$TIMESTAMP"

echo "========================================="
echo "Backup completed successfully!"
echo "Backup file: ${BACKUP_DIR}/backup_${TIMESTAMP}.tar.gz"
echo "Size: $(du -h ${BACKUP_DIR}/backup_${TIMESTAMP}.tar.gz | cut -f1)"
echo "========================================="

echo "Cleaning old backups (keeping last 30)..."
cd "$BACKUP_DIR"
ls -t backup_*.tar.gz | tail -n +31 | xargs -r rm -f

echo "========================================="
echo "Backup finished!"
echo "========================================="
