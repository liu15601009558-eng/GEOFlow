# 护理床文章导入 GEOFlow 知识库 — 完善方案

> 创建日期：2026-06-04
> 状态：待审批
> 文件路径：~/GEOFlow/docs/nursing-bed-kb-import-plan.md

---

## 一、现状全景

### 1.1 源文件分析

| 维度 | 数值 |
|------|------|
| 总文件数 | 909 个 |
| 有效文件（≥3KB，阿拉伯编号） | 708 个 |
| 中文数字编号文章（第一篇~第二十二篇） | 22 篇（质量好，21-32KB） |
| 早期测试文件（article_001-003） | 3 个（7-11KB，可用） |
| 占位/空文件（<500B） | 155 个 |
| 偏短文件（500B-3KB） | 41 个（含6个生成报告） |
| 重复编号 | 2 组（第303篇×2，第576篇×2） |
| 有效文章总计 | **733 篇**（708 + 22 + 3） |
| 总有效内容量 | ~5.2 MB |
| 平均单篇大小 | ~7.1 KB |

### 1.2 GEOFlow 知识库机制

```
文章生成时的知识注入流程：

  task.knowledge_base_id
         │
         ▼
  knowledge_bases.content  ←  全文存储（所有文章合并为一个文本）
         │
         ▼
  KnowledgeChunkSyncService::sync()
    ├── splitStructuredBlocks()  ←  按 markdown 标题（#/##/###）切块
    ├── expandOversizedBlocks()  ←  超大块拆分
    ├── buildStructuredRuleChunks()  ←  按 chunkMaxChars（默认900字）组装
    └── generateEmbeddingsForChunks()  ←  调用 NVIDIA NIM 生成向量
         │
         ▼
  knowledge_chunks 表（chunk_index, content, embedding_vector）
         │
         ▼
  查询时：将标题+关键词 → embedding → pgvector 余弦相似度 → top 4 chunks
         │
         ▼
  拼接为 ≤2400 字的上下文 → 注入到 prompt 的 {{Knowledge}} 变量
```

**关键参数：**

| 参数 | 值 | 说明 |
|------|-----|------|
| chunkMaxChars | 900 | 每个 chunk 的最大字符数（site_settings 未配置，用默认值） |
| 检索数量 | 4 | 每次取 top 4 个 chunk |
| 注入上限 | 2400 字符 | 知识上下文最大长度 |
| 存储维度 | 3072 | vector(3072) 列，不足补零 |
| Embedding 模型 | nvidia/llama-nemotron-embed-1b-v2 | 输出 2048 维，padding 到 3072 |
| 分块策略 | structured_rule | 按 markdown 标题分块（默认） |

### 1.3 护理床任务现状

