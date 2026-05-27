# GEOFlow nginx 配置模板
# 三个目标站都需要添加以下配置块

## 关键修改说明

### 1. 移除旧的 rewrite 规则（如果存在）
删除类似以下的规则：
```nginx
# 删除这些
rewrite ^/news/geoflow-agent/v1/articles(.*)$ /news/index.php$1 last;
```

### 2. 添加 location 块（放在 server 块中，location / 之前）
```nginx
# GEOFlow Agent API
location ^~ /news/geoflow-agent/v1/ {
    root /home/wwwroot/www.{DOMAIN};
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
```

### 3. 各站 Webroot 路径
| 站点 | Webroot |
|------|---------|
| chengenyiliao.cn | /home/wwwroot/www.chengenyiliao.cn |
| tietachang.com | /home/wwwroot/www.tietachang.com |
| 90817.com | /home/wwwroot/www.90817.com |

### 4. 配置位置
- nginx 主配置: `/www/server/panel/vhost/nginx/{domain}.conf`
- 修改后需要: `nginx -t && nginx -s reload`

### 5. 验证命令
```bash
# 测试配置
nginx -t

# 重载
nginx -s reload

# 测试 API 端点
curl -s -o /dev/null -w "%{http_code}" https://{domain}/news/geoflow-agent/v1/articles
# 期望: GET 返回 404, POST 返回 401 (签名校验)
```
