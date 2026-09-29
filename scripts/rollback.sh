#!/bin/bash

set -e

DEPLOY_ENV=${1:-production}
# 允许通过环境变量覆盖部署根目录（CI 与脚本目录命名不一致时使用）
DEPLOY_DIR=${DEPLOY_DIR:-/var/www/${DEPLOY_ENV}.muucmf.cc}
CURRENT_DIR="${DEPLOY_DIR}/current"
TARGET_RELEASE=${2:-""}

echo "========================================="
echo "MuuCmf T6 Rollback Script"
echo "Environment: ${DEPLOY_ENV}"
echo "========================================="

if [ -z "$TARGET_RELEASE" ]; then
    echo "Finding previous release..."
    TARGET_RELEASE=$(ls -t "${DEPLOY_DIR}/releases" | head -2 | tail -1)
fi

if [ -z "$TARGET_RELEASE" ]; then
    echo "Error: No previous release found"
    exit 1
fi

TARGET_RELEASE_PATH="${DEPLOY_DIR}/releases/${TARGET_RELEASE}"

if [ ! -d "$TARGET_RELEASE_PATH" ]; then
    echo "Error: Target release not found: $TARGET_RELEASE_PATH"
    exit 1
fi

echo "Rolling back to release: $TARGET_RELEASE"
echo "Path: $TARGET_RELEASE_PATH"

# 非交互场景（CI 通过 ssh 调用时无 TTY）跳过确认；交互式执行保留二次确认
if [ -t 0 ]; then
    read -p "Are you sure you want to rollback? (yes/no): " confirm
    if [ "$confirm" != "yes" ]; then
        echo "Rollback cancelled"
        exit 0
    fi
fi

echo "[1/3] Switching to previous release..."
ln -snf "$TARGET_RELEASE_PATH" "$CURRENT_DIR"

echo "[2/3] Notifying queue workers to restart..."
cd "$CURRENT_DIR"
# 不使用 cache:clear：TP6 未内置该命令，且 redis 驱动的 clear() 等价 flushDB，
# 会连带清空 session 与队列重启信号。
php think queue:restart || true

echo "[3/3] Restarting services..."
systemctl restart php-fpm || true
systemctl restart nginx || true

echo "========================================="
echo "Rollback completed successfully!"
echo "Current release: $TARGET_RELEASE"
echo "========================================="
