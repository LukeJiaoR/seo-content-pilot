# SEO Content Pilot — 产品需求文档（PRD）

> 版本：v0.2 草案（新增 GEO 模块）
> 日期：2026-09-29
> 状态：待评审

---

## 1. 产品概述

### 1.1 一句话定位

一款 WordPress 插件：从关键词拓词、英文 SEO/GEO 文章生成、自动发布，到 GSC 数据 + AI 引用数据回收验证效果的**全闭环内容增长工具**。同时优化 Google 传统搜索（SEO）与 AI 答案引擎（GEO：ChatGPT、Perplexity、Gemini、Google AI Overview）两个流量渠道。

### 1.2 解决什么问题

- 内容站站长 / 外贸独立站运营者需要持续产出英文 SEO 内容，但人工写稿慢、外包贵
- 现有 AI 写作工具只解决"写"，不解决"写什么词、发完效果如何"的闭环
- GSC 里躺着大量机会词（排名 11–30 的词），多数站长不会挖
- 流量正从传统搜索向 AI 答案迁移（ChatGPT 周活约 10 亿、近 60% Google 搜索零点击），但 GEO 工具（Otterly/Peec/Profound，$29–300/月）**只监控不生产**，无法闭环

### 1.3 核心价值闭环

```
GSC 机会词 + GEO 缺口词（AI 答案引用竞品但没你）
→ 拓词扩展 → AI 生成（SEO+GEO 双优化）→ 定时自动发布
→ GSC 验证 SEO 排名 + Prompt Tracking 验证 AI 引用率
→ 再发现新机会
```

### 1.4 目标用户

| 用户类型 | 特征 | 核心诉求 |
|---|---|---|
| 英文内容站站长 | 有 1–10 个 WordPress 站，懂基础 SEO | 低成本量产内容，追流量增长 |
| 外贸/出海运营 | B2B/B2C 独立站，内容营销获客 | 英文内容产出 + 效果可量化 |
| SEO 代理/代运营 | 管理多个客户站点 | 多站管理、批量产出、白标报告 |

### 1.5 非目标（本阶段不做）

- 不做中文内容生成
- 不做外链建设
- 不做站群管理（单站安装、单站授权）
- 不做 SaaS 独立平台（纯 WordPress 插件形态）

---

## 2. 竞品参考

| 产品 | 形态 | 与我们的差异 |
|---|---|---|
| RankYak | SaaS $99/月 | 自动化但无 GSC 闭环，黑盒不可控 |
| Koala / Cuppa | AI 写作工具 | 只解决生成，无拓词和效果追踪 |
| Otterly / Peec AI / Profound | GEO 监控 $29–300/月 | 只监控 AI 引用，不生产内容，无 SEO 侧数据 |
| Semrush AI Visibility | SEO+GEO 监控 $99+/月 | 监控强但无 WP 内自动生产发布闭环 |
| Site Kit by Google | WP 官方插件 | 只做 GSC 数据展示，无内容生产 |
| RankMath / Yoast | SEO 插件 | 只做 on-page 检查，不生产内容 |

**我们的差异化**：拓词 → 生成 → 发布 → SEO(GSC) + GEO(AI 引用) 双渠道验证的完整闭环，且数据都在用户自己站点里。GEO 侧不仅监控引用率，还能直接生产"面向被引用而优化"的内容。

---

## 3. 功能需求

### 3.1 功能总览与优先级

| # | 模块 | 功能点 | 优先级 |
|---|---|---|---|
| F1 | 设置中心 | LLM API Key、DataForSEO Key、GSC OAuth、文章偏好（字数/语气/作者/分类） | P0 |
| F2 | 文章生成管线 | 单关键词 → 大纲 → 分段生成 → 组装 → Meta/FAQ → 存草稿 | P0 |
| F3 | 发布系统 | 直接发布 / 存草稿 / 定时排期（每天 N 篇均匀铺开） | P0 |
| F4 | 生成队列 | 批量导入关键词、队列状态管理、失败重试、用量统计 | P1 |
| F5 | 拓词引擎 | 种子词扩展（Autocomplete + suggestions）、去重、聚类、指标打分 | P1 |
| F6 | GSC 看板 | 点击/曝光/CTR/排名趋势，按文章分组的效果曲线 | P1 |
| F7 | 机会词挖掘 | Striking Distance 词、高曝光低 CTR 词、意外流量词 → 一键入队 | P1 |
| F8 | SEO 集成 | Yoast/RankMath Meta 字段填充、特色图（AI 生成/图库）、内链建议 | P2 |
| F9 | 收录加速 | IndexNow / Google Indexing API 提交、收录状态抽查 | P2 |
| F10 | AI Overview 检测 | DataForSEO SERP 返回的 Google AIO 内容中检测域名引用，进看板 | P1 |
| F11 | GEO Prompt Tracking | 定时用目标词询问 ChatGPT/Gemini/Perplexity，统计提及率/引用率/情感，趋势看板 | P2 |
| F12 | GEO 内容优化 | 生成管线加入"面向被引用"规则：问答结构、可引用数据段、llms.txt | P2 |
| F13 | GEO 归因 | GA4 中 AI 引荐流量趋势（chatgpt.com/perplexity.ai 等 referrer）、品牌词监控 | P2 |
| F14 | 竞品 Share of Voice | 维护竞品列表，GEO 监控中对比提及份额 | P3 |