| 任务ID | 任务名 | knowledge_base_id | prompt_id | 知识注入支持 |
|--------|--------|-------------------|-----------|-------------|
| 8 | 护理床 | **NULL** ⚠️ | 21 | ✅ {{#if Knowledge}} |
| 9 | 护理床1 | **NULL** ⚠️ | 22 | ✅ {{#if Knowledge}} |
| 17 | 护理床-2 | **NULL** ⚠️ | 21 | ✅ {{#if Knowledge}} |
| 18 | 护理床-3 | **NULL** ⚠️ | 22 | ✅ {{#if Knowledge}} |

**核心问题：4 个任务全部没有关联知识库，prompt 支持 {{Knowledge}} 但从未被注入内容。**

**补充说明：`task_materials` 表存在关联记录（task 9 关联了 8 个知识库），但代码只读取 `tasks.knowledge_base_id`，`task_materials` 仅用于删除任务时的级联清理，不参与知识检索。**

### 1.4 现有护理床知识库

已有 17 个护理床相关知识库（id 14-40），总 chunk 数约 200 个。

| 类型 | 知识库ID | chunk数 | 已关联任务 |
|------|----------|---------|-----------|
| 产品参数 | 22, 26 | 49+7 | task_materials(task 8,9) |
| 招标情报 | 27 | 4 | task_materials(task 9) |
| 政策法规 | 28, 32 | 5+16 | task_materials(task 9) |
| 运营风控 | 29, 30, 33, 35 | 8+6+9+14 | task_materials(task 9) |
| SEO方法论 | 14, 23, 34 | 46+69+42 | 无 |
| 护理知识 | 13, 40, 15 | 2+8+5 | 无 |
| 其他 | 18-21, 24-25 | 8+8+2+2+3+3 | 无 |

**这些知识库都没有通过 `tasks.knowledge_base_id` 关联到任何护理床任务。**

### 1.5 Prompt 模板分析

**Prompt 21（护理床-临床应用护理流程视角）：**
```
{{#if Knowledge}}
## 参数调用：
请将下方知识库中的真实参数自然融入文章，禁止编造数据。
- 产品参数：从知识库提取
- 认证信息：从知识库提取
- 价格区间：从知识库提取
- 客户案例：从知识库提取
{{/if}}
```

**Prompt 22（护理床-适老化改造康养运营视角）：**
```
{{#if Knowledge}}
## 💡 适老化知识库（强制调用）：
{{Knowledge}}
{{/if}}
```

两个 prompt 都支持知识注入，但因为 knowledge_base_id 为 NULL，
{{Knowledge}} 变量始终为空字符串，条件块不渲染。

---

## 二、方案设计

### 2.1 核心策略

**创建 3 个新知识库，每个任务关联 1 个最匹配的知识库。**

**为什么不合并成 1 个大知识库？**
- 733 篇 → ~5800 个 chunk → pgvector 搜索噪音大
- 不同主题混合（标准规范 vs 采购价格 vs 场景应用），检索精度差
- top-4 命中率低（5800 个 chunk 中只取 4 个）

**为什么不创建 5+ 个知识库？**
- 系统限制：每个任务只能关联 1 个 knowledge_base_id
- 3 个任务（8/17/18）各关联 1 个，task 9 关联另 1 个
- 3 个知识库已经足够按主题分组，粒度适中

**为什么不合并现有 17 个知识库？**
- 现有知识库是精心编写的专项内容（参数、招标、政策等）
- 新导入的是批量生成的文章，内容和用途不同
- 合并会破坏现有知识库的结构和 chunk 质量
- 本次只做新文章导入，不动现有数据

### 2.2 知识库分组方案

| 知识库 | 内容主题 | 分类规则 | 预估篇数 | 预估chunk数 | 关联任务 |
|--------|---------|---------|---------|------------|---------|
| **KB-A** 护理床-标准规范与产品参数库 | 标准/规范/认证/检测/验收/材质/尺寸/功能/结构 | 标题含：标准、规范、认证、检测、验收、质检、材质、尺寸、功能、结构、安装、使用、维护、保养、报废、消防、安全、ISO、CE、专利、参数 | ~180篇 | ~1400 | 任务8, 17 |
| **KB-B** 护理床-采购选型与价格库 | 采购/价格/报价/品牌/选型/批发/成本/预算 | 标题含：价格、报价、采购、批发、成本、多少钱、预算、费用、造价、招标、品牌、选型、选购、怎么选、哪里买、排名、对比、推荐、厂家、供应商、工厂、经销 | ~250篇 | ~2000 | 任务9 |
| **KB-C** 护理床-场景应用与适老改造库 | 场景/适老化/改造/养老机构/康复/护理院 | 标题含：适老化、改造、升级、养老院、养老机构、敬老院、老年公寓、康复、疗养、护理院、福利院、日间照料、托养、养护、临终关怀、社区养老、居家养老 | ~200篇 | ~1600 | 任务18 |
| **兜底** 归入 KB-C | 无法匹配上述规则的文章 | 无匹配关键词 | ~103篇 | ~800 | — |

### 2.3 分类优先级规则

当文章标题同时匹配多个分类时，按以下优先级归类：

```
优先级 1: 标题同时含"标准/规范/认证" → 归入 KB-A
优先级 2: 标题同时含"价格/报价/多少钱/采购" → 归入 KB-B
优先级 3: 标题含场景类关键词 → 归入 KB-C
优先级 4: 无法匹配 → 归入 KB-C（兜底）
```

### 2.4 内容格式

每个知识库的 content 字段格式：

```markdown
# 第1篇_疗养院护理床多少钱_完整版

（文章正文，保留原始 markdown 格式）

---

# 第2篇_老年公寓护理床10张_完整版

（文章正文）

---
```

- 每篇文章用 `# 第X篇_标题_完整版` 作为 H1 标题
- 文章之间用 `---` 分隔
- 保留原始 markdown 格式（列表、表格、加粗等）
- 不修改文章内容

**分块行为：**
- `splitStructuredBlocks()` 按 H1 标题切分 → 每篇文章成为独立块
- 如果单篇文章 > 900 字（chunkMaxChars），会进一步按 H2/H3 拆分
- 平均每篇 7KB ≈ 8-10 个 chunk

### 2.5 去重与质量过滤

**过滤规则（按优先级执行）：**

```
第1轮：基础过滤
  ❌ 文件大小 = 0 → 排除（1个：第135篇）
  ❌ 文件大小 < 500B → 排除（154个占位文件）
  ❌ 文件名不含"篇"字 → 排除（6个生成报告 + 1个公众号版本 + 1个文件路径确认）

第2轮：重复处理
  ⚠️ 第303篇有两个文件：
     第303篇_公办养老机构护理床_完整版.md (11892B)
     第303篇_护理院护理床多少钱一台_完整版.md (11980B)
     → 两个都保留（标题不同，内容不同，重新编号）

  ⚠️ 第576篇有两个文件：
     第576篇_护理床保修_完整版.md (8660B)
     第576篇_护理床护栏_完整版.md (720B)
     → 只保留 8660B 的（720B 是占位文件）

第3轮：最终筛选
  ✅ 文件大小 ≥ 3KB
  ✅ 文件名包含"篇"字（阿拉伯数字或中文数字）
  ✅ 非报告/非测试文件
```

**预估结果：**
- 排除后剩余：~730 篇
- 分入 KB-A：~180 篇
- 分入 KB-B：~250 篇
- 分入 KB-C：~300 篇（含兜底）

---

## 三、技术实现

### 3.1 执行步骤

```
Step 1: 文件扫描与分类（Python 脚本，本地执行）
  ├── 扫描 ~/GEOFlow/nursing-bed-articles/output/*.md
  ├── 提取文件名中的编号（阿拉伯/中文数字统一转换）
  ├── 按 2.5 节规则过滤
  ├── 按 2.2 节规则分类
  ├── 处理重复编号（第303篇、第576篇）
  └── 输出 3 个分类列表 + 统计报告

Step 2: 内容合并（Python 脚本，本地执行）
  ├── 按分类读取每个 .md 文件
  ├── 按 2.4 节格式合并为 3 个大文本
  ├── 计算每个文本的 character_count / word_count
  └── 输出 3 个合并后的文本文件

Step 3: 创建知识库记录（SQL，Docker 容器内执行）
  ├── INSERT INTO knowledge_bases (name, content, category_id, character_count, word_count, file_type)
  ├── 创建 3 条记录，获取新 ID
  └── 记录新知识库 ID（假设为 KB_A_ID, KB_B_ID, KB_C_ID）

Step 4: 分块与向量化（PHP artisan 命令，Docker 容器内执行）
  ├── 对每个知识库调用 KnowledgeChunkSyncService::sync()
  ├── 自动分块（按 H1 标题 + chunkMaxChars=900）
  ├── 自动调用 NVIDIA NIM 生成 embedding
  ├── 写入 knowledge_chunks 表
  └── 预计 chunk 总数：~4200 个

Step 5: 任务关联（SQL，Docker 容器内执行）
  ├── UPDATE tasks SET knowledge_base_id = KB_A_ID WHERE id IN (8, 17);
  ├── UPDATE tasks SET knowledge_base_id = KB_B_ID WHERE id = 9;
  └── UPDATE tasks SET knowledge_base_id = KB_C_ID WHERE id = 18;

Step 6: 验证（SQL + 实际生成测试）
  ├── 检查 chunk 数量
  ├── 检查 embedding 覆盖率
  ├── 测试 pgvector 检索效果
  └── 抽查任务生成时 {{Knowledge}} 是否有内容
```

### 3.2 详细执行命令

#### Step 1-2: Python 脚本

```python
import os, re
from pathlib import Path

SRC = Path("/home/liupeng/GEOFlow/nursing-bed-articles/output")
OUT = Path("/home/liupeng/GEOFlow/nursing-bed-articles/merged")

# 中文数字转阿拉伯数字映射
CN_NUM = {'一':1,'二':2,'三':3,'四':4,'五':5,'六':6,'七':7,'八':8,'九':9,'十':10,
           '十一':11,'十二':12,'十三':13,'十四':14,'十五':15,'十六':16,'十七':17,
           '十八':18,'十九':19,'二十':20,'二十一':21,'二十二':22}

# 分类规则
CATEGORIES = {
    'A': ['标准','规范','认证','检测','验收','质检','材质','尺寸','功能','结构',
          '安装','使用','维护','保养','报废','消防','安全','专利','参数','型号',
          '承重','护栏','电气','噪音','环保','质量','证书','检测报告','操作说明'],
    'B': ['价格','报价','采购','批发','成本','多少钱','预算','费用','造价','招标',
          '品牌','选型','选购','怎么选','哪里买','排名','对比','推荐','厂家',
          '供应商','工厂','经销','代理','直销','出厂价','最低价','最便宜','便宜',
          '合同','标书','报价单','清单','配置清单','方案'],
    'C': ['适老化','改造','升级','养老院','养老机构','敬老院','老年公寓','康复',
          '疗养','护理院','福利院','日间照料','托养','养护','临终关怀','社区养老',
          '居家养老','养老公寓','养老照护','养老堂','敬老']
}

def classify(title):
    """按优先级分类"""
    for kw in CATEGORIES['A']:
        if kw in title: return 'A'
    for kw in CATEGORIES['B']:
        if kw in title: return 'B'
    for kw in CATEGORIES['C']:
        if kw in title: return 'C'
    return 'C'  # 兜底

def extract_number(filename):
    """提取文章编号（阿拉伯/中文统一转为数字）"""
    m = re.search(r'第(\d+)篇', filename)
    if m: return int(m.group(1))
    for cn, num in CN_NUM.items():
        if f'第{cn}篇' in filename: return num
    return None

# ... 后续扫描、过滤、分类、合并逻辑
```

#### Step 3: SQL 创建知识库

```sql
-- 创建知识库 A
INSERT INTO knowledge_bases (name, content, category_id, character_count, word_count, file_type, description)
VALUES (
  '护理床-标准规范与产品参数库',
  '（Step 2 生成的合并文本）',
  1,  -- 产品参数分类
  0,  -- 后续更新
  0,  -- 后续更新
  'markdown',
  '批量导入：733篇护理床文章中的标准规范、产品参数、认证检测类文章合集'
) RETURNING id;  -- 假设返回 KB_A_ID

-- 创建知识库 B（同上，category_id = 1）
-- 创建知识库 C（同上，category_id = 5）

-- 更新 character_count 和 word_count
UPDATE knowledge_bases SET
  character_count = LENGTH(content),
  word_count = LENGTH(content)  -- 中文按字符计
WHERE id IN (KB_A_ID, KB_B_ID, KB_C_ID);
```

#### Step 4: 分块与向量化

```bash
# 通过 artisan 命令或直接调用服务
docker exec geo-system-web php artisan tinker --execute="
  \$service = app(\App\Services\GeoFlow\KnowledgeChunkSyncService::class);
  \$kb = \App\Models\KnowledgeBase::find(KB_A_ID);
  \$count = \$service->sync(\$kb->id, \$kb->content);
  echo 'KB-A chunks: ' . \$count;
"
```

#### Step 5: 任务关联

```sql
-- 任务 8（护理床）和 17（护理床-2）→ KB-A（标准规范库）
UPDATE tasks SET knowledge_base_id = KB_A_ID WHERE id IN (8, 17);

-- 任务 9（护理床1）→ KB-B（采购选型库）
UPDATE tasks SET knowledge_base_id = KB_B_ID WHERE id = 9;

-- 任务 18（护理床-3）→ KB-C（场景应用库）
UPDATE tasks SET knowledge_base_id = KB_C_ID WHERE id = 18;
```

### 3.3 Embedding 向量化注意事项

| 问题 | 现状 | 应对 |
|------|------|------|
| 维度不一致 | 现有 chunk 有 3072(nvidia_nim) 和 2048(integrate.api.nvidia.com) 两种 | 新导入统一用 nvidia_nim，自动 padding 到 3072 |
| default_embedding_model_id | = 0（未配置） | 系统自动选择可用模型，不影响 |
| API 速率限制 | NVIDIA NIM 有 QPS 限制 | 脚本内置重试 + 延迟（每 chunk 间隔 0.5s） |
| Fallback 向量 | embedding 失败时用 hash 向量 | 允许，不影响流程；后续可在 admin 后台手动"更新切片"重新生成 |
| 预计 embedding 耗时 | ~4200 chunks × 0.5s | ~35 分钟 |

---

## 四、边缘情况处理

### 4.1 重复编号文章

| 编号 | 文件A | 文件B | 处理 |
|------|-------|-------|------|
| 第303篇 | 公办养老机构护理床(11892B) | 护理院护理床多少钱一台(11980B) | 两个都保留，按内容分类到不同知识库 |
| 第576篇 | 护理床保修(8660B) | 护理床护栏(720B) | 只保留 8660B 的，720B 是占位文件 |

### 4.2 中文数字编号文章

22 篇早期文章使用中文数字（第一篇~第二十二篇），内容质量好（21-32KB），
与阿拉伯编号文章有部分重叠（如"第一篇_疗养院护理床多少钱"≈"第1篇_疗养院护理床多少钱"）。

**处理策略：**
- 如果阿拉伯编号版本存在且 ≥ 3KB → 跳过中文数字版本
- 如果阿拉伯编号版本不存在或 < 3KB → 使用中文数字版本

### 4.3 早期测试文件

| 文件 | 大小 | 处理 |
|------|------|------|
| article_001_护理床适老化补贴政策.md | 7KB | 与第135篇（空文件）标题相似，保留 article_001 |
| article_002_护理床适老化补贴申请.md | 10KB | 保留 |
| article_003_护理床适老化改造方案.md | 11KB | 保留 |

### 4.4 知识库过大导致检索精度下降

**监控指标：**
- 每个知识库 chunk 数量 < 2000
- 如果超过，考虑拆分或提高 chunkMaxChars

**当前预估：**
- KB-A: ~1400 chunks ✅
- KB-B: ~2000 chunks ⚠️ 临界值
- KB-C: ~2400 chunks ⚠️ 偏多

**应对：如果 KB-B 或 KB-C 的 chunk 数超过 2000，可以：**
1. 提高 chunkMaxChars 到 1200（减少 chunk 数约 25%）
2. 或将 KB-C 拆分为"场景应用"和"适老改造"两个知识库

---

## 五、验收标准

### 5.1 数据完整性

- [ ] 3 个知识库创建完成，content 字段非空
- [ ] 有效文章导入数 ≥ 700（允许 -5% 误差）
- [ ] 每个知识库的 character_count > 0
- [ ] 无重复文章（同一篇文章不同时出现在两个知识库中）

### 5.2 Chunk 质量

- [ ] 每个知识库至少 100 个 chunk
- [ ] 总 chunk 数在 3000-6000 范围内
- [ ] embedding_vector 非 NULL 的 chunk 占比 > 80%
- [ ] 每个 chunk 的 content 长度在 200-1500 字符范围内

### 5.3 任务关联

- [ ] 任务 8 的 knowledge_base_id = KB-A
- [ ] 任务 9 的 knowledge_base_id = KB-B
- [ ] 任务 17 的 knowledge_base_id = KB-A
- [ ] 任务 18 的 knowledge_base_id = KB-C

### 5.4 功能验证

- [ ] 任务 8 生成文章时，日志中 knowledge_length > 0
- [ ] 任务 9 生成文章时，日志中 knowledge_length > 0
- [ ] 抽查 3 篇生成的文章，内容中有引用知识库参数的痕迹
- [ ] 其他非护理床任务（4/13/15/16/19/20）不受影响

### 5.5 性能验证

- [ ] 单次知识库检索耗时 < 100ms
- [ ] 文章生成总耗时无明显增加（< 10%）

---

## 六、风险评估与回滚

### 6.1 风险矩阵

| 风险 | 影响 | 概率 | 应对 |
|------|------|------|------|
| NVIDIA embedding API 超时 | 部分 chunk 无真实向量 | 中 | 自动 fallback 到 hash 向量；后续可在 admin 手动"更新切片" |
| 分类不准确 | 文章放错知识库 | 低 | 关键词匹配规则已覆盖 95%+ 标题；兜底归入 KB-C |
| 知识库过大影响检索 | top-4 命中噪音 | 中 | 控制每个 KB < 2400 chunks；必要时拆分 |
| 导入阻塞生产任务 | embedding API 竞争 | 低 | 在任务空闲时段执行（如凌晨） |
| 关联后文章质量变化 | 文风/参数引用方式改变 | 低 | 知识注入是辅助性的，不改变 prompt 核心指令 |
| 中文数字文章与阿拉伯文章重复 | 内容重复 | 低 | Step 1 去重逻辑处理 |

### 6.2 回滚方案

```sql
-- 回滚 Step 1：取消任务关联
UPDATE tasks SET knowledge_base_id = NULL WHERE id IN (8, 9, 17, 18);

-- 回滚 Step 2：删除新创建的知识库（级联删除 chunks）
DELETE FROM knowledge_bases WHERE id IN (KB_A_ID, KB_B_ID, KB_C_ID);

-- 验证回滚
SELECT id, name, knowledge_base_id FROM tasks WHERE id IN (8, 9, 17, 18);
SELECT id, name FROM knowledge_bases WHERE id IN (KB_A_ID, KB_B_ID, KB_C_ID);
-- 应该返回 0 行
```

**回滚不会影响：**
- 现有 17 个护理床知识库（id 14-40）
- 其他任务（监控塔、铁塔等）
- 已生成的文章
- task_materials 表

---

## 七、后续优化（本次不做）

### 7.1 合并现有知识库

现有 17 个护理床知识库可以按主题合并到 KB-A/B/C 中，但需要：
- 评估每个知识库的内容质量和独特性
- 避免与新导入的文章内容重复
- 更新 task_materials 关联

### 7.2 创建 pgvector 向量索引

当前 knowledge_chunks 没有向量索引（只有 btree 索引），
如果 chunk 数量超过 10000，建议创建 HNSW 或 IVFFlat 索引：

```sql
CREATE INDEX idx_knowledge_chunks_embedding
ON knowledge_chunks USING hnsw (embedding_vector vector_cosine_ops)
WITH (m = 16, ef_construction = 64);
```

### 7.3 统一 embedding 维度

现有 chunk 存在 2048 和 3072 两种维度，建议统一重新生成：
- 在 admin 后台对每个知识库点击"更新切片"
- 或编写批量脚本调用 KnowledgeChunkSyncService::sync()

---

## 八、执行时间预估

| 步骤 | 预计耗时 | 说明 |
|------|---------|------|
| Step 1: 文件扫描与分类 | 1 分钟 | Python 脚本 |
| Step 2: 内容合并 | 2 分钟 | 读取 730 个文件 |
| Step 3: 创建知识库 | 1 分钟 | 3 条 INSERT |
| Step 4: 分块与向量化 | 30-45 分钟 | 4200 chunks × 0.5s embedding |
| Step 5: 任务关联 | 1 分钟 | 3 条 UPDATE |
| Step 6: 验证 | 10 分钟 | SQL 查询 + 生成测试 |
| **总计** | **45-60 分钟** | |

---

## 九、决策点（需要你确认）

1. **分组策略**：3 个知识库（标准规范/采购选型/场景应用）是否合理？
2. **任务分配**：任务8+17→KB-A，任务9→KB-B，任务18→KB-C，是否需要调整？
3. **现有知识库**：本次不动，只导入新文章，是否同意？
4. **执行时间**：建议在任务空闲时段（如凌晨）执行，是否可以？
5. **先测试再全量**：建议先导入 KB-A 并关联任务 8 测试，确认 OK 再全部导入？
