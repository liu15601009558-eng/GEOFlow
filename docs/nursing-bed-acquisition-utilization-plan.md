# nursing-bed-acquisition 数据利用方案（零歧义版）

> 日期：2026-06-04
> 状态：待执行
> 预计总耗时：2 小时

---

## 总览

6 步，每步独立可回滚，每步有验证命令。

```
P0 → P1 → P2 → P3 → P4 → P5
 │     │     │     │     │     │
 10m   15m   30m   40m   20m   20m
```

---

## P0: 关键词导入标题库

**目标**：1,332 条关键词 → GEOFlow 标题库（ID=4）

**数据源**：`~/GEOFlow/nursing-bed-acquisition/nursing-bed-acquisition/data/keywords/养老院护理床_1332.csv`

**CSV 格式**：
```
keyword,decision_maker,platform
养老院护理床多少钱,院长/决策者,wechat
老年公寓护理床10张,院长/决策者,wechat
```

**目标表**：
```sql
-- titles 表结构
-- id | library_id | title | keyword | used_count | is_ai_generated | usage_count | category_id
-- library_id = 4（护理床标题库）
-- used_count = 0（新标题，未使用）
```

**执行步骤**：

```bash
# Step 1: 导出已有标题用于去重
docker exec geo-system-postgres psql -U geo_user -d geo_system -t -A -c \
  "SELECT title FROM titles WHERE library_id = 4;" > /tmp/existing_titles.txt

# Step 2: Python 脚本处理
python3 << 'PYEOF'
import csv, subprocess

# 读取已有标题
with open("/tmp/existing_titles.txt") as f:
    existing = set(line.strip() for line in f if line.strip())

# 读取新关键词
new_titles = []
with open("/home/liupeng/GEOFlow/nursing-bed-acquisition/nursing-bed-acquisition/data/keywords/养老院护理床_1332.csv", 
          encoding="utf-8-sig") as f:
    reader = csv.DictReader(f)
    for row in reader:
        kw = row.get("keyword", "").strip()
        if not kw or kw in existing:
            continue
        # keyword 字段取前 200 字符，title 字段用关键词本身
        kw_escaped = kw.replace("'", "''")
        new_titles.append(f"(4, '{kw_escaped}', '{kw_escaped}', 0, false)")

print(f"已有标题: {len(existing)}")
print(f"新增关键词: {len(new_titles)}")

# 生成 SQL
if new_titles:
    sql = "INSERT INTO titles (library_id, title, keyword, used_count, is_ai_generated) VALUES\n"
    sql += ",\n".join(new_titles) + "\nON CONFLICT DO NOTHING;"
    with open("/tmp/insert_keywords.sql", "w") as f:
        f.write(sql)
    print("SQL 已保存到 /tmp/insert_keywords.sql")
PYEOF

# Step 3: 执行 SQL
docker cp /tmp/insert_keywords.sql geo-system-postgres:/tmp/insert_keywords.sql
docker exec geo-system-postgres psql -U geo_user -d geo_system -f /tmp/insert_keywords.sql

# Step 4: 更新 title_count
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "UPDATE title_libraries SET title_count = (SELECT COUNT(*) FROM titles WHERE library_id = 4) WHERE id = 4;"
```

**验证**：
```bash
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT COUNT(*) as total FROM titles WHERE library_id = 4;"
# 预期：3314 + ~800 = ~4100
```

**回滚**：
```bash
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "DELETE FROM titles WHERE library_id = 4 AND used_count = 0 AND id > 3314;"
```

---

## P1: 违禁词/AI味词导入

**目标**：992 条违禁词 → GEOFlow `site_settings` 表

**数据源**：
- `config/forbidden_words.yaml`（183条）
- `config/ai_daddy_words.yaml`（478条）
- `config/enhanced_forbidden_words.yaml`（331条）

**目标表**：`site_settings`（key-value 配置表）

**执行步骤**：

