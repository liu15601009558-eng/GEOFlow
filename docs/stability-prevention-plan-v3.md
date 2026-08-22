# GEOFlow 稳定性保障方案 V3（终版）

> 日期：2026-06-05
> 版本：V3
> 状态：待执行
> 基于V2复盘修正18个问题

---

## 一、核心原则

1. **最小改动**：不改数据库表结构，不动引擎
2. **向后兼容**：新代码能处理旧数据
3. **独立部署**：每个站点独立文件，互不影响
4. **失败安全**：任何一步失败都不影响已有功能

---

## 二、最终架构

```
GEOFlow服务器
┌──────────────────────────────┐
│ auto_push_standalone.php     │
│ ① generateCleanSummary()    │  ← 通用清洗，覆盖所有Markdown残留
│ ② fixImageUrls($domain)     │  ← 只替换域名，不动文件名
│ ③ POST 到远端               │
│ ④ 记录推送日志               │
└──────────────────────────────┘
              │
              ▼
远端服务器（每站点独立文件）
┌──────────────────────────────┐
│ sync_article.php             │
│ ① INSERT前查重(title+typeid) │  ← 应用层去重，不依赖索引
│ ② 已存在则UPDATE            │  ← 用PDO事务
│ ③ seo_description强制清洗    │
│ ④ 返回 action 字段           │
│                              │
│ health.php（独立文件）        │
│ ① 检查重复数                │
│ ② 检查异常描述数             │
│ ③ 检查接口状态               │
│ ④ 返回JSON                  │
└──────────────────────────────┘
              │
              ▼
GEOFlow服务器
┌──────────────────────────────┐
│ cronjob: health_check.sh     │
│ ① curl 调 health.php        │  ← HTTP检查，不依赖SSH
│ ② 解析JSON用jq              │
│ ③ 告警去重（状态文件）        │
│ ④ 飞书webhook通知            │
└──────────────────────────────┘
```

---

## 三、详细实现

### 3.1 推送端：auto_push_standalone.php

**改动范围**：只改3个函数，不动主流程

```php
/**
 * 生成干净摘要（通用，覆盖所有已知残留模式）
 */
function generateCleanSummary($content, $maxLen = 200) {
    if (empty($content)) return '';
    
    $clean = $content;
    // 移除Markdown图片：![alt](url)
    $clean = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $clean);
    // 移除残留模式：! image /img/xxx.jpg
    $clean = preg_replace('/!\s*image\s+\S+/', '', $clean);
    // 移除HTML图片标签
    $clean = preg_replace('/<img[^>]*>/i', '', $clean);
    // 移除其他Markdown链接
    $clean = preg_replace('/\[[^\]]*\]\([^)]+\)/', '', $clean);
    // 移除HTML标签
    $clean = strip_tags($clean);
    // 合并空白
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = trim($clean);
    
    if (mb_strlen($clean, 'UTF-8') > $maxLen) {
        $clean = mb_substr($clean, 0, $maxLen, 'UTF-8') . '...';
    }
    return $clean;
}

/**
 * 修正图片URL（只改域名，不动文件名）
 */
function fixImageUrls($html, $siteDomain) {
    // /img/ 路径 → 完整URL
    $html = preg_replace('#(src=["\']?)(/img/)#i', '$1' . $siteDomain . '$2', $html);
    // /uploads/ 路径 → 完整URL
    $html = preg_replace('#(src=["\']?)(/uploads/)#i', '$1' . $siteDomain . '$2', $html);
    return $html;
}
```

**配置区**（已有，不需要改）：
```php
$baseUrl = "https://www.chengenyiliao.cn";  // 已修复
```

**改动点**：
- `convertMarkdownToHtml` 中调用 `fixImageUrls` 时传入站点域名
- `description` 字段用 `generateCleanSummary` 生成（已有）

### 3.2 接收端：sync_article.php

