#!/bin/bash
#
# MuuCmf T6 服务器侧健康检查
#
# 在部署服务器上执行（供 CI 通过 ssh 调用或运维手动执行）。
# 注意：项目未定义 /health 路由，因此直接探站点根；DB/Redis 地址读当前版本 .env，
# 与运行时保持一致（不再依赖不存在的 `php think db:check` / `redis:check`）。
#
set -e

DEPLOY_ENV=${1:-production}
# 允许通过环境变量覆盖部署根目录（CI 与脚本目录命名不一致时使用）
DEPLOY_DIR=${DEPLOY_DIR:-/var/www/${DEPLOY_ENV}.muucmf.cc}
CURRENT_DIR="${DEPLOY_DIR}/current"

# 站点入口地址
case "$DEPLOY_ENV" in
    production) SITE_URL="https://www.muucmf.cc/" ;;
    staging)    SITE_URL="https://staging.muucmf.cc/" ;;
    *)          SITE_URL="https://${DEPLOY_ENV}.muucmf.cc/" ;;
esac

echo "========================================="
echo "MuuCmf T6 Health Check Script"
echo "Environment: ${DEPLOY_ENV}"
echo "Deploy dir:  ${DEPLOY_DIR}"
echo "========================================="

echo "Checking site root: ${SITE_URL}"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$SITE_URL" || echo "000")

# 接受 2xx / 3xx
if [ "$HTTP_CODE" = "000" ] || [ "$HTTP_CODE" -lt 200 ] || [ "$HTTP_CODE" -ge 400 ]; then
    echo "✗ Site unreachable (HTTP ${HTTP_CODE})"
    echo "========================================="
    exit 1
fi

echo "✓ Site reachable (HTTP ${HTTP_CODE})"

if [ ! -d "$CURRENT_DIR" ]; then
    echo "✗ Current release not found: ${CURRENT_DIR}"
    exit 1
fi

cd "$CURRENT_DIR"

echo "Checking database connection..."
DB_RESULT=$(php -r '
    $env = parse_ini_file(".env", true, INI_SCANNER_RAW) ?: [];
    $db  = $env["DATABASE"] ?? [];
    $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s",
        $db["HOSTNAME"] ?? "127.0.0.1",
        $db["HOSTPORT"] ?? "3306",
        $db["DATABASE"] ?? ""
    );
    try {
        new PDO($dsn, $db["USERNAME"] ?? "root", $db["PASSWORD"] ?? "");
        echo "OK";
    } catch (Throwable $e) {
        echo "FAIL";
    }
' 2>/dev/null || echo "FAIL")

if [ "$DB_RESULT" = "OK" ]; then
    echo "✓ Database connection OK"
else
    echo "✗ Database connection failed"
    exit 1
fi

echo "Checking Redis connection..."
REDIS_RESULT=$(php -r '
    if (!extension_loaded("redis")) {
        echo "SKIP";
        exit;
    }
    $env   = parse_ini_file(".env", true, INI_SCANNER_RAW) ?: [];
    $redis = $env["REDIS"] ?? [];
    try {
        $client = new Redis();
        $client->connect($redis["HOST"] ?? "127.0.0.1", (int) ($redis["PORT"] ?? 6379), 2.0);
        if (($redis["password"] ?? "") !== "") {
            $client->auth($redis["password"]);
        }
        echo "OK";
    } catch (Throwable $e) {
        echo "FAIL";
    }
' 2>/dev/null || echo "FAIL")

case "$REDIS_RESULT" in
    OK)   echo "✓ Redis connection OK" ;;
    SKIP) echo "! 本机 PHP 未安装 redis 扩展，跳过 Redis 检查" ;;
    *)    echo "✗ Redis connection failed"; exit 1 ;;
esac

echo "Checking queue worker..."
if pgrep -f "queue:work" > /dev/null; then
    echo "✓ Queue worker running"
else
    echo "✗ Queue worker not running"
    exit 1
fi

echo "========================================="
echo "All health checks passed!"
echo "========================================="