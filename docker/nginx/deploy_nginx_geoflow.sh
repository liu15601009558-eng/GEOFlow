#!/bin/bash
# deploy_nginx_geoflow.sh - 部署 GEOFlow nginx 配置到三个目标站
# 用法: sudo bash deploy_nginx_geoflow.sh
# 需要在远程服务器上以 root 权限运行

set -e

SITES="chengenyiliao.cn tietachang.com 90817.com"
NGINX_DIR="/www/server/panel/vhost/nginx"
WEBROOT_PREFIX="/home/wwwroot/www"

# GEOFlow location 块模板
GEOFLOW_LOCATION='
# === GEOFlow Agent API ===
location ^~ /news/geoflow-agent/v1/ {
    root %WEBROOT%;
    index index.php;
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_NAME $document_root/index.php;
    }
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
'

echo "=== GEOFlow nginx 配置部署脚本 ==="
echo ""

for SITE in $SITES; do
    echo "--- 处理 $SITE ---"
    
    NGINX_CONF="${NGINX_DIR}/${SITE}.conf"
    WEBROOT="${WEBROOT_PREFIX}/${SITE}"
    
    if [ ! -f "$NGINX_CONF" ]; then
        echo "  ❌ 配置文件不存在: $NGINX_CONF"
        continue
    fi
    
    # 检查是否已存在 GEOFlow 配置
    if grep -q "GEOFlow Agent API" "$NGINX_CONF"; then
        echo "  ⚠️  已存在 GEOFlow 配置，跳过"
        continue
    fi
    
    # 替换 Webroot 路径
    LOCATION_BLOCK=$(echo "$GEOFLOW_LOCATION" | sed "s|%WEBROOT%|${WEBROOT}|g")
    
    # 在 location / 之前插入 GEOFlow 配置
    # 使用 sed 在 "location / {" 行之前插入
    TMP_CONF=$(mktemp)
    
    # 查找 location / 的位置并插入
    awk -v block="$LOCATION_BLOCK" '
        /^    location \/ \{/ {
            print block
        }
        { print }
    ' "$NGINX_CONF" > "$TMP_CONF"
    
    # 验证语法
    if nginx -t -c "$TMP_CONF" 2>/dev/null; then
        cp "$TMP_CONF" "$NGINX_CONF"
        echo "  ✅ 配置已更新"
    else
        echo "  ❌ 配置语法错误，已保留原文件"
        rm -f "$TMP_CONF"
        continue
    fi
    
    rm -f "$TMP_CONF"
done

echo ""
echo "=== 验证 nginx 配置 ==="
nginx -t

echo ""
echo "=== 重载 nginx ==="
nginx -s reload

echo ""
echo "=== 测试 API 端点 ==="
for SITE in $SITES; do
    GET_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://${SITE}/news/geoflow-agent/v1/articles")
    POST_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "Content-Type: application/json" -d '{"test":1}' "https://${SITE}/news/geoflow-agent/v1/articles")
    echo "  ${SITE}: GET=${GET_CODE} POST=${POST_CODE}"
done

echo ""
echo "✅ 完成"