**改动范围**：在 INSERT 前加查重逻辑，其他不变

```php
// ========== 在 INSERT 语句之前插入 ==========

// 查重：标题 + 栏目
$checkStmt = $pdo->prepare("SELECT aid FROM ey_archives WHERE title = ? AND typeid = ? AND is_del = 0 LIMIT 1");
$checkStmt->execute([$title, $typeid]);
$existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    // 已存在 → 更新
    $aid = $existing['aid'];
    $pdo->beginTransaction();
    try {
        $upd1 = $pdo->prepare("UPDATE ey_archives SET seo_description = ?, update_time = ? WHERE aid = ?");
        $upd1->execute([$description, $now, $aid]);
        
        $upd2 = $pdo->prepare("UPDATE ey_article_content SET content = ?, update_time = ? WHERE aid = ?");
        $upd2->execute([$content, $now, $aid]);
        
        $pdo->commit();
        jsonOk(['article_id' => (int)$aid, 'title' => $title, 'message' => '文章更新成功', 'action' => 'updated']);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonErr('更新失败: ' . $e->getMessage());
        exit;
    }
}

// 不存在 → 继续执行原有的 INSERT 逻辑
// ... 原有代码不变 ...
```

**seo_description 清洗**（在 description 生成处修改）：
```php
// 原来：
$description = !empty($data["seo_description"]) ? $data["seo_description"] : ($data["description"] ?: mb_substr(strip_tags($content), 0, 200));

// 改为：
$rawDesc = !empty($data["seo_description"]) ? $data["seo_description"] : ($data["description"] ?? '');
if (empty($rawDesc)) {
    // 从内容生成：先去Markdown图片，再去HTML标签
    $rawDesc = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $content);
    $rawDesc = preg_replace('/!\s*image\s+\S+/', '', $rawDesc);
    $rawDesc = strip_tags($rawDesc);
    $rawDesc = preg_replace('/\s+/', ' ', $rawDesc);
    $rawDesc = mb_substr(trim($rawDesc), 0, 200, 'UTF-8');
}
$description = $rawDesc;
```

### 3.3 健康检查：health.php（新文件）

**独立文件，不污染 sync_article.php**

```php
<?php
// health.php - 放在 sync_article.php 同目录
// 访问：/health.php?token=xxx

header('Content-Type: application/json; charset=utf-8');

$token = $_GET['token'] ?? '';
$validToken = 'health_check_2026';  // 独立token，不用sync_token

if ($token !== $validToken) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid token']);
    exit;
}

// 加载数据库配置（复用 sync_article.php 的配置）
require_once __DIR__ . '/sync_db_config.php';  // 如果有
// 或直接写死（每个站点不同）

try {
    $pdo = new PDO("mysql:host=localhost;dbname={$DB_NAME}", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 检查重复
    $stmt = $pdo->query("
        SELECT COUNT(*) as cnt FROM (
            SELECT title, typeid, COUNT(*) as c 
            FROM {$TABLE_PREFIX}archives 
            WHERE is_del = 0 AND title != ''
            GROUP BY title, typeid 
            HAVING c > 1
        ) as t
    ");
    $dups = $stmt->fetch()['cnt'];
    
    // 检查异常描述
    $stmt = $pdo->query("
        SELECT COUNT(*) as cnt 
        FROM {$TABLE_PREFIX}archives 
        WHERE is_del = 0 
        AND (seo_description LIKE '%! image%' 
             OR seo_description LIKE '%copy.jpg%'
             OR seo_description LIKE '%.JPG%')
    ");
    $badDesc = $stmt->fetch()['cnt'];
    
    // 文章总数
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM {$TABLE_PREFIX}archives WHERE is_del = 0");
    $total = $stmt->fetch()['cnt'];
    
    $status = 'ok';
    if ($dups > 0 || $badDesc > 10) $status = 'warning';
    
    echo json_encode([
        'status' => $status,
        'timestamp' => date('c'),
        'checks' => [
            'total_articles' => (int)$total,
            'duplicates' => (int)$dups,
            'bad_descriptions' => (int)$badDesc,
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
```

