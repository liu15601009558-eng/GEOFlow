# GEOFlow Agent Harness

## 项目概述

GEOFlow 是一个基于 Laravel 的内容生成和分发系统，用于自动化生成 SEO 文章并同步到多个目标网站。

- **框架**: Laravel 11 + PHP 8.4
- **数据库**: PostgreSQL (pgvector)
- **队列**: Redis + Laravel Horizon
- **容器化**: Docker Compose
- **端口**: Web 18080, PostgreSQL 15432, Redis 16379

## 快速启动

```bash
# 进入项目目录
cd ~/GEOFlow

# 启动所有服务
docker compose up -d

# 查看容器状态
docker ps --format "table {{.Names}}\t{{.Status}}"

# 查看日志
docker logs geo-system-web --tail 50
docker logs geo-system-queue --tail 50
docker logs geo-system-scheduler --tail 50
```

## 项目结构

```
GEOFlow/
├── app/
│   ├── Console/Commands/          # Artisan 命令
│   ├── Http/Controllers/Admin/    # 后台管理控制器
│   ├── Jobs/                      # 队列任务
│   ├── Models/                    # Eloquent 模型
│   └── Services/GeoFlow/         # 核心业务逻辑
├── config/                        # 配置文件
├── database/migrations/           # 数据库迁移
├── docker/                        # Docker 配置
├── public/                        # Web 根目录
├── resources/views/admin/         # 后台视图
├── routes/                        # 路由定义
├── storage/logs/                  # 日志文件
├── AGENTS.md                      # 本文件
├── feature_list.json              # 功能清单
├── init.sh                        # 初始化脚本
└── claude-progress.md             # 会话进度记录
```

## 关键文件

### 核心服务

| 文件 | 作用 |
|------|------|
| `app/Services/GeoFlow/WorkerExecutionService.php` | 文章生成核心逻辑 |
| `app/Services/GeoFlow/ArticleGeoFlowService.php` | 文章处理服务 |
| `app/Services/GeoFlow/DistributionOrchestrator.php` | 分发编排器 |
| `app/Services/GeoFlow/DistributionHttpClient.php` | HTTP 客户端 |
| `app/Services/GeoFlow/JobQueueService.php` | 队列任务服务 |
| `app/Services/GeoFlow/TaskLifecycleService.php` | 任务生命周期 |

### 数据模型

| 文件 | 表名 | 说明 |
|------|------|------|
| `app/Models/Task.php` | tasks | 任务配置 |
| `app/Models/Article.php` | articles | 生成的文章 |
| `app/Models/AiModel.php` | ai_models | AI 模型配置 |
| `app/Models/AiModelGroup.php` | ai_model_groups | 模型组 |
| `app/Models/AiModelGroupMember.php` | ai_model_group_members | 模型组成员 |
| `app/Models/TargetSite.php` | target_sites | 目标站点 |
| `app/Models/DistributionChannel.php` | distribution_channels | 分发通道 |
| `app/Models/ArticleDistribution.php` | article_distributions | 分发记录 |

### 队列任务

| 文件 | 作用 |
|------|------|
| `app/Jobs/ProcessGeoFlowTaskJob.php` | 处理文章生成任务 |
| `app/Jobs/ProcessArticleDistributionJob.php` | 处理文章分发任务 |

## 数据库表结构

### 核心表

```sql
-- tasks: 任务配置
-- 字段: id, name, site_id, ai_model_id, model_group_id, 
--        publish_interval, next_publish_at, schedule_enabled, status

-- articles: 生成的文章
-- 字段: id, title, content, task_id, status (draft/published), published_at

-- ai_models: AI 模型
-- 字段: id, name, model_id, api_url, api_key, status, daily_limit, used_today

-- ai_model_groups: 模型组
-- 字段: id, name, strategy (round_robin/failover), is_active

-- ai_model_group_members: 模型组成员
-- 字段: id, group_id, model_id, priority

-- target_sites: 目标站点
-- 字段: id, name, url

-- distribution_channels: 分发通道
-- 字段: id, name, domain, endpoint_url, channel_type, status

-- article_distributions: 分发记录
-- 字段: id, article_id, distribution_channel_id, status, remote_id

-- task_runs: 任务运行记录
-- 字段: id, task_id, article_id, status, started_at, finished_at, error_message
```

## 常见陷阱

### 1. WorkerExecutionService.php 类型错误

**问题**: `resolveAiModelCandidates()` 返回 `AiModelGroupMember` 实例，但下游方法要求 `AiModel` 类型

