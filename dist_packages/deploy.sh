#!/bin/bash
# GEOFlow Distribution 远程站点部署脚本
# 在 211.149.151.138 上执行 (root)

set -e

SITES=(
  "www.chengenyiliao.cn|geoflow-target-site-www-chengenyiliao-cn.zip"
  "www.tietachang.com|geoflow-target-site-www-tietachang-com.zip"
)

for entry in "${SITES[@]}"; do
  DOMAIN="${entry%%|*}"
  ZIP="${entry##*|}"
  TARGET="/www/wwwroot/${DOMAIN}/news"
  
  echo "=== 部署 ${DOMAIN} ==="
  
  if [ ! -f "/tmp/${ZIP}" ]; then
    echo "  [SKIP] /tmp/${ZIP} 不存在,请先 scp 传过来"
    continue
  fi
  
  mkdir -p "${TARGET}"
  cd /www/wwwroot/${DOMAIN}
  
  # 备份旧 news 目录(如有)
  if [ -d "news" ] && [ "$(ls -A news 2>/dev/null)" ]; then
    mv news "news.bak.$(date +%Y%m%d_%H%M%S)"
    mkdir -p news
  fi
  
  unzip -o "/tmp/${ZIP}" -d "${TARGET}"
  chown -R www:www "${TARGET}"
  chmod -R 755 "${TARGET}"
  
  echo "  [OK] ${DOMAIN}/news/ 已部署"
  echo "  [OK] 含 ${TARGET}/public/index.php (API接收端)"
done

echo ""
echo "=== 下一步: 配置 nginx 路由 ==="
echo "在宝塔面板 → 网站 → ${DOMAIN} → 伪静态/配置文件中加入:"
echo ""
cat << 'NGINX'
# GEOFlow Distribution 子站路由 (/news/)
location ^~ /news/ {
    alias /www/wwwroot/你的域名/news/public/;
    try_files $uri $uri/ /news/index.php?$query_string;
    
    location ~ \.php$ {
        fastcgi_pass unix:/tmp/php-cgi-83.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        include fastcgi_params;
    }
}
NGINX

echo ""
echo "然后重启 nginx: /etc/init.d/nginx restart"
echo "重启 php-fpm: /etc/init.d/php-fpm-83 restart"