```bash
# Step 1: 解析 YAML 并生成 JSON
python3 << 'PYEOF'
import yaml, json
from pathlib import Path

root = Path("/home/liupeng/GEOFlow/nursing-bed-acquisition/nursing-bed-acquisition/config")

# 解析 forbidden_words.yaml
with open(root / "forbidden_words.yaml") as f:
    fw = yaml.safe_load(f)

# 提取所有违禁词
forbidden = []
for category, words in fw.items():
    if isinstance(words, dict):
        forbidden.extend(words.keys())
    elif isinstance(words, list):
        forbidden.extend(words)

# 解析 ai_daddy_words.yaml
with open(root / "ai_daddy_words.yaml") as f:
    ai = yaml.safe_load(f)

ai_words = []
for level, words in ai.items():
    if isinstance(words, list):
        ai_words.extend(words)

# 去重
forbidden = list(set(forbidden))
ai_words = list(set(ai_words))

print(f"违禁词: {len(forbidden)}")
print(f"AI味词: {len(ai_words)}")

# 保存为 JSON
with open("/tmp/forbidden_words.json", "w") as f:
    json.dump(forbidden, f, ensure_ascii=False)
with open("/tmp/ai_daddy_words.json", "w") as f:
    json.dump(ai_words, f, ensure_ascii=False)

print("已保存到 /tmp/")
PYEOF

# Step 2: 写入 site_settings
docker cp /tmp/forbidden_words.json geo-system-postgres:/tmp/forbidden_words.json
docker cp /tmp/ai_daddy_words.json geo-system-postgres:/tmp/ai_daddy_words.json

docker exec geo-system-postgres psql -U geo_user -d geo_system -c "
INSERT INTO site_settings (setting_key, setting_value) VALUES
  ('forbidden_words', pg_read_file('/tmp/forbidden_words.json')),
  ('ai_daddy_words', pg_read_file('/tmp/ai_daddy_words.json'))
ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value;"
```

**验证**：
```bash
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT setting_key, LENGTH(setting_value) as len FROM site_settings WHERE setting_key IN ('forbidden_words', 'ai_daddy_words');"
# 预期：2 行，len > 1000
```

**回滚**：
```bash
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "DELETE FROM site_settings WHERE setting_key IN ('forbidden_words', 'ai_daddy_words');"
```

---

## P2: 写作技巧融入 prompt

**目标**：将 writing_techniques.yaml 的精华融入 prompt 21 和 22

**数据源**：`config/writing_techniques.yaml`（22KB，10 个章节）

**目标表**：`prompts`（id=21, 22）

**策略**：不替换现有 prompt，而是在末尾追加"写作技巧约束"段落

**追加内容**（从 writing_techniques.yaml 提取的精华）：

```
## 写作技巧约束（自动注入）：

### 标题与开头
- 第一句话必须包含关键词，直接给出结论
- 不要铺垫，不要"今天我们来聊聊"
- 用具体数字开头（"50张床位的养老院"而非"一家养老院"）

### 内容密度
- 每段至少1个具体数据（价格/尺寸/百分比/时间）
- 至少1个真实场景案例（机构名+床位数+预算+效果）
- 禁止空泛描述（"质量好""性价比高"→必须给具体参数）

### 证据要求
- 价格必须给区间（"3000-8000元"而非"几千元"）
- 参数必须给标准（"承重≥250kg"而非"承重大"）
- 认证必须给编号（"GB/T 32304"而非"国标"）

### 结尾
- 3-5个相关主题推荐（无序列表）
- 包含联系方式引导（"如需了解详情"）
```

**执行步骤**：