**错误信息**:
```
getAiModelUnavailableReason(): Argument #1 ($aiModel) must be of type 
App\Models\AiModel, App\Models\AiModelGroupMember given
```

**解决方法**: 使用 `AiModel::query()` 直接查询，而不是通过关系查询

### 2. 文章同步机制

**旧系统**: `article_sync_log` 表（已弃用）
**新系统**: `article_distributions` 表

查询分发状态时使用新表：
```sql
SELECT ad.id, ad.article_id, ad.status, ad.created_at
FROM article_distributions ad
WHERE ad.article_id = 123;
```

### 3. 发布调度机制

文章生成后状态为 `draft`，需要等待 `next_publish_at` 时间到达才会发布：

```sql
-- 检查任务的发布时间
SELECT id, name, next_publish_at, publish_interval
FROM tasks
WHERE status = 'active';
```

### 4. OPcache 问题

容器使用 PHP 内置服务器，代码修改后可能需要重启容器：

```bash
# 重启队列 worker（推荐）
docker exec geo-system-queue php /var/www/html/artisan queue:restart

# 或重启整个容器
docker restart geo-system-queue
```

### 5. 远程站点同步

远程站点使用 `sync_article.php` 接口，需要配置 Token：

```bash
# 测试远程站点连接
curl -X POST https://www.chengenyiliao.cn/sync_article.php \
  -H "Content-Type: application/json" \
  -H "X-Sync-Token: geoflow_sync_2026_ceyl" \
  -d '{"title":"test","content":"test","description":"test","category_id":0}'
```

## 常用命令

### Docker 操作

```bash
# 查看容器状态
docker ps --format "table {{.Names}}\t{{.Status}}"

# 查看日志
docker logs geo-system-web --tail 100
docker logs geo-system-queue --tail 100
docker logs geo-system-scheduler --tail 100

# 进入容器
docker exec -it geo-system-web bash

# 重启服务
docker restart geo-system-queue
docker restart geo-system-scheduler
```

### 数据库操作

```bash
# 连接数据库
docker exec geo-system-postgres psql -U geo_user -d geo_system

# 查看任务状态
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, name, status, next_publish_at FROM tasks WHERE status = 'active';"

# 查看最近文章
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, LEFT(title, 50), status, published_at FROM articles ORDER BY id DESC LIMIT 10;"

# 查看分发状态
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, article_id, status, created_at FROM article_distributions ORDER BY id DESC LIMIT 10;"

# 查看任务运行记录
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, task_id, article_id, status, started_at FROM task_runs ORDER BY id DESC LIMIT 10;"
```

### Laravel 命令

```bash
# 运行队列 worker
docker exec geo-system-queue php /var/www/html/artisan queue:work

# 运行调度器
docker exec geo-system-scheduler php /var/www/html/artisan schedule:work

# 运行迁移
docker exec geo-system-web php /var/www/html/artisan migrate

# 清除缓存
docker exec geo-system-web php /var/www/html/artisan cache:clear
docker exec geo-system-web php /var/www/html/artisan config:clear
docker exec geo-system-web php /var/www/html/artisan route:clear
```

## 环境变量

关键环境变量（在 `.env` 文件中）：

```
DB_HOST=geo-system-postgres
DB_PORT=5432
DB_DATABASE=geo_system
DB_USERNAME=geo_user
DB_PASSWORD=geo_fl...2024

REDIS_HOST=geo-system-redis
REDIS_PORT=6379

APP_URL=http://localhost:18080
```

## 故障排查

### 文章生成失败

```bash
# 检查任务运行记录
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, task_id, status, error_message FROM task_runs 
   WHERE status = 'failed' ORDER BY id DESC LIMIT 5;"

# 检查 AI 模型状态
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, name, status, daily_limit, used_today FROM ai_models WHERE status = 'active';"
```

### 文章分发失败

```bash
# 检查分发记录
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, article_id, status, last_error_message FROM article_distributions 
   WHERE status = 'failed' ORDER BY id DESC LIMIT 5;"

# 检查分发通道
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, name, domain, status FROM distribution_channels;"
```

### 队列任务积压

```bash
# 检查队列状态
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT queue, COUNT(*) FROM jobs GROUP BY queue;"

# 重启队列 worker
docker exec geo-system-queue php /var/www/html/artisan queue:restart
```

## 版本历史

- 2026-06-03: 初始版本，基于当前生产环境

---

**最后更新**: 2026-06-03
**维护者**: liupeng
