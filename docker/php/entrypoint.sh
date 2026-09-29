#!/bin/sh
#
# MuuCmf T6 PHP 容器通用初始化（app 与 worker 共用）
#
# 方案 B：代码位于持久化卷，后台「在线更新」与应用安装会直接写入
# app/config/public/route/extend/data 等目录，因此容器内的 www-data
# 必须对这些目录有写权限，否则升级会报「写入失败」。
#
set -e

APP_ROOT=/var/www/html

log() {
    echo "[entrypoint] $*"
}

# 1. .env 必须是文件而不是目录。
#    单文件 bind mount 在宿主文件缺失时会被 Docker 自动创建成同名目录，
#    此时 PHP 读不到配置且安装向导也写不进去，属于静默故障，这里直接拦下。
if [ -d "$APP_ROOT/.env" ]; then
    log "ERROR: $APP_ROOT/.env 是目录而不是文件。"
    log "宿主上的 .env 不存在时，Docker 会自动创建同名目录。"
    log "请在宿主创建 .env（可复制 .env.production.example 作为模板）后重新启动容器。"
    exit 1
fi

# 2. 按 PUID/PGID 对齐 www-data，使容器写宿主卷后属主仍是宿主用户。
#    Alpine 基础镜像没有 usermod/groupmod，直接改写 /etc/passwd 与 /etc/group。
if [ "$(id -u)" = "0" ] && [ -n "$PUID" ] && [ -n "$PGID" ]; then
    if [ "$(id -u www-data)" != "$PUID" ] || [ "$(id -g www-data)" != "$PGID" ]; then
        log "对齐 www-data 到 ${PUID}:${PGID}"
        sed -i "s|^www-data:x:[0-9]*:[0-9]*:|www-data:x:${PUID}:${PGID}:|" /etc/passwd
        sed -i "s|^www-data:x:[0-9]*:|www-data:x:${PGID}:|" /etc/group
    fi
fi

# 3. 修正可写路径属主。仅在明确配置了 PUID/PGID 时执行：
#    无条件 chown 会把宿主代码目录归属改成 www-data，导致宿主用户 git pull / 编辑文件失败；
#    而 dev 环境（docker-compose.dev.yml）整目录挂载，误改属主的代价更大。
if [ "$(id -u)" = "0" ] && [ -n "$PUID" ] && [ -n "$PGID" ]; then
    TARGET_OWNER="$(id -u www-data):$(id -g www-data)"
    for path in .env runtime data app config public route extend; do
        target="$APP_ROOT/$path"
        [ -e "$target" ] || continue
        current_owner="$(stat -c '%u:%g' "$target" 2>/dev/null || echo "")"
        if [ "$current_owner" != "$TARGET_OWNER" ]; then
            log "修正 $path 属主为 www-data"
            chown -R www-data:www-data "$target"
        fi
    done
fi

if [ -z "$PUID" ] || [ -z "$PGID" ]; then
    log "WARNING: 未设置 PUID/PGID，跳过属主修正。"
    log "若后台在线更新或应用安装报「写入失败」，请在宿主机执行 id -u / id -g"
    log "取其结果写入宿主 .env 的 PUID / PGID，然后重启容器。"
fi

exec "$@"