### 3.4 监控脚本：health_check.sh

```bash
#!/bin/bash
# health_check.sh
# 通过HTTP API检查所有站点健康状态
# 依赖：curl, jq

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
STATE_DIR="$SCRIPT_DIR/state"
LOG_DIR="$SCRIPT_DIR/logs"
mkdir -p "$STATE_DIR" "$LOG_DIR"

ALERT_URL="${FEISHU_WEBHOOK_URL:-}"  # 从环境变量读取
COOLDOWN=3600  # 同一问题3600秒内不重复告警

SITES=(
    "程恩医疗器械|https://www.chengenyiliao.cn/health.php?token=health_check_2026"
    "铁塔厂|https://www.tietachang.com/health.php?token=health_check_2026"
)

send_alert() {
    local site="$1"
    local message="$2"
    local state_file="$STATE_DIR/$(echo "$site" | md5sum | cut -d' ' -f1)"
    
    # 告警去重：同一问题在冷却期内只发一次
    if [ -f "$state_file" ]; then
        last_alert=$(cat "$state_file")
        now=$(date +%s)
        if [ $((now - last_alert)) -lt $COOLDOWN ]; then
            return 0
        fi
    fi
    
    # 发送告警
    if [ -n "$ALERT_URL" ]; then
        curl -sL -X POST "$ALERT_URL" \
            -H "Content-Type: application/json" \
            -d "{\"msg_type\":\"text\",\"content\":{\"text\":\"⚠️ $site: $message\"}}" \
            >/dev/null 2>&1
    fi
    
    # 记录告警时间
    date +%s > "$state_file"
    
    # 写日志
    echo "$(date '+%Y-%m-%d %H:%M:%S') ALERT [$site] $message" >> "$LOG_DIR/alert.log"
}

for site_info in "${SITES[@]}"; do
    IFS='|' read -r name url <<< "$site_info"
    
    # HTTP请求
    response=$(curl -sL --max-time 10 "$url" 2>/dev/null)
    http_code=$(curl -sL -o /dev/null -w "%{http_code}" --max-time 10 "$url" 2>/dev/null)
    
    if [ "$http_code" != "200" ]; then
        send_alert "$name" "接口异常 HTTP $http_code"
        continue
    fi
    
    # 用jq解析JSON
    status=$(echo "$response" | jq -r '.status // "unknown"')
    dups=$(echo "$response" | jq -r '.checks.duplicates // 0')
    badDesc=$(echo "$response" | jq -r '.checks.bad_descriptions // 0')
    total=$(echo "$response" | jq -r '.checks.total_articles // 0')
    
    # 记录日志
    echo "$(date '+%Y-%m-%d %H:%M:%S') $name: total=$total dups=$dups badDesc=$badDesc status=$status" >> "$LOG_DIR/health.log"
    
    # 检查阈值
    if [ "$status" != "ok" ]; then
        send_alert "$name" "状态=$status, 重复$dups篇, 异常描述$badDesc篇"
    fi
    
    if [ "$dups" -gt 0 ]; then
        send_alert "$name" "发现$dups篇重复文章"
    fi
done

# 日志轮转：只保留30天
find "$LOG_DIR" -name "*.log" -mtime +30 -delete 2>/dev/null
```

### 3.5 备份与回滚

**备份命令**（执行前必须运行）：