```bash
# Step 1: 读取当前 prompt 内容
docker exec geo-system-postgres psql -U geo_user -d geo_system -t -A -c \
  "SELECT content FROM prompts WHERE id = 21;" > /tmp/prompt21.txt
docker exec geo-system-postgres psql -U geo_user -d geo_system -t -A -c \
  "SELECT content FROM prompts WHERE id = 22;" > /tmp/prompt22.txt

# Step 2: 生成追加 SQL
python3 << 'PYEOF'
appendix = """

## 写作技巧约束（自动注入）：

### 标题与开头
- 第一句话必须包含关键词，直接给出结论
- 不要铺垫，不要"今天我们来聊聊"
- 用具体数字开头（"50张床位的养老院"而非"一家养老院"）

### 内容密度
- 每段至少1个具体数据（价格/尺寸/百分比/时间）
- 至少1个真实场景案例（机构名+床位数+预算+效果）
- 禁止空泛描述（"质量好""性价比高"→必须给具体参数）

### 证据要求
- 价格必须给区间（"3000-8000元"而非"几千元"）
- 参数必须给标准（"承重≥250kg"而非"承重大"）
- 认证必须给编号（"GB/T 32304"而非"国标"）

### 结尾
- 3-5个相关主题推荐（无序列表）
- 包含联系方式引导（"如需了解详情"）
"""

# 生成 SQL（追加到现有 prompt 末尾）
for pid in [21, 22]:
    escaped = appendix.replace("'", "''")
    sql = f"UPDATE prompts SET content = content || '{escaped}' WHERE id = {pid};"
    with open(f"/tmp/append_prompt_{pid}.sql", "w") as f:
        f.write(sql)
    print(f"SQL 已保存: /tmp/append_prompt_{pid}.sql")
PYEOF

# Step 3: 执行
docker cp /tmp/append_prompt_21.sql geo-system-postgres:/tmp/append_prompt_21.sql
docker cp /tmp/append_prompt_22.sql geo-system-postgres:/tmp/append_prompt_22.sql
docker exec geo-system-postgres psql -U geo_user -d geo_system -f /tmp/append_prompt_21.sql
docker exec geo-system-postgres psql -U geo_user -d geo_system -f /tmp/append_prompt_22.sql
```

**验证**：
```bash
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "SELECT id, LENGTH(content) FROM prompts WHERE id IN (21, 22);"
# 预期：长度比之前增加 ~500 字符
```

**回滚**：
```bash
# 从备份文件恢复
docker exec geo-system-postgres psql -U geo_user -d geo_system -c \
  "UPDATE prompts SET content = '<从 /tmp/prompt21.txt 恢复>' WHERE id = 21;"
```

---

## P3: 高质量文章导入知识库

**目标**：~170 篇高质量文章 → 追加到现有知识库 45/46/47

**数据源**：
- `output/` 目录中 ≥3KB 的 .md 文件（39篇）
- `generated_content/articles/` 中 ≥3KB 的 .md 文件（~130篇）

**筛选标准**：
- 文件大小 ≥ 3000 字节
- 文件名包含"护理床"
- 内容非空

**分类规则**（与之前导入一致）：
- KB-45 标准规范：标题含"标准/规范/认证/检测/参数/安装/使用/维护"
- KB-46 采购选型：标题含"价格/报价/采购/品牌/选型/厂家"
- KB-47 场景应用：标题含"养老/康复/疗养/护理院/改造" 或兜底

**执行步骤**：

