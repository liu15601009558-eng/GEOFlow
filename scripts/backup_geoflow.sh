#!/bin/bash
# GEOFlow 每日备份：数据库 + 配置/上传文件
# 手动执行：bash scripts/backup_geoflow.sh
set -euo pipefail

ROOT="${GEOFLOW_BACKUP_ROOT:-/home/liupeng/backups/geoflow}"
PROJECT="${GEOFLOW_PROJECT_DIR:-/home/liupeng/GEOFlow}"
DAY="$(date +%Y%m%d)"
LOG="$ROOT/backup.log"

mkdir -p "$ROOT/db" "$ROOT/files"
exec >> "$LOG" 2>&1
echo "===== $(date '+%F %T') 开始备份 ====="

# 1) 数据库（容器内 local trust，无需密码）
# -Fc 自定义格式本身已压缩，不再二次 gzip，节省 CPU 与时间。
docker exec geo-system-postgres pg_dump -U geo_user -d geo_system -Fc \
  > "$ROOT/db/geo_system_$DAY.dump"
sha256sum "$ROOT/db/geo_system_$DAY.dump" > "$ROOT/db/geo_system_$DAY.dump.sha256"
echo "数据库备份: $(du -h "$ROOT/db/geo_system_$DAY.dump" | cut -f1)"

# 2) 配置与文件（含密钥，归档后立即 600）
tar -czf "$ROOT/files/geoflow_files_$DAY.tar.gz" -C "$PROJECT" \
  .env uploads dist_packages storage/app scripts/secrets.local.env 2>/dev/null || true
chmod 600 "$ROOT/files/geoflow_files_$DAY.tar.gz"
echo "文件备份: $(du -h "$ROOT/files/geoflow_files_$DAY.tar.gz" | cut -f1)"

# 3) 保留 7 天（数据库与文件各留日备）
find "$ROOT/db" -name 'geo_system_*.dump' -mtime +7 -delete
find "$ROOT/db" -name 'geo_system_*.dump.sha256' -mtime +7 -delete
find "$ROOT/files" -name 'geoflow_files_*.tar.gz' -mtime +7 -delete
echo "===== $(date '+%F %T') 备份完成 ====="
