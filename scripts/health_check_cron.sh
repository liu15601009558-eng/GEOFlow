#!/bin/bash
# health_check_cron.sh
# GEOFlow 健康检查脚本
# 通过HTTP API检查所有站点健康状态

LOG_DIR="/home/liupeng/GEOFlow/logs"
STATE_DIR="/home/liupeng/GEOFlow/scripts/state"
mkdir -p "$LOG_DIR" "$STATE_DIR"

COOLDOWN=3600  # 同一问题3600秒内不重复告警

SITES=(
    "程恩医疗器械|https://www.chengenyiliao.cn/health.php?token=health_check_2026"
    "铁塔厂|https://www.tietachang.com/health.php?token=health_check_2026"
)

send_alert() {
    local site="$1"
    local message="$2"
    local state_file="$STATE_DIR/$(echo "$site" | md5sum | cut -d' ' -f1)"
    
    # 告警去重
    if [ -f "$state_file" ]; then
        last_alert=$(cat "$state_file")
        now=$(date +%s)
        if [ $((now - last_alert)) -lt $COOLDOWN ]; then
            return 0
        fi
    fi
    
    # 记录告警时间
    date +%s > "$state_file"
    
    # 写日志
    echo "$(date '+%Y-%m-%d %H:%M:%S') ALERT [$site] $message" >> "$LOG_DIR/alert.log"
}

echo "$(date '+%Y-%m-%d %H:%M:%S') 开始健康检查" >> "$LOG_DIR/health.log"

for site_info in "${SITES[@]}"; do
    IFS='|' read -r name url <<< "$site_info"
    
    # HTTP请求
    response=$(curl -sL --max-time 10 "$url" 2>/dev/null)
    http_code=$(curl -sL -o /dev/null -w "%{http_code}" --max-time 10 "$url" 2>/dev/null)
    
    if [ "$http_code" != "200" ]; then
        send_alert "$name" "接口异常 HTTP $http_code"
        echo "$(date '+%Y-%m-%d %H:%M:%S') $name: HTTP $http_code" >> "$LOG_DIR/health.log"
        continue
    fi
    
    # 解析JSON
    status=$(echo "$response" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status','unknown'))" 2>/dev/null || echo "parse_error")
    dups=$(echo "$response" | python3 -c "import sys,json; print(json.load(sys.stdin).get('checks',{}).get('duplicates',0))" 2>/dev/null || echo "0")
    badDesc=$(echo "$response" | python3 -c "import sys,json; print(json.load(sys.stdin).get('checks',{}).get('bad_descriptions',0))" 2>/dev/null || echo "0")
    total=$(echo "$response" | python3 -c "import sys,json; print(json.load(sys.stdin).get('checks',{}).get('total_articles',0))" 2>/dev/null || echo "0")
    
    # 记录日志
    echo "$(date '+%Y-%m-%d %H:%M:%S') $name: total=$total dups=$dups badDesc=$badDesc status=$status" >> "$LOG_DIR/health.log"
    
    # 检查阈值
    if [ "$status" != "ok" ]; then
        send_alert "$name" "状态=$status, 重复$dups篇, 异常描述$badDesc篇"
    fi
    
    if [ "$dups" -gt 0 ] 2>/dev/null; then
        send_alert "$name" "发现$dups篇重复文章"
    fi
done

# 日志轮转：只保留30天
find "$LOG_DIR" -name "*.log" -mtime +30 -delete 2>/dev/null

echo "$(date '+%Y-%m-%d %H:%M:%S') 健康检查完成" >> "$LOG_DIR/health.log"
