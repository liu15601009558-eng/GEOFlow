# 程恩医疗器械同步修复方案（完善版）

> 日期：2026-06-04
> 状态：待执行

## 一、问题根因

| 时间 | 事件 | 影响 |
|------|------|------|
| 2026-05-23 之前 | cron.php autoPushToSite() 正常运行 | 文章写入 EyouCMS 数据库 ✅ |
| 2026-05-23 | cron.php 不再被 Laravel scheduler 调用 | 同步停止 ❌ |
| 2026-05-25 | 新分发系统上线（GEOFlow Agent） | 文章存 JSON 文件，不写数据库 |
| 现在 | 503 篇已发布文章未同步 | EyouCMS 后台看不到新文章 |

## 二、远端 sync_article.php 能力确认

sync_article.php 的 POST 接口已具备完整功能：

| 功能 | 状态 | 说明 |
|------|------|------|
| 自动分类 | ✅ | 根据标题关键词自动分到子栏目（45-49） |
| Markdown→HTML | ✅ | 自动转换排版 |
| 百度推送 | ✅ | 每篇文章自动推送百度收录 |
| 封面图提取 | ✅ | 从内容中提取第一张图片作为 litpic |
| TDK 字段 | ✅ | 支持 seo_title/seo_keywords/seo_description |
| Emoji 清理 | ✅ | 自动清理 4 字节 emoji |
| 产品图片插入 | ✅ | 自动插入产品配图 |

子栏目自动分类规则（远端 sync_article.php 内置）：

| 栏目 ID | 栏目名 | 触发关键词 |
|---------|--------|-----------|
| 45 | B2B采购方案 | 招标,集采,批发,报价,直销,大宗,医养结合,老年公寓... |
| 46 | 产品选型指南 | 选购,怎么选,哪里买,品牌,推荐,指南...（默认兜底） |
| 47 | 产品对比评测 | 电动vs,手动vs,承重,材质,对比,区别,参数... |
| 48 | 行业政策趋势 | 政策,报销,医保,认证,ISO,趋势,2026,宏观... |
| 49 | 家庭护理技巧 | 照顾,翻身,褥疮,中风,骨折,瘫痪,卧床,护工... |

## 三、修复方案

### Step 1: 恢复 autoPushToSite() 调度

**问题**：Laravel scheduler 只调度 `geoflow:schedule-tasks`，不调用 `cron.php`。

**修复**：在 `routes/console.php` 中添加 cron.php 调度。

**修改文件**：`/var/www/html/routes/console.php`

**添加内容**（在文件末尾 `Schedule::command('geoflow:track-articles')` 之后）：

```php
/**
 * GEOFlow 文章同步到 EyouCMS：每 10 分钟推送一次已发布文章。
 * 通过 sync_article.php 写入远端数据库，自动分类+百度推送。
 */
Schedule::exec('php ' . base_path('bin/cron.php'))
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/cron-sync.log'));
```

**注意**：`cron.php` 包含多个功能（任务调度、文章发布、自动推送），全部执行。

### Step 2: 修改 autoPushToSite() 使用自动分类

**当前问题**：`autoPushToSite()` 发送 `category_id => $site['default_category_id']`（固定值 15）。

**远端行为**：sync_article.php 收到 POST 后会自动覆盖 category_id（根据标题关键词分类）。

**结论**：不需要修改！远端 sync_article.php 的 POST 接口会自动分类。当前发送的 `category_id=15` 会被远端的 `autoClassify()` 覆盖为正确的子栏目 ID。

**验证**：之前测试的结果证实了这一点：
- 发送 category_id=15 → 远端自动改为 45/46/47/48

### Step 3: 补推 503 篇未同步文章

**手动执行一次**：
```bash
docker exec geo-system-web php /var/www/html/bin/cron.php
```