```bash
#!/bin/bash
# backup_before_change.sh

BACKUP_DIR="/home/liupeng/GEOFlow/backups/$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

# 备份远端文件
ssh -p 22000 root@211.149.151.138 "
cp /home/wwwroot/chengenyiliao.cn/sync_article.php $BACKUP_DIR/chengenyiliao_sync_article.php
cp /home/wwwroot/tietachang.com/sync_article.php $BACKUP_DIR/tietachang_sync_article.php
"

# 备份GEOFlow端
docker cp geo-system-web:/var/www/html/bin/auto_push_standalone.php $BACKUP_DIR/auto_push_standalone.php

# 备份数据库表结构
ssh -p 22000 root@211.149.151.138 "
mysqldump -u chengenyiliao_cn -p'ScQGH7QXYX' chengenyiliao_cn ey_archives --no-data > $BACKUP_DIR/chengenyiliao_schema.sql
mysqldump -u tietachang_com -p'WJczF8BtizAsbbTX' tietachang_com ey_archives --no-data > $BACKUP_DIR/tietachang_schema.sql
"

echo "备份完成: $BACKUP_DIR"
ls -la "$BACKUP_DIR"
```

**回滚命令**：

```bash
#!/bin/bash
# rollback.sh <backup_dir>

BACKUP_DIR=$1

if [ -z "$BACKUP_DIR" ] || [ ! -d "$BACKUP_DIR" ]; then
    echo "用法: rollback.sh <备份目录>"
    exit 1
fi

# 恢复远端文件
ssh -p 22000 root@211.149.151.138 "
cp $BACKUP_DIR/chengenyiliao_sync_article.php /home/wwwroot/chengenyiliao.cn/sync_article.php
cp $BACKUP_DIR/tietachang_sync_article.php /home/wwwroot/tietachang.com/sync_article.php
"

# 恢复GEOFlow端
docker cp $BACKUP_DIR/auto_push_standalone.php geo-system-web:/var/www/html/bin/auto_push_standalone.php

# 验证
curl -sL "https://www.chengenyiliao.cn/sync_article.php?action=status"
curl -sL "https://www.tietachang.com/sync_article.php?action=status"

echo "回滚完成"
```

### 3.6 测试与验证

**部署前测试**（用curl模拟推送）：

```bash
# 测试程恩医疗器械
curl -sL -X POST "https://www.chengenyiliao.cn/sync_article.php" \
  -H "X-Sync-Token: geoflow_sync_2026_ceyl" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "测试文章_请删除",
    "content": "<p>这是测试内容</p>",
    "description": "测试描述",
    "category_id": 15,
    "seo_title": "测试文章",
    "seo_keywords": "测试",
    "seo_description": "测试描述"
  }'

# 预期返回：{"code":0,"data":{"action":"inserted","article_id":xxxxx}}

# 再次推送同标题
# 预期返回：{"code":0,"data":{"action":"updated","article_id":xxxxx}}

# 清理测试数据
curl -sL "https://www.chengenyiliao.cn/sync_article.php?action=delete&ids=xxxxx" \
  -H "X-Sync-Token: geoflow_sync_2026_ceyl"
```

---

## 四、执行计划

| 步骤 | 内容 | 耗时 | 验证 |
|------|------|------|------|
| 1 | 备份 | 5分钟 | ls 备份目录 |
| 2 | 改 auto_push_standalone.php | 10分钟 | 代码review |
| 3 | 改程恩 sync_article.php | 10分钟 | curl测试 |
| 4 | 改铁塔厂 sync_article.php | 10分钟 | curl测试 |
| 5 | 部署 health.php | 5分钟 | curl测试 |
| 6 | 部署 cronjob | 5分钟 | 手动运行 |
| 7 | 验证 | 10分钟 | 查看日志 |

**总计**：约55分钟

---

## 五、风险评估

| 风险 | 概率 | 影响 | 缓解 |
|------|------|------|------|
| sync_article.php改错导致同步中断 | 低 | 高 | 备份+回滚脚本 |
| health.php安全漏洞 | 低 | 中 | 独立token验证 |
| 告警误报 | 中 | 低 | 冷却期机制 |
| 查重SQL性能差 | 低 | 低 | title+typeid索引已有 |
