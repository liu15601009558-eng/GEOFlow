# GEOFlow 稳定性保障方案 V2

> 日期：2026-06-05
> 版本：V2（优化版）
> 状态：待审批

---

## 一、问题根因（不变）

| 问题 | 根因 |
|------|------|
| 重复推送 | sync_article.php 无重复检查 |
| 图片不显示 | baseUrl 使用不存在的子域名 |
| SEO描述污染 | Markdown语法未清洗 |
| 文件名空格 | 解析器不支持URL空格 |

---

## 二、架构设计

### 2.1 三层防线

```
┌─────────────────────────────────────────────────────────┐
│                    第一层：推送端预处理                    │
│  auto_push_standalone.php                               │
│  ① 内容清洗（Markdown→纯文本摘要）                       │
│  ② 图片URL修正（主域名）                                 │
│  ③ 文件名规范化（空格→连字符）                           │
│  ④ 本地标题缓存查重（快速过滤）                          │
└─────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────┐
│                    第二层：接收端强校验                    │
│  sync_article.php                                       │
│  ① 数据库 UNIQUE 约束（title + typeid）                  │
│  ② INSERT ON DUPLICATE KEY UPDATE                       │
│  ③ seo_description 强制清洗                              │
│  ④ 返回 action: insert/updated/failed                   │
└─────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────┐
│                    第三层：定时监控告警                    │
│  HTTP 健康检查 API + 告警通知                            │
│  ① /sync_article.php?action=health                      │
│  ② 返回：重复数、异常描述数、接口状态                    │
│  ③ 超阈值 → 飞书/微信告警                               │
└─────────────────────────────────────────────────────────┘
```

### 2.2 监控分层

| 层级 | 方式 | 用途 | 失败影响 |
|------|------|------|---------|
| L1 | HTTP API | 实时检查接口可用性 | 低（可能是网络问题） |
| L2 | 远端health接口 | 检查数据健康状态 | 中（数据可能异常） |
| L3 | SSH直连数据库 | 深度检查+修复 | 高（需要人工介入） |

---

## 三、详细实现

### 3.1 推送端改造

**文件**：`auto_push_standalone.php`

```php
// ========== 配置区 ==========
$SITE_CONFIGS = [
    'chengenyiliao' => [
        'url' => 'https://www.chengenyiliao.cn/sync_article.php',
        'token' => 'geoflow_sync_2026_ceyl',
        'domain' => 'https://www.chengenyiliao.cn',
    ],
    'tietachang' => [
        'url' => 'https://www.tietachang.com/sync_article.php',
        'token' => 'geoflow_sync_2026_ceyl',
        'domain' => 'https://www.tietachang.com',
    ],
    '90817' => [
        'url' => 'https://90817.com/sync_article.php',
        'token' => 'geoflow_sync_2026_90817_monopole',
        'domain' => 'https://90817.com',
    ],
];

// ========== 核心函数 ==========

/**
 * 生成干净摘要（通用，不绑定CMS）
 */
function generateCleanSummary($content, $maxLen = 200) {
    if (empty($content)) return '';
    
    // 移除所有Markdown语法
    $clean = $content;
    $clean = preg_replace('/!\[[^\]]*\]\([^)]+\)/', '', $clean);  // 图片
    $clean = preg_replace('/\[[^\]]*\]\([^)]+\)/', '', $clean);   // 链接
    $clean = preg_replace('/[#*_~`>|-]/', '', $clean);            // 标记符号
    $clean = strip_tags($clean);                                   // HTML标签
    $clean = preg_replace('/\s+/', ' ', $clean);                   // 多余空白
    $clean = trim($clean);
    
    if (mb_strlen($clean) > $maxLen) {
        $clean = mb_substr($clean, 0, $maxLen, 'UTF-8') . '...';
    }
    return $clean;
}

/**
 * 规范化文件名（空格→连字符，去除特殊字符）
 */
function normalizeFilename($url) {
    // 提取文件名部分
    $parts = parse_url($url);
    $path = $parts['path'] ?? '';
    
    // 规范化文件名
    $filename = basename($path);
    $filename = preg_replace('/\s+/', '-', $filename);      // 空格→连字符
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);  // 去除特殊字符
    
    // 重建URL
    $dir = dirname($path);
    return ($dir !== '.' ? $dir . '/' : '') . $filename;
}