**预期行为**：
1. 读取 `target_sites` 表获取站点配置
2. 查找 `tasks.site_id=1` 关联的任务（8, 9, 17, 18）
3. 查询 `status='published'` 且不在 `article_sync_log` 中的文章
4. 每篇文章：
   - Markdown → HTML 转换
   - Emoji 清理
   - AI 输出清理
   - POST 到 sync_article.php
   - 远端自动分类到子栏目（45-49）
   - 远端自动写入 EyouCMS 数据库
   - 远端自动推送百度收录
   - 记录到 article_sync_log
5. 每篇间隔 1 秒

**预计耗时**：503 篇 × 1 秒 = ~8 分钟

### Step 4: 验证

```bash
# 1. 检查 article_sync_log 新增记录
docker exec geo-system-postgres psql -U geo_user -d geo_system -c "
SELECT target, status, COUNT(*) FROM article_sync_log 
WHERE created_at > '2026-06-04' GROUP BY target, status;"

# 2. 检查 EyouCMS 后台文章数量
curl -sL "https://www.chengenyiliao.cn/sync_article.php?action=list_titles&limit=1" \
  -H "X-Sync-Token: geoflow_sync_2026_ceyl" | grep -o '"aid":[0-9]*' | tail -1

# 3. 检查文章 URL 可访问
curl -sI "https://www.chengenyiliao.cn/Related_knowledge/3131.html" | head -3

# 4. 清 EyouCMS 运行时缓存（新文章可能需要）
sshpass -p 'wf52sd4q' ssh -o StrictHostKeyChecking=no -p 22000 root@211.149.151.138 \
  'rm -rf /www/wwwroot/chengenyiliao.cn/runtime/*'

# 5. 检查分类是否正确
curl -sL "https://www.chengenyiliao.cn/sync_article.php?action=list_titles&limit=10&offset=3120" \
  -H "X-Sync-Token: geoflow_sync_2026_ceyl" | python3 -c "
import json, sys
data = json.load(sys.stdin)
for a in data['data'][-5:]:
    print(f\"  aid={a['aid']} typeid={a['typeid']} {a['title'][:40]}\")"
```

### Step 5: 处理 /news/ 路径（可选，不急）

当前 `/news/` 被 GEOFlow Agent 接管（渲染 JSON 文件，无 CSS）。

**两个选择**：

**A. 保留 /news/ 由 GEOFlow Agent 渲染**
- 需要单独修复 CSS 文件部署
- /news/ 和 EyouCMS 是两套独立系统

**B. 移除 GEOFlow Agent 的 /news/ 接管（推荐）**
- 让 EyouCMS 自己渲染所有页面
- 文章通过 /Related_knowledge/{aid}.html 访问
- 样式自然继承 EyouCMS 模板

**执行**（选 B 时）：
1. 在远端服务器找到 GEOFlow Agent 的 nginx 配置
2. 移除 /news/ 路径的特殊处理
3. 清除 EyouCMS 运行时缓存

## 四、安全保障

| 保障措施 | 说明 |
|----------|------|
| 不重复推送 | article_sync_log 已有 2854 条记录，ON CONFLICT DO NOTHING |
| 不影响其他站点 | autoPushToSite() 按 site_id 分组，只处理 site_id=1 |
| 回滚方案 | 删除 sync_log 新增记录即可重新推送 |
| 先备份 | 执行前备份数据库 |

## 五、执行清单

- [ ] Step 1: 修改 routes/console.php 添加 cron.php 调度
- [ ] Step 2: 确认远端 sync_article.php 自动分类正常（已验证 ✅）
- [ ] Step 3: 手动执行 cron.php 补推 503 篇文章
- [ ] Step 4: 验证（article_sync_log + EyouCMS 后台 + URL 访问 + 分类正确）
- [ ] Step 5: 处理 /news/ 路径（可选）

## 六、后续优化

1. **cron.php 日志恢复**：确保 cron_2026-06-04.log 能正常写入
2. **article_sync_log 清理**：定期清理超过 30 天的记录
3. **图片同步**：确保 GEOFlow 文章中的图片在远端可访问
4. **/news/ CSS 修复**：如果保留 GEOFlow Agent 的 /news/ 页面，需要部署 CSS 文件
