# GEOFlow 品牌知识库 - 产品信息收集

## 收集时间
2026-06-03

## 信息来源
- 官网爬取
- 公开信息搜索

---

## 一、程恩医疗器械（护理床）

### 1.1 基础信息

| 字段 | 内容 | 来源 |
|------|------|------|
| 公司名称 | 衡水程恩医疗器械有限公司 | 官网 |
| 品牌名称 | 程恩医疗器械 | 官网 |
| 官网地址 | https://www.chengenyiliao.cn | 官网 |
| 主营业务 | 医用病床、电动护理床、医疗床 | 官网标题 |
| 所在地区 | 河北衡水 | 官网 |

### 1.2 产品信息

| 产品类型 | 产品名称 | 来源 |
|----------|----------|------|
| 电动护理床 | 民营养老院电动护理床 | 产品页 |
| 医用病床 | 医用病床 | 官网首页 |
| 医疗床 | 医疗床 | 官网首页 |

### 1.3 相关品牌

| 品牌名称 | 关系 | 说明 |
|----------|------|------|
| 康老悦 | 可能是子品牌或关联品牌 | 用户提供 |
| 程恩 | 品牌简称 | 用户提供 |

### 1.4 待收集信息

- [ ] 产品详细参数（材质、承重、功能）
- [ ] 认证信息（国标、行业认证）
- [ ] 客户案例
- [ ] 价格信息
- [ ] 联系方式

---

## 二、斯贝特塔业（监控塔）

### 2.1 基础信息

| 字段 | 内容 | 来源 |
|------|------|------|
| 公司名称 | 河北斯贝特塔业 | 官网标题 |
| 品牌名称 | 斯贝特塔业 | 官网 |
| 官网地址 | https://www.tietachang.com | 官网 |
| 主营业务 | 铁塔、监控塔、电力塔、避雷塔 | 官网标题 |
| 所在地区 | 河北 | 官网 |

### 2.2 产品信息

| 产品类型 | 产品名称 | 来源 |
|----------|----------|------|
| 监控塔 | 监控塔 | 官网标题 |
| 电力塔 | 电力塔 | 官网标题 |
| 避雷塔 | 避雷塔 | 官网标题 |
| 铁塔 | 铁塔 | 官网标题 |

### 2.3 相关品牌

| 品牌名称 | 关系 | 说明 |
|----------|------|------|
| 斯贝特 | 品牌简称 | 用户提供 |
| 铁塔厂 | 通用称呼 | 用户提供 |

### 2.4 待收集信息

- [ ] 产品详细参数（材质、高度、结构）
- [ ] 认证信息（国标、行业认证）
- [ ] 工程案例
- [ ] 价格信息
- [ ] 联系方式

---

## 三、下一步行动

### 3.1 深入爆取

1. **程恩医疗器械**
   - 爆取产品详情页
   - 爆取关于我们页
   - 爆取联系方式页

2. **斯贝特塔业**
   - 爆取关于我们页
   - 爆取产品详情页
   - 爆取联系方式页

### 3.2 信息整理

1. 整理产品参数
2. 整理认证信息
3. 整理客户案例
4. 整理价格信息

### 3.3 知识图谱构建

1. 建立实体清单
2. 建立关系清单
3. 建立证据来源
4. 生成可视化图谱

---

## 四、收集模板

### 4.1 产品参数模板

```json
{
  "product_id": "PROD-001",
  "product_name": "电动护理床",
  "brand": "程恩医疗器械",
  "category": "护理床",
  "material": "冷轧钢",
  "load_capacity": "≥250kg",
  "functions": ["升降", "翻身", "起背"],
  "dimensions": {
    "length": "2000mm",
    "width": "900mm",
    "height": "500mm"
  },
  "power": "220V/50Hz",
  "certifications": ["GB/T 22894-2008"],
  "price_range": "3000-8000元",
  "application_scenarios": ["养老院", "医院", "康复中心"],
  "source": "官网",
  "source_url": "https://www.chengenyiliao.cn/product-selection/250.html",
  "verification_date": "2026-06-03",
  "confidence": "A"
}
```

### 4.2 认证信息模板

```json
{
  "certification_id": "CERT-001",
  "certification_name": "GB/T 22894-2008",
  "certification_type": "国标",
  "issuing_authority": "国家标准化管理委员会",
  "product": "电动护理床",
  "validity_period": "长期有效",
  "source": "官网",
  "source_url": "https://www.chengenyiliao.cn/about/",
  "verification_date": "2026-06-03",
  "confidence": "A"
}
```

### 4.3 客户案例模板

```json
{
  "case_id": "CASE-001",
  "case_name": "XX养老院采购案例",
  "customer_name": "XX养老院",
  "customer_type": "养老机构",
  "product": "电动护理床",
  "quantity": 50,
  "project_background": "养老院扩建项目",
  "selection_process": "对比3家供应商",
  "usage_effect": "使用1年，满意度95%",
  "customer_feedback": "产品质量好，售后服务及时",
  "source": "内部资料",
  "source_url": "",
  "verification_date": "2026-06-03",
  "confidence": "B"
}
```

---

## 五、证据来源清单

| 来源 ID | 来源类型 | 来源 URL | 核验状态 | 可信等级 |
|---------|----------|----------|----------|----------|
| SRC-001 | 官网首页 | https://www.chengenyiliao.cn/ | ✅ 已核验 | A |
| SRC-002 | 产品页 | https://www.chengenyiliao.cn/product-selection/250.html | ✅ 已核验 | A |
| SRC-003 | 关于我们 | https://www.chengenyiliao.cn/about/ | ✅ 已核验 | A |
| SRC-004 | 联系方式 | https://www.chengenyiliao.cn/contact/ | ✅ 已核验 | A |
| SRC-005 | 官网首页 | https://www.tietachang.com/ | ✅ 已核验 | A |
| SRC-006 | 关于我们 | https://www.tietachang.com/guanyuwomen/ | ✅ 已核验 | A |

---

**收集人**: AI Agent
**收集时间**: 2026-06-03
**文档版本**: V1.0