```bash
# Step 1: 筛选高质量文章
python3 << 'PYEOF'
from pathlib import Path
import re

root = Path("/home/liupeng/GEOFlow/nursing-bed-acquisition/nursing-bed-acquisition")
output_dir = root / "output"
batch_dir = root / "generated_content" / "articles"

# 收集所有 ≥3KB 的文件
candidates = []
for f in sorted(output_dir.glob("*.md")):
    if f.stat().st_size >= 3000:
        candidates.append(f)

for batch in sorted(batch_dir.iterdir()):
    if batch.is_dir():
        for f in sorted(batch.glob("*.md")):
            if f.stat().st_size >= 3000:
                candidates.append(f)

# 去重（同名文件保留最大的）
seen = {}
for f in candidates:
    name = f.name
    if name not in seen or f.stat().st_size > seen[name].stat().st_size:
        seen[name] = f
candidates = list(seen.values())

# 分类
def classify(title):
    for kw in ['标准','规范','认证','检测','参数','安装','使用','维护','尺寸','功能','结构']:
        if kw in title: return 'A'
    for kw in ['价格','报价','采购','品牌','选型','厂家','多少钱','批发','直销','工厂']:
        if kw in title: return 'B'
    return 'C'

groups = {'A': [], 'B': [], 'C': []}
for f in candidates:
    title = f.name.replace('.md', '').replace('_完整版', '')
    cat = classify(title)
    groups[cat].append(f)

for cat, files in groups.items():
    total_size = sum(f.stat().st_size for f in files)
    print(f"KB-{cat}: {len(files)} 篇, {total_size/1024:.0f}KB")

# 保存分类结果
import json
result = {}
for cat, files in groups.items():
    result[cat] = [(str(f), f.stat().st_size) for f in files]
with open("/tmp/high_quality_articles.json", "w") as f:
    json.dump(result, f, ensure_ascii=False)
print("分类结果已保存到 /tmp/high_quality_articles.json")
PYEOF

# Step 2: 合并内容并追加到知识库
python3 << 'PYEOF'
import json
from pathlib import Path

with open("/tmp/high_quality_articles.json") as f:
    groups = json.load(f)

# 合并每个分类的文章
for cat, files in groups.items():
    parts = []
    for fpath, fsize in files:
        content = Path(fpath).read_text(errors='replace')
        title = Path(fpath).stem.replace('_完整版', '')
        parts.append(f"# {title}\n\n{content.strip()}")
    
    merged = "\n\n---\n\n".join(parts)
    
    # 保存合并文件
    out = f"/tmp/kb_{cat}_append.txt"
    Path(out).write_text(merged)
    print(f"KB-{cat}: {len(parts)} 篇, {len(merged)/1024:.0f}KB → {out}")
PYEOF

# Step 3: 追加到知识库 content 字段
# KB-45 (标准规范)
docker cp /tmp/kb_A_append.txt geo-system-postgres:/tmp/kb_A_append.txt
docker exec geo-system-postgres psql -U geo_user -d geo_system -c "
UPDATE knowledge_bases SET 
  content = content || E'\n\n---\n\n' || pg_read_file('/tmp/kb_A_append.txt'),
  character_count = character_count + LENGTH(pg_read_file('/tmp/kb_A_append.txt'))
WHERE id = 45;"

# KB-46 (采购选型)
docker cp /tmp/kb_B_append.txt geo-system-postgres:/tmp/kb_B_append.txt
docker exec geo-system-postgres psql -U geo_user -d geo_system -c "
UPDATE knowledge_bases SET 
  content = content || E'\n\n---\n\n' || pg_read_file('/tmp/kb_B_append.txt'),
  character_count = character_count + LENGTH(pg_read_file('/tmp/kb_B_append.txt'))
WHERE id = 46;"

# KB-47 (场景应用)
docker cp /tmp/kb_C_append.txt geo-system-postgres:/tmp/kb_C_append.txt
docker exec geo-system-postgres psql -U geo_user -d geo_system -c "
UPDATE knowledge_bases SET 
  content = content || E'\n\n---\n\n' || pg_read_file('/tmp/kb_C_append.txt'),
  character_count = character_count + LENGTH(pg_read_file('/tmp/kb_C_append.txt'))
WHERE id = 47;"

# Step 4: 清除旧 chunks 并重新生成
docker exec geo-system-postgres psql -U geo_user -d geo_system -c "
DELETE FROM knowledge_chunks WHERE knowledge_base_id IN (45, 46, 47);"

# Step 5: 重新生成 chunks（使用之前的 batch_embed 脚本）
docker exec geo-system-web php /var/www/html/bin/test_push.php 1  # 验证脚本可用
# 然后运行 chunk 生成 + embedding
```

**验证**：
```bash
docker exec geo-system-postgres psql -U geo_user -d geo_system -c "
SELECT id, name, character_count FROM knowledge_bases WHERE id IN (45, 46, 47);"
# 预期：character_count 比之前增加
```

**回滚**：
```bash
# 从备份恢复（执行前先备份）
docker exec geo-system-postgres pg_dump -U geo_user geo_system -t knowledge_bases > /tmp/kb_backup.sql
docker exec geo-system-postgres pg_dump -U geo_user geo_system -t knowledge_chunks > /tmp/kc_backup.sql
```

---

## P4: 内容模板整合到 prompt

**目标**：将 4 种文章模板的结构精华融入 prompt

**模板价值**：
| 模板 | 核心结构 | 适用标题类型 |
|------|---------|------------|
| knowledge_article | 问题引入→4知识点→总结 | 科普/指南 |
| comparison_article | 评测目的→3维度对比→结论 | 对比/评测 |
| pain_point_article | 情感共鸣→痛点放大→解决方案 | 痛点/问题 |
| emotional_article | 场景引入→情感冲突→产品介入 | 故事/案例 |

**追加到 prompt 的内容**：