/**
 * 修正图片URL（使用站点主域名）
 */
function fixImageUrls($html, $siteDomain) {
    // 修正 /img/ 和 /uploads/ 路径
    $html = preg_replace('#src="(/img/[^"]+)"#i', 'src="' . $siteDomain . '$1"', $html);
    $html = preg_replace('#src="(/uploads/[^"]+)"#i', 'src="' . $siteDomain . '$1"', $html);
    
    // 修正文件名中的空格
    $html = preg_replace_callback('#src="([^"]+)"#i', function($m) {
        return 'src="' . normalizeFilename($m[1]) . '"';
    }, $html);
    
    return $html;
}
```

### 3.2 接收端改造

**文件**：各站点 `sync_article.php`

```php
// ========== 数据库表结构改造 ==========
// 添加唯一索引（防止重复）
ALTER TABLE ey_archives ADD UNIQUE INDEX idx_title_typeid (title, typeid, is_del);

// 添加内容hash字段（防止内容重复）
ALTER TABLE ey_archives ADD COLUMN content_hash VARCHAR(32) DEFAULT NULL;
ALTER TABLE ey_archives ADD INDEX idx_content_hash (content_hash);

// ========== 核心逻辑 ==========

/**
 * 插入或更新文章（UPSERT）
 */
function upsertArticle($pdo, $data) {
    $now = time();
    $title = trim($data['title'] ?? '');
    $typeid = intval($data['category_id'] ?? 15);
    $content = $data['content'] ?? '';
    $description = generateCleanSummary($content);  // 强制清洗
    
    // 计算内容hash
    $contentHash = md5($content);
    
    // 检查是否已存在（标题+栏目）
    $stmt = $pdo->prepare("SELECT aid FROM ey_archives WHERE title = ? AND typeid = ? AND is_del = 0 LIMIT 1");
    $stmt->execute([$title, $typeid]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // 更新
        $aid = $existing['aid'];
        $stmt = $pdo->prepare("UPDATE ey_archives SET seo_description = ?, content_hash = ?, update_time = ? WHERE aid = ?");
        $stmt->execute([$description, $contentHash, $now, $aid]);
        
        $stmt = $pdo->prepare("UPDATE ey_article_content SET content = ?, update_time = ? WHERE aid = ?");
        $stmt->execute([$content, $now, $aid]);
        
        return ['action' => 'updated', 'article_id' => (int)$aid];
    }
    
    // 检查内容hash是否重复（不同标题但相同内容）
    $stmt = $pdo->prepare("SELECT aid FROM ey_archives WHERE content_hash = ? AND is_del = 0 LIMIT 1");
    $stmt->execute([$contentHash]);
    $dup = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dup) {
        return ['action' => 'skipped', 'reason' => 'duplicate_content', 'article_id' => (int)$dup['aid']];
    }
    
    // 插入新文章
    // ... INSERT 逻辑 ...
    
    return ['action' => 'inserted', 'article_id' => (int)$aid];
}

/**
 * 健康检查接口
 */
function healthCheck($pdo) {
    $result = [
        'status' => 'ok',
        'checks' => [],
        'timestamp' => date('Y-m-d H:i:s'),
    ];
    
    // 检查重复
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM (SELECT title, typeid, COUNT(*) as c FROM ey_archives WHERE is_del = 0 GROUP BY title, typeid HAVING c > 1) as t");
    $dups = $stmt->fetch()['cnt'];
    $result['checks']['duplicates'] = [
        'value' => $dups,
        'threshold' => 0,
        'status' => $dups > 0 ? 'warning' : 'ok',
    ];
    
    // 检查异常描述
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM ey_archives WHERE is_del = 0 AND (seo_description LIKE '%! image%' OR seo_description LIKE '%copy.jpg%')");
    $badDesc = $stmt->fetch()['cnt'];
    $result['checks']['bad_descriptions'] = [
        'value' => $badDesc,
        'threshold' => 10,
        'status' => $badDesc > 10 ? 'warning' : 'ok',
    ];
    
    // 总体状态
    foreach ($result['checks'] as $check) {
        if ($check['status'] !== 'ok') {
            $result['status'] = 'warning';
            break;
        }
    }
    
    return $result;
}

// API路由
$action = $_GET['action'] ?? 'status';
if ($action === 'health') {
    jsonOk(healthCheck($pdo));
}
```

### 3.3 监控部署

**方式A：HTTP健康检查（推荐）**

```bash
#!/bin/bash
# health_check_http.sh
# 通过HTTP API检查，不依赖SSH

SITES=(
    "chengenyiliao|https://www.chengenyiliao.cn/sync_article.php?action=health|geoflow_sync_2026_ceyl"
    "tietachang|https://www.tietachang.com/sync_article.php?action=health|geoflow_sync_2026_ceyl"
    "90817|https://90817.com/sync_article.php?action=health|geoflow_sync_2026_90817_monopole"
)

ALERT_URL="https://open.feishu.cn/open-apis/bot/v2/hook/xxx"  # 飞书机器人

for site in "${SITES[@]}"; do
    IFS='|' read -r name url token <<< "$site"
    
    response=$(curl -sL --max-time 10 "$url" -H "X-Sync-Token: $token" 2>/dev/null)
    http_code=$(curl -sL -o /dev/null -w "%{http_code}" --max-time 10 "$url" -H "X-Sync-Token: $token" 2>/dev/null)
    
    if [ "$http_code" != "200" ]; then
        # 接口不可用，发送告警
        curl -sL -X POST "$ALERT_URL" -H "Content-Type: application/json" -d "{
            \"msg_type\": \"text\",
            \"content\": {\"text\": \"⚠️ $name 同步接口异常: HTTP $http_code\"}
        }"
        continue
    fi
    
    # 解析健康检查结果
    status=$(echo "$response" | grep -o '"status":"[^"]*"' | cut -d'"' -f4)
    dups=$(echo "$response" | grep -o '"duplicates":{[^}]*}' | grep -o '"value":[0-9]*' | cut -d: -f2)
    
    if [ "$status" != "ok" ]; then
        curl -sL -X POST "$ALERT_URL" -H "Content-Type: application/json" -d "{
            \"msg_type\": \"text\",
            \"content\": {\"text\": \"⚠️ $name 数据异常: 重复$dups篇, 状态$status\"}
        }"
    fi
done
```

**方式B：SSH深度检查（备用）**

```bash
#!/bin/bash
# health_check_ssh.sh
# SSH直连数据库深度检查，作为HTTP检查的补充

# 仅在HTTP检查失败时执行
# 用于确认是网络问题还是真实故障
```

### 3.4 数据库改造

**唯一索引方案（推荐）**：

```sql
-- 方案A：title + typeid 组合唯一（推荐）
ALTER TABLE ey_archives ADD UNIQUE INDEX idx_title_typeid (title, typeid, is_del);

-- 方案B：如果需要支持删除后重建，用软删除标记
-- 将 is_del=1 改为 deleted_at 时间戳
ALTER TABLE ey_archives ADD COLUMN deleted_at INT DEFAULT NULL;
-- 唯一索引只约束未删除的记录
CREATE UNIQUE INDEX idx_title_active ON ey_archives(title, typeid, (CASE WHEN deleted_at IS NULL THEN 1 ELSE NULL END));
```

**内容hash字段**：

```sql
ALTER TABLE ey_archives ADD COLUMN content_hash VARCHAR(32) DEFAULT NULL;
ALTER TABLE ey_archives ADD INDEX idx_content_hash (content_hash);
```

---

## 四、备选方案

### 4.1 如果不想改数据库表结构

**方案：应用层去重 + 定期清理**

```php
// 推送前检查（不依赖唯一索引）
function checkExists($pdo, $title, $typeid) {
    $stmt = $pdo->prepare("SELECT aid FROM ey_archives WHERE title = ? AND typeid = ? AND is_del = 0 LIMIT 1");
    $stmt->execute([$title, $typeid]);
    return $stmt->fetch();
}

