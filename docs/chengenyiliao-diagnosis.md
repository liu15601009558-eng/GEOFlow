# 程恩医疗器械网站问题诊断

> 日期：2026-06-04

## 问题描述

用户反馈：chengenyiliao.cn/news 没有样式，文章没有同步到数据库。

## 调查结果

### 1. 文章同步状态：✅ 正常

- 远端数据库有 **3131 篇文章**（aid 1~3131）
- GEOFlow 最新同步的文章已写入 EyouCMS 的 `ey_archives` + `ey_article_content` 表
- sync_article.php 接口正常运行
- 分类正确：B2B采购方案(45)、产品选型指南(46)、产品对比评测(47) 等

### 2. 前端页面渲染：⚠️ 有问题

**问题 A：CSS 文件 404**
- `/news/assets/css/site.css` → HTTP 404
- `/news/assets/js/site.js` → 未验证（可能也是 404）
- 原因：GEOFlow 的 `front_mode = static` 模式生成静态 HTML，
  但对应的 CSS/JS 静态资源文件没有部署到远端服务器

**问题 B：文章摘要中混入 markdown 原始标记**
- 摘要中出现 `! image /img/xxx.jpg` 这样的 markdown 图片语法
- 这些应该被渲染为 `<img>` 标签或在摘要中被过滤掉

### 3. 根因分析

GEOFlow 的静态页面生成流程：
```
GEOFlow → 生成 HTML → 推送到远端 sync_article.php
                       ↓
               远端 EyouCMS 存储文章数据
                       ↓
               GEOFlow 生成静态 HTML 页面（引用 /news/assets/css/site.css）
                       ↓
               静态 HTML + CSS/JS 推送到远端 /news/ 目录
                       ↑
               ⚠️ CSS/JS 文件可能没有被推送
```

### 4. 需要确认的事项

1. GEOFlow 是否有静态资源部署机制？（检查 DistributionOrchestrator 或相关代码）
2. `/news/assets/css/site.css` 应该由谁生成/部署？
3. 之前（同步前）网站的 /news/ 页面是什么样的？是否有自己的样式？

### 5. 修复方向

**方案 A：修复静态资源部署**
- 找到 GEOFlow 生成 CSS/JS 的逻辑
- 确保静态资源随文章一起推送到远端

**方案 B：修改 HTML 模板，使用内联样式**
- 将 CSS 直接嵌入到 HTML `<style>` 标签中
- 避免依赖外部 CSS 文件

**方案 C：使用 EyouCMS 自身模板**
- 不用 GEOFlow 的静态页面
- 让 EyouCMS 自己渲染文章页面（使用 EyouCMS 的模板系统）
- GEOFlow 只负责写入数据，不负责前端渲染