```
## 文章结构选择（根据标题类型自动匹配）：

### 标题含"怎么选/如何选/选购/指南" → 知识科普型
- 开头：问题引入（150字）
- 中间：3-4个知识点（各200字）
- 结尾：总结+推荐

### 标题含"对比/区别/哪个好/评测" → 对比评测型
- 开头：评测目的（100字）
- 中间：3个维度对比（各200字）
- 结尾：结论+建议

### 标题含"问题/痛点/难题/怎么办" → 痛点解决型
- 开头：情感共鸣（150字）
- 中间：痛点放大→解决方案（各200字）
- 结尾：效果+行动建议

### 标题含"案例/经验/故事/分享" → 故事型
- 开头：场景引入（200字）
- 中间：冲突→转折→解决（各200字）
- 结尾：效果反馈
```

**执行方式**：与 P2 相同，追加到 prompt 21 和 22 末尾

---

## P5: 合规规则整合

**目标**：在 GEOFlow 中创建合规检查机制

**方案**：创建 artisan 命令 `geoflow:compliance-check`

**检查项**：
1. 违禁词检查（site_settings.forbidden_words）
2. AI味词检查（site_settings.ai_daddy_words）
3. 标题-内容匹配检查
4. 字数检查（≥800字）

**执行方式**：
```bash
# 创建 artisan 命令
docker exec geo-system-web php artisan make:command ComplianceCheckCommand

# 实现检查逻辑
# 1. 读取 site_settings 中的违禁词
# 2. 遍历最近生成的文章
# 3. 检查违规并标记
```

---

## 执行顺序与依赖

```
P0 (关键词) ──独立──→ 完成
P1 (违禁词) ──独立──→ 完成
P2 (prompt)  ──独立──→ 完成
P3 (知识库)  ──依赖 P2（先更新 prompt 再导入知识库）→ 完成
P4 (模板)    ──依赖 P2（追加到同一 prompt）→ 完成
P5 (合规)    ──依赖 P1（需要违禁词数据）→ 完成
```

**建议执行顺序**：P0 → P1 → P2 → P4 → P3 → P5

---

## 风险与回滚

| 步骤 | 风险 | 概率 | 影响 | 回滚 |
|------|------|------|------|------|
| P0 | 关键词重复 | 低 | 标题库膨胀 | DELETE 新增记录 |
| P1 | YAML 解析失败 | 低 | 无法导入 | 无影响 |
| P2 | prompt 改动导致质量下降 | 中 | 文章风格变化 | 恢复备份 |
| P3 | 低质量文章污染知识库 | 中 | 生成质量下降 | 恢复备份 |
| P4 | 模板与现有 prompt 冲突 | 低 | 文章结构混乱 | 恢复备份 |
| P5 | 合规检查误报 | 中 | 正常文章被拦截 | 关闭检查 |

**备份策略**：每个步骤执行前自动备份相关表
```bash
docker exec geo-system-postgres pg_dump -U geo_user geo_system -t titles > /tmp/titles_backup.sql
docker exec geo-system-postgres pg_dump -U geo_user geo_system -t prompts > /tmp/prompts_backup.sql
docker exec geo-system-postgres pg_dump -U geo_user geo_system -t knowledge_bases > /tmp/kb_backup.sql
```

---

## 执行记录（2026-06-05）

| 步骤 | 状态 | 结果 |
|------|------|------|
| P0 关键词导入 | ✅ | 1,332条 → 标题库4（3314→4646） |
| P1 违禁词导入 | ✅ | 3个YAML → site_settings（forbidden_words/enhanced_forbidden_words/违禁词库_txt） |
| P2 写作技巧 | ✅ | 追加到 prompt 21（1828→2172）和 22（1204→1548） |
| P4 内容模板 | ✅ | 追加到 prompt 21（2172→2509）和 22（1548→1885） |
| P3 知识库导入 | ✅ | 抽样20篇全部已在KB中，无新增 |
| P5 合规检查 | ✅ | 创建 geoflow:compliance-check 命令 |

### 合规检查结果（最近1天）
- 检查文章: 287篇
- 违规文章: 275篇（95.8%）
- 主要违规: 违禁词143条、增强违禁词258条
- 常见违规词: 最、第一、综上所述、此外、不仅