### 3.2 P0 功能详述

#### F1 设置中心

- **LLM 配置**：支持 OpenAI / Claude / Gemini，用户自带 Key（BYOK）；Key 加密存储于 wp_options
- **DataForSEO 配置**：login/password，余额显示
- **GSC 授权**：OAuth 2.0 授权流程（见 5.3 风险说明），站点选择
- **内容偏好**：
  - 目标语言：English（地区可选 US/UK/AU…）
  - 文章长度：Short（800–1200）/ Medium（1500–2500）/ Long（3000+）
  - 语气：Professional / Casual / Technical / 自定义描述
  - 作者署名、默认分类、默认标签策略
- **Prompt 模板**：大纲/正文/Meta 三段模板可自定义，带变量插值（`{keyword}`、`{serp_titles}` 等）

#### F2 文章生成管线

单篇文章的生成流程：

```
关键词
 → [可选] SERP 分析（Top10 标题/H2 结构、PAA 问题）
 → 生成大纲（H1 + 5–10 个 H2/H3，含 FAQ）
 → 逐节生成正文（每节独立请求，避免超长输出质量下降）
 → 组装全文 + 关键词位置检查（标题/首段/H2/结尾）
 → 生成 Meta Title（≤60 字符）+ Meta Description（≤155 字符）
 → 生成 FAQ Schema（JSON-LD）
 → 特色图：AI 生成或 Unsplash/Pexels 拉取，自动写 alt
 → 按发布策略处理（草稿/发布/排期）
```

- 去 AI 味策略：段落长度随机化、禁用词列表（delve, tapestry, landscape 等）、句式多样性指令
- 内链建议：生成后扫描站内已有文章标题，推荐 2–5 个插入点（P2 可后置）

#### F3 发布系统

- 三种模式：**直接发布 / 存草稿待审 / 定时排期**
- 定时排期：设定「每天最多 N 篇 + 发布时间窗口」，系统按队列顺序均匀铺开，避免单日批量发布
- 发布后动作（可开关）：提交 IndexNow、写入发布记录表

### 3.3 P1 功能详述

#### F5 拓词引擎

- 输入：种子词列表（手动/CSV）
- 扩展来源：
  1. Google Autocomplete（A–Z、0–9、疑问词前缀轮询）
  2. DataForSEO `keyword_suggestions` / `related_keywords`（带搜索量、KD、CPC）
  3. GSC 查询词扩展（F7 输出回流）
  4. LLM 语义变体（场景、意图、同义）
- 处理：去重 → embedding 聚类（同簇只写一篇，防 keyword cannibalization）→ 意图分类（LLM）→ 打分排序
- 打分公式（v1）：`score = volume / max(kd, 1)`，GSC 已有排名的词加权 ×1.5
- 输出：关键词队列，支持勾选/全选一键送入生成

#### F6 GSC 看板

- 站点级趋势：点击、曝光、CTR、平均排名（28 天 / 3 个月对比）
- 文章级效果：每篇 AI 生成文的曝光/点击曲线（page ↔ query 关联）
- 数据刷新：每日定时拉取，本地缓存

#### F7 机会词挖掘

- **Striking Distance**：排名 11–30 且曝光 > 阈值 → 建议"优化旧文"或"写新文"
- **高曝光低 CTR**：排名 ≤ 10 且 CTR 低于预期 → 建议重写标题/Meta
- **意外流量词**：带来点击但站点无对应主题文章 → 直接进拓词队列
- 每个机会词支持「一键生成文章」

#### F10 AI Overview 检测（AIO 引用监控）

- 对关键词池中已发布文章的词，定期（每周）调 DataForSEO SERP API
- 解析返回中的 Google AI Overview 块：检测是否引用本站域名、引用位置、被引用的页面
- 结果进看板：AIO 引用率趋势 + 哪些文章被引用
- **定位：GEO 指标中成本最低、最先做的一个**（复用已有 API，无需新 Key）

### 3.4 P2 功能（简述）

- F8：Yoast/RankMath 字段通过其公开 meta API 写入；特色图 AI 生成（gpt-image / Gemini）或图库 API
- F9：IndexNow 一键提交 + URL Inspection API 抽查收录
- F11 **GEO Prompt Tracking**：从关键词池选追踪词（优先商业意图），每周定时用用户 LLM Key 询问 ChatGPT/Gemini/Perplexity（prompt 模板如 "best {keyword} tools"、"{keyword} recommendations"）；解析回答得到 **Mention Rate（提及率）、Citation Rate（域名引用率）、列表位置、情感倾向**；趋势进看板
- F12 **GEO 内容优化**：生成管线 prompt 加入"面向被引用"规则——问答式 H2、定义句与直接回答段、数据表格/统计、作者框与来源引用（E-E-A-T）；可选生成站点 `/llms.txt`
- F13 **GEO 归因**：GA4 中 chatgpt.com / perplexity.ai / gemini / copilot 等 referrer 趋势；GSC 品牌词曝光监控（AI 引用 → 品牌搜索增长的滞后指标）
- F14（P3）：竞品 Share of Voice——维护竞品域名/品牌列表，在 Prompt Tracking 与 AIO 检测中对比提及份额，找出"有竞品没我"的 GEO 缺口词 → 进拓词队列

---

## 4. 用户流程（核心路径）

### 4.1 首次配置

安装插件 → 填 LLM Key → 授权 GSC → 设置内容偏好 → 完成

### 4.2 日常主流程（闭环）

1. 打开「机会词」页，看到 GSC 挖出的 Striking Distance 词和 GEO 缺口词（AI 答案有竞品没我）
2. 勾选 →「拓词」扩展出相关长尾，查看搜索量/KD 打分
3. 勾选 →「加入生成队列」，设置每天发 2 篇
4. 系统按队列自动生成（SEO+GEO 双优化）、定时发布
5. 一周后看板显示：新文章 GSC 曝光/点击曲线 + AIO/Prompt 引用率变化
6. 新的查询词和 GEO 缺口又出现在机会列表 → 循环

---

## 5. 边界与风险

### 5.1 内容质量风险

- AI 内容存在被 Google 判定低质的风险 → 默认走「存草稿待审」模式，排期发布兜底
- 必须保留人工审核环节作为推荐路径

### 5.2 发布节奏风险

- 单日批量发布易触发警戒 → 排期功能强制每日上限（默认 ≤ 3 篇/天）

### 5.3 GSC OAuth 审核风险

- 未验证的 Google OAuth App 有 100 用户上限，正式上架需走 Google 验证（数周）
- **MVP 缓解方案**：支持读取 Site Kit 已授权的 token（用户已装 Site Kit 时免重复授权）

### 5.4 成本与定价参考

- 单篇文章 LLM 成本约 $0.05–0.30；DataForSEO 拓词约 $0.01–0.05/千词；SERP 约 $0.0006/次
- GEO 监控成本：每词每次 3 个引擎各 1 次调用（约 $0.01–0.05/词/周），50 词追踪约 $2.5/周
- 商业模式（初定）：插件免费 + BYOK（用户自带各 API Key），后续可做托管 Key 的订阅版

### 5.5 GEO 数据准确性风险

- LLM 回答有随机性 → Prompt Tracking 必须看趋势（周粒度），不做单次结论；每词每周固定 prompt、固定引擎
- AI Overview 出现率因词而异（并非所有词都有 AIO），指标要标注样本基数

---

## 6. 里程碑

| 阶段 | 内容 | 验收标准 |
|---|---|---|
| M1 | 插件骨架 + F1 设置 + F2 单篇生成 + F3 存草稿 | 输入 1 个关键词，产出完整草稿文章 |
| M2 | F3 排期 + F4 队列 | 批量导入 50 词，自动排队定时发布 |
| M3 | F5 拓词引擎 | 1 个种子词扩展出 200+ 词并打分排序 |
| M4 | F6 GSC 看板 + F7 机会词 + F10 AIO 检测 | 看板数据正确，机会词可一键入队，AIO 引用可见，闭环跑通 |
| M5 | F8/F9 + F11/F12/F13 | SEO 集成、收录提交、GEO 监控与内容优化规则上线 |

---

## 7. 成功指标

**SEO 侧**
- 生成文章 7 日内收录率
- 生成文章 28 日平均曝光增长
- Striking Distance 词优化后排名提升率

**GEO 侧**
- 追踪词的 AI Mention Rate / Citation Rate 周趋势（ChatGPT/Gemini/Perplexity）
- Google AI Overview 域名引用率（有 AIO 的词中被引用的占比）
- GA4 AI 引荐流量（chatgpt.com / perplexity.ai 等 referrer）月环比增长

**产品侧**
- 用户周活跃闭环率（当周完成"机会词→生成→发布"的比例）
