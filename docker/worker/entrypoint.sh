#!/bin/sh
#
# MuuCmf T6 worker 容器入口：等待依赖就绪后交给 supervisord
#
# 探活地址必须与应用一致，即来自 .env 的 [DATABASE]/[REDIS]；
# 不能再依赖 DB_HOST/REDIS_HOST 之类的容器环境变量——ThinkPHP 只读 .env，
# 传环境变量应用读不到，探活结果也就不代表应用真实情况。
#
set -e

echo "Starting MuuCmf T6 Worker..."

APP_ROOT=/var/www/html
ENV_FILE="$APP_ROOT/.env"
WAIT_TIMEOUT=120

mkdir -p /var/log/supervisor

# 从 .env 的 [DATABASE] 段读取并尝试连接 MySQL
db_probe() {
    php -r '
        $env = parse_ini_file($argv[1], true, INI_SCANNER_RAW) ?: [];
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
            echo "WAIT";
        }
    ' "$ENV_FILE"
}

# 从 .env 的 [REDIS] 段读取并尝试连接 Redis
redis_probe() {
    php -r '
        $env   = parse_ini_file($argv[1], true, INI_SCANNER_RAW) ?: [];
        $redis = $env["REDIS"] ?? [];
        try {
            $client = new Redis();
            $client->connect($redis["HOST"] ?? "127.0.0.1", (int) ($redis["PORT"] ?? 6379), 2.0);
            if (($redis["password"] ?? "") !== "") {
                $client->auth($redis["password"]);
            }
            echo "OK";
        } catch (Throwable $e) {
            echo "WAIT";
        }
    ' "$ENV_FILE"
}

wait_for() {
    service=$1
    probe=$2
    hint=$3

    started=$(date +%s)

    while [ "$($probe)" != "OK" ]; do
        now=$(date +%s)
        elapsed=$((now - started))
        if [ "$elapsed" -ge "$WAIT_TIMEOUT" ]; then
            echo "[worker] ERROR: 等待 ${service} 就绪超时（${WAIT_TIMEOUT}s）。"
            echo "[worker] hint: ${hint}"
            exit 1
        fi
        echo "${service} is unavailable - sleeping (${elapsed}s)"
        sleep 2
    done

    echo "${service} is ready!"
}

if [ ! -f "$ENV_FILE" ]; then
    echo "[worker] 未找到 $ENV_FILE，跳过依赖探活。"
    echo "[worker] 请先在宿主准备 .env（可复制 .env.production.example）后重启容器。"
else
    echo "Waiting for MySQL to be ready..."
    wait_for "MySQL" "db_probe" \
        "若 .env 的 [DATABASE] HOSTNAME 为 127.0.0.1，请改为容器服务名 mysql；数据库账号需与 .env.docker 一致。"

    echo "Waiting for Redis to be ready..."
    wait_for "Redis" "redis_probe" \
        "若 .env 的 [REDIS] HOST 为 127.0.0.1，请改为容器服务名 redis。"
fi

echo "Starting supervisor..."
# 复用 app 容器的初始化脚本：校验 .env、对齐 www-data UID/GID、修正共享卷属主
exec /var/www/html/docker/php/entrypoint.sh /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf