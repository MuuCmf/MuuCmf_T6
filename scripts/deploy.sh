#!/bin/bash

set -e

DEPLOY_ENV=${1:-staging}
# 允许通过环境变量覆盖部署根目录（CI 与脚本目录命名不一致时使用）
DEPLOY_DIR=${DEPLOY_DIR:-/var/www/${DEPLOY_ENV}.muucmf.cc}
BACKUP_DIR="${DEPLOY_DIR}/backups/$(date +%Y%m%d_%H%M%S)"
RELEASE_DIR="${DEPLOY_DIR}/releases/$(date +%Y%m%d_%H%M%S)"
CURRENT_DIR="${DEPLOY_DIR}/current"
# 共享 .env：放在部署根目录（不在 releases 内），避免每次换软链后丢配置
SHARED_ENV="${DEPLOY_DIR}/.env"
PACKAGE_FILE=${2:-""}

echo "========================================="
echo "MuuCmf T6 Deployment Script"
echo "Environment: ${DEPLOY_ENV}"
echo "========================================="

if [ -z "$PACKAGE_FILE" ]; then
    echo "Error: Package file not specified"
    echo "Usage: $0 <environment> <package_file>"
    exit 1
fi

if [ ! -f "$PACKAGE_FILE" ]; then
    echo "Error: Package file not found: $PACKAGE_FILE"
    exit 1
fi

echo "[1/7] Creating backup..."
mkdir -p "$BACKUP_DIR"
if [ -L "$CURRENT_DIR" ]; then
    cp -r "$(readlink -f $CURRENT_DIR)" "$BACKUP_DIR/" || true
    echo "Backup created at: $BACKUP_DIR"
else
    echo "Warning: No current release to backup"
fi

echo "[2/7] Creating release directory..."
mkdir -p "$RELEASE_DIR"

echo "[3/7] Extracting package..."
tar -xzf "$PACKAGE_FILE" -C "$RELEASE_DIR"

echo "[4/7] Copying environment file..."
# 优先使用包内的 .env.<env>（仅用于不含密钥的场景）；
# 否则回退到部署根目录的共享 .env（推荐：密钥不入包，且换软链不丢配置）
if [ -f "${RELEASE_DIR}/.env.${DEPLOY_ENV}" ]; then
    cp "${RELEASE_DIR}/.env.${DEPLOY_ENV}" "${RELEASE_DIR}/.env"
    echo "Environment file copied from package (.env.${DEPLOY_ENV})"
elif [ -f "$SHARED_ENV" ]; then
    cp "$SHARED_ENV" "${RELEASE_DIR}/.env"
    echo "Environment file copied from shared env (${SHARED_ENV})"
else
    echo "Error: 未找到 .env.<env> 或共享 ${SHARED_ENV}，缺少 .env 的版本无法启动"
    echo "请在 ${SHARED_ENV} 准备应用配置（可参考 .env.production.example）"
    exit 1
fi

echo "[5/7] Installing dependencies..."
cd "$RELEASE_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

echo "[6/7] Notifying queue workers to restart..."
# 项目未引入 think-migration，数据库升级由 data/upgrade.sql 承担（由后台在线更新流程执行），
# 因此这里不再调用 migrate:run。同样不再调用 cache:clear：
# TP6 未内置该命令，且 redis 驱动的 clear() 等价于 flushDB，会连带清空 session 与队列重启信号。
php think queue:restart || true

echo "[7/7] Switching to new release..."
ln -snf "$RELEASE_DIR" "$CURRENT_DIR"

echo "Setting permissions..."
chmod -R 755 "${RELEASE_DIR}/runtime"
chmod -R 755 "${RELEASE_DIR}/public/attachment"
chown -R www-data:www-data "$DEPLOY_DIR"

echo "========================================="
echo "Deployment completed successfully!"
echo "Release directory: $RELEASE_DIR"
echo "Current link: $CURRENT_DIR -> $(readlink -f $CURRENT_DIR)"
echo "========================================="

echo "Cleaning old releases (keeping last 5)..."
cd "${DEPLOY_DIR}/releases"
ls -t | tail -n +6 | xargs -r rm -rf

echo "Cleaning old backups (keeping last 10)..."
cd "${DEPLOY_DIR}/backups"
ls -t | tail -n +11 | xargs -r rm -rf

echo "========================================="
echo "Deployment finished!"
echo "========================================="