// 定期清理重复（保留最新）
$sql = "UPDATE ey_archives SET is_del = 1 
        WHERE aid NOT IN (
            SELECT max_aid FROM (
                SELECT MAX(aid) as max_aid 
                FROM ey_archives 
                WHERE is_del = 0 
                GROUP BY title, typeid
            ) as t
        ) AND is_del = 0";
```

### 4.2 如果不想改sync_article.php

**方案：GEOFlow端全权处理**

```php
// GEOFlow端推送前完成所有处理
// sync_article.php 只做最简单的INSERT

// 1. 查询远端已有标题
$existing = queryRemoteTitles($siteUrl, $token);

// 2. 如果已存在，跳过或调用update接口
if (isset($existing[$title])) {
    // 调用update接口
    updateRemoteArticle($siteUrl, $token, $existing[$title], $data);
} else {
    // 调用insert接口
    insertRemoteArticle($siteUrl, $token, $data);
}
```

### 4.3 如果90817也需要同步修复

**方案：CMS适配层**

```php
interface CmsAdapter {
    public function insertArticle($data);
    public function updateArticle($aid, $data);
    public function checkDuplicate($title);
    public function healthCheck();
}

class EyouCmsAdapter implements CmsAdapter { ... }
class WordPressAdapter implements CmsAdapter { ... }
```

---

## 五、落地细节

### 5.1 变更前检查清单

```markdown
- [ ] 备份当前 sync_article.php
- [ ] 备份当前 auto_push_standalone.php
- [ ] 备份数据库表结构
- [ ] 验证备份可恢复
- [ ] 在测试环境验证（如有）
- [ ] 记录变更内容到 CHANGELOG
```

### 5.2 灰度发布流程

```
1. 修改代码，提交到Git
2. 部署到程恩医疗器械（流量最小）
3. 观察1小时，检查日志
4. 无异常后部署到铁塔厂
5. 观察1小时
6. 部署到90817
```

### 5.3 回滚流程

```bash
# 1. 恢复代码
cp /home/wwwroot/site/sync_article.php.bak /home/wwwroot/site/sync_article.php

# 2. 恢复数据库（如果改了表结构）
mysql -u user -p db < /backup/table_structure_backup.sql

# 3. 验证恢复
curl -sL "https://site/sync_article.php?action=status"
```

### 5.4 变更记录模板

```markdown
## [日期] 变更标题

**变更内容**：
- 文件：xxx.php
- 改动：xxx

**影响范围**：
- 程恩医疗器械 ✅
- 铁塔厂 ✅
- 90817 ✅

**回滚方式**：
- 恢复 xxx.php.bak

**验证命令**：
- curl https://site/sync_article.php?action=health
```

---

## 六、验收标准

| 检查项 | 标准 | 验证方式 |
|--------|------|---------|
| 重复检查 | 同标题同栏目不重复插入 | 推送测试 |
| 内容去重 | 同内容不同标题不重复插入 | 手动测试 |
| 图片URL | 使用主域名，无空格 | SQL查询 |
| SEO描述 | 无Markdown残留 | SQL查询 |
| 健康检查 | API返回正常 | curl测试 |
| 告警通知 | 异常时能收到通知 | 模拟异常 |

---

## 七、执行计划

| 阶段 | 内容 | 耗时 | 依赖 |
|------|------|------|------|
| P0 | 推送端改造（baseUrl+清洗） | 30分钟 | 无 |
| P1 | 接收端改造（UPSERT+health） | 45分钟 | P0 |
| P2 | 数据库改造（唯一索引+hash） | 15分钟 | P1 |
| P3 | 监控部署（HTTP检查+告警） | 30分钟 | P1 |
| P4 | 灰度发布+验证 | 1小时 | P0-P3 |

**总计**：约3小时

---

## 八、风险评估

| 风险 | 概率 | 影响 | 缓解措施 |
|------|------|------|---------|
| 唯一索引导致插入失败 | 中 | 高 | 先清理重复再加索引 |
| 健康检查API被滥用 | 低 | 中 | 添加频率限制 |
| 回滚不完整 | 低 | 高 | 变更前完整备份 |
| 监控告警风暴 | 中 | 低 | 添加告警抑制 |
