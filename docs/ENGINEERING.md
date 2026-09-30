# SEO Content Pilot — 工程设计文档

> 版本：v0.2 草案（新增 GEO 模块）
> 日期：2026-09-29
> 对应产品文档：[PRD.md](./PRD.md)

---

## 1. 技术栈决策

| 层 | 选型 | 理由 |
|---|---|---|
| 插件主体 | PHP 8.1+（WordPress 原生，无框架） | WP 插件标准形态；避免 Composer 依赖冲突 |
| 后台 UI | React 18 + `@wordpress/components` + `@wordpress/element` | 与 WP 后台风格一致；用 `@wordpress/scripts` 构建，零配置 |
| 前端路由 | 单页设置应用（hash router）挂载到插件菜单页 | 简单可靠 |
| 异步任务 | Action Scheduler（WooCommerce 同款） | 成熟队列，支持失败重试、分组、定时；以库形式内嵌 |
| HTTP 客户端 | `wp_remote_*`（WP HTTP API） | 统一超时/代理处理 |
| 数据库 | MySQL 自定义表 + `$wpdb` | 见 §4 |
| LLM 接入 | 直连 OpenAI / Claude / Gemini REST API（BYOK） | 无中间后端，Key 加密存 wp_options |
| 关键词数据 | DataForSEO REST API | 按次计费，覆盖 suggestions/SERP/PAA/搜索量 |
| GSC 数据 | Google Search Console API（OAuth 2.0） | 见 §7 |
| 构建工具 | `@wordpress/scripts`（webpack 封装） | 官方标准 |

**明确不引入**：Composer 大型框架、独立后端服务、前端 Tailwind（后台用 WP 组件即可）。

---

## 2. 目录结构

```
seo-content-pilot/
├── seo-content-pilot.php        # 插件入口：声明、激活/停用钩子、autoload
├── uninstall.php
├── includes/
│   ├── class-plugin.php         # 主类：注册菜单、enqueue、REST 路由
│   ├── class-activator.php      # 建表、默认选项
│   ├── class-settings.php       # 选项读写、Key 加解密（sodium）
│   ├── Admin/
│   │   ├── class-admin-page.php # React 应用挂载点
│   │   └── class-assets.php
│   ├── Generation/
│   │   ├── class-pipeline.php   # 文章生成编排器（§5）
│   │   ├── class-outline-generator.php
│   │   ├── class-section-writer.php
│   │   ├── class-seo-finalizer.php
│   │   ├── class-featured-image.php
│   │   └── Prompts/             # 默认 prompt 模板（可被设置覆盖）
│   ├── LLM/
│   │   ├── interface-llm-client.php
│   │   ├── class-openai-client.php
│   │   ├── class-claude-client.php
│   │   ├── class-gemini-client.php
│   │   └── class-llm-factory.php
│   ├── Keywords/
│   │   ├── class-expander.php       # 拓词编排（§6）
│   │   ├── class-autocomplete.php   # Google Autocomplete
│   │   ├── class-dataforseo.php     # DataForSEO 客户端
│   │   ├── class-clusterer.php      # 去重 + 聚类
│   │   └── class-scorer.php         # 打分排序
│   ├── GSC/
│   │   ├── class-oauth.php          # OAuth 流程 + token 刷新
│   │   ├── class-client.php         # Search Analytics / URL Inspection
│   │   ├── class-sync.php           # 每日同步任务
│   │   └── class-opportunities.php  # 机会词挖掘（§7.3）
│   ├── GEO/
│   │   ├── class-prompt-tracker.php # ChatGPT/Gemini/Perplexity 定时问答监控（§8.1）
│   │   ├── class-answer-parser.php  # LLM 回答 → 提及/引用/位置/情感 结构化
│   │   ├── class-aio-detector.php   # DataForSEO SERP → AI Overview 域名引用检测（§8.2）
│   │   ├── class-geo-rules.php      # 生成管线的"面向被引用"prompt 规则（§8.3）
│   │   └── class-llms-txt.php       # /llms.txt 生成与路由
│   ├── Publishing/
│   │   ├── class-publisher.php      # wp_insert_post / 排期
│   │   ├── class-scheduler.php      # 每日配额铺开逻辑
│   │   └── class-indexnow.php
│   ├── SEO/
│   │   ├── class-yoast-adapter.php
│   │   ├── class-rankmath-adapter.php
│   │   └── class-internal-links.php
│   └── REST/
│       ├── class-keywords-controller.php
│       ├── class-queue-controller.php
│       ├── class-gsc-controller.php
│       └── class-settings-controller.php
├── src/                            # React 源码（构建到 build/）
│   ├── index.js
│   ├── pages/
│   │   ├── Dashboard.jsx          # GSC 看板
│   │   ├── Keywords.jsx           # 拓词
│   │   ├── Queue.jsx              # 生成队列
│   │   ├── Opportunities.jsx      # 机会词
│   │   └── Settings.jsx
│   └── components/
├── package.json
└── docs/
    ├── PRD.md
    └── ENGINEERING.md
```

---

## 3. 插件骨架（入口约定）

- 主类 `SCP\Plugin` 单例，`plugins_loaded` 时初始化
- REST 命名空间：`scp/v1`
- 权限：所有 REST 路由校验 `current_user_can('manage_options')` + nonce
- 激活时：`dbDelta` 建表、注册 Action Scheduler 周期任务、写默认选项
- 选项键统一前缀 `scp_`；自定义表前缀 `{$wpdb->prefix}scp_`

---

## 4. 数据模型

### 4.1 `scp_keywords`（关键词池）

| 字段 | 类型 | 说明 |
|---|---|---|
| id | bigint PK | |
| keyword | varchar(255) | 唯一索引（小写归一化） |
| source | varchar(32) | seed / autocomplete / dataforseo / gsc / llm |
| cluster_id | bigint NULL | 聚类分组 |
| volume | int NULL | |
| kd | float NULL | |
| cpc | decimal(8,2) NULL | |
| intent | varchar(16) NULL | informational / commercial / navigational / transactional |
| score | float NULL | 优先级分 |
| status | varchar(16) | new / queued / generating / published / skipped |
| created_at / updated_at | datetime | |

### 4.2 `scp_jobs`（生成任务）

| 字段 | 说明 |
|---|---|
| id, keyword_id (FK), post_id (NULL) | 文章关联 |
| status | pending / running / done / failed |
| step | outline / sections / finalize / image（断点恢复用） |
| payload | JSON：中间产物（大纲、各节正文） |
| error | text |
| scheduled_at | 计划发布时间 |
| tokens_used, cost_estimate | 用量统计 |

### 4.3 `scp_gsc_daily`（GSC 每日数据）

| 字段 | 说明 |
|---|---|
| id, date, query, page, country, device | 联合唯一索引 |
| clicks, impressions, ctr, position | API 原始指标 |

> 拉取时 country/device 先聚合为 all（行数可控），看板筛选维度后续再加。

### 4.4 `scp_publish_log`

post_id、keyword、published_at、indexnow_submitted、indexed_at（URL Inspection 抽查回填）

### 4.5 `scp_geo_checks`（Prompt Tracking 结果，F11）

| 字段 | 说明 |
|---|---|
| id, keyword_id (FK), checked_at | |
| engine | varchar(16)：chatgpt / gemini / perplexity |
| prompt_text | 实际使用的 prompt（固定模板，保证周间可比） |
| mentioned | bool：品牌/域名是否被提及 |
| cited | bool：域名是否作为来源被引用 |
| mention_position | int NULL：列表类回答中的排位 |
| sentiment | varchar(8)：positive / neutral / negative |
| competitors_mentioned | JSON：命中的竞品品牌列表 |
| raw_answer_hash | char(64)：原文 hash 去重；原文按需另存 |

### 4.6 `scp_aio_checks`（Google AI Overview 检测，F10）

| 字段 | 说明 |
|---|---|
| id, keyword_id (FK), checked_at | |
| has_aio | bool：该词本次是否出现 AIO |
| cited | bool：AIO 是否引用本站域名 |
| cited_url | varchar(255) NULL：被引用的具体页面 |
| competitors_cited | JSON |

### 4.7 wp_options

`scp_settings`（数组：LLM 配置、内容偏好、发布策略）、`scp_gsc_token`（加密）、`scp_llm_keys`（加密）

**加密方案**：`sodium_crypto_secretbox`，密钥派生自 `SECURE_AUTH_KEY`（wp-config 常量，站点唯一，不入库）。

---

## 5. 文章生成管线（Generation Pipeline）

### 5.1 编排

`Pipeline` 作为 Action Scheduler 的 job handler，每篇文章一个异步任务，**分步执行、每步落库**（`scp_jobs.step` + `payload`），任一步失败可从断点重试：

```
outline → sections(循环 N 次) → finalize → image → publish
```

### 5.2 各步职责

| 步骤 | 输入 | 输出 | 备注 |
|---|---|---|---|
| outline | keyword, SERP 数据(可选), 偏好 | H1 + H2/H3 树 + FAQ 列表 | 一次调用；JSON mode 输出 |
| sections | 大纲单个节点 + 前文摘要 | 该节 HTML | 每节独立请求；并发上限 2（省 token 峰值） |
| finalize | 全文 | Meta title/desc、FAQ JSON-LD、slug | 含关键词位置检查，不通过则触发一次修订调用 |
| image | keyword + H1 | attachment_id | AI 生成失败时降级 Unsplash/Pexels，再降级跳过 |
| publish | 全部产物 | post_id | 走 Publisher（§8） |

### 5.3 Prompt 约定

- 模板存 `Generation/Prompts/*.php`，返回数组 `[system, user]`
- 变量插值：`{keyword}`、`{language}`、`{tone}`、`{word_count}`、`{serp_titles}`、`{paa_questions}`
- 用户在设置页的自定义模板覆盖默认；合并时白名单校验变量
- 去 AI 味公共指令（拼进每个 system prompt）：禁用词列表、段落长度 2–6 句随机、禁止空洞总结段

### 5.4 关键实现约束

- WP HTTP 超时默认 5s → 所有 LLM 请求显式 `timeout => 60`；长文绝不单次生成
- LLM 客户端统一接口 `complete(array $messages, array $opts): string`，带指数退避重试（429/5xx，最多 3 次）
- 每次调用记录 tokens → 汇总到 job

---

## 6. 拓词引擎

### 6.1 流程

```
种子词列表
 → 并发生成扩展：
    a) Autocomplete: seed + [a-z, 0-9, how/what/why/...] 轮询
    b) DataForSEO keyword_suggestions（depth=1）
    c) DataForSEO related_keywords
    d) GSC 机会词回流（来自 §7.3）
 → 归一化去重（小写、去停用词、词形还原可选）
 → 批量回填指标：DataForSEO search_volume + keyword_difficulty（按 100 词/批）
 → 聚类：embedding（text-embedding-3-small）余弦相似度 ≥ 0.82 归簇
         同簇保留 score 最高者为主词，其余标记 cluster 成员
 → 意图分类：LLM 批量（50 词/次，JSON 输出）
 → score = volume / max(kd,1) × (GSC 已有排名 ? 1.5 : 1)
 → 写 scp_keywords
```

### 6.2 成本控制

- 指标回填只在「加入队列」时做（拓词列表页先显示词，勾选后才拉指标），避免为几百个不会用的词付费
- embedding 结果缓存到 `scp_keywords.embedding`（BLOB），重跑不重复计费

### 6.3 聚类说明

- v1 用 embedding 相似度，实现简单
- v2 可换 SERP 重叠聚类（两词 Top10 重叠 ≥ 40% 归簇）——更准但贵，留接口 `interface-clusterer`

---

## 7. GSC 集成

### 7.1 OAuth 流程

1. 设置页点「Connect Google」→ 跳转 Google consent（scope: `webmasters.readonly`）
2. callback 到 admin URL：`/wp-admin/admin.php?page=scp-settings&scp_gsc_callback=1`
3. 换 token，加密存 `scp_gsc_token`；后台用 refresh_token 自动续期
4. 站点选择：`sites.list` → 用户选定一个，存设置

**Site Kit 兼容（MVP 缓解 OAuth 审核风险）**：检测到 Site Kit 已授权 GSC 时，通过其 `googlesitekit_...` 选项读取 access token 直接使用；读不到才走自己的 OAuth。

### 7.2 数据同步

- Action Scheduler 每日任务 `scp_gsc_daily_sync`
- `searchanalytics.query`：维度 `query+page+date`，日期范围滚动拉最近 3 天（GSC 数据延迟），upsert 进 `scp_gsc_daily`
- 分页：`rowLimit=25000` 循环；API 配额 1200 次/分钟足够
- 首次授权后回填 16 个月历史（分批任务，防超时）

### 7.3 机会词挖掘（Opportunities）

对 `scp_gsc_daily` 聚合（最近 28 天）：

| 类型 | 规则 | 动作建议 |
|---|---|---|
| striking_distance | 10 < avg_position ≤ 30 且 impressions ≥ 50 | 优化旧文 or 生成新文 |
| low_ctr | avg_position ≤ 10 且 ctr < 位置基准值 | 重写标题/Meta |
| unexpected | clicks > 0 且 query 不在 scp_keywords 且 page 非 AI 生成 | 进拓词队列 |

位置基准 CTR（v1 内置常量表）：pos1≈0.28, pos2≈0.15, pos3≈0.10, pos4-10 递减。

输出写入 `scp_keywords`（source=gsc）或直接给 REST 返回。

### 7.4 URL Inspection

- 发布后第 3/7 天各抽查一次 `index.inspect`，回填 `indexed_at`
- 配额 2000 次/天/站点，绰绰有余

---

## 8. GEO 集成

### 8.1 Prompt Tracking（F11，`Prompt_Tracker`）

- Action Scheduler 周任务 `scp_geo_weekly_check`：取追踪词（`scp_keywords` 中标记 `track_geo=1`，上限可配，默认 50）
- 每词 × 每引擎 1 次调用，复用 `LLM\` 客户端（chatgpt=OpenAI、gemini=Gemini、perplexity=Perplexity API 单独 key 存 `scp_llm_keys`）
- **prompt 固定模板**（存设置可改，但改动需提示"会破坏趋势可比性"）：
  - commercial 意图：`"What are the best {keyword} tools/products?"`
  - informational 意图：`"How to {keyword}? Please recommend sources."`
- 回答不直接用正则解析 → 交给 `Answer_Parser` 做一次结构化 LLM 调用（JSON mode 输出 mentioned/cited/position/sentiment/competitors），对抗回答格式差异
- 品牌/域名识别：设置页配 `brand_name` + `brand_aliases[]` + 站点域名
- 写 `scp_geo_checks`；看板聚合：周粒度 mention_rate / citation_rate / avg_position / share_of_voice

### 8.2 AI Overview 检测（F10，`AIO_Detector`）

- 周任务：对 `status=published` 的关键词调 DataForSEO SERP API（`serp/google/organic/live/advanced`，带 `ai_overview` 字段）
- 解析 AIO references 数组：匹配本站域名 → cited/cited_url；匹配竞品域名表 → competitors_cited
- 写 `scp_aio_checks`；看板展示引用率时**标注样本基数**（has_aio=true 的词数）
- 成本：仅对有文章的词跑，50 词/周 ≈ $0.03，几乎可忽略

### 8.3 GEO 内容优化规则（F12，`GEO_Rules`）

- 作为公共 prompt 片段注入生成管线（与"去 AI 味"指令并列），非独立模块逻辑：
  - 每个 H2 尽量用疑问句，首段给 2–3 句直接回答（可被引用的"答案块"）
  - 至少 1 个数据表格或统计列表
  - 定义句规范：`"X is ..."` 清晰定义核心术语
  - 文末作者框（byline + 资历描述，E-E-A-T）
- `LLMs_Txt`：`rewrite rule` 把 `/llms.txt` 映射到动态输出（站点简介 + 精选文章 URL 列表），设置页开关

### 8.4 GEO 归因（F13）

- GA4：MVP 不做 GA4 API 集成，v1 只在看板静态提示用户在 GA4 查看的 referrer 清单（chatgpt.com、perplexity.ai、gemini.google.com、copilot.microsoft.com）；v2 再接 GA4 Data API
- 品牌词：复用 `scp_gsc_daily`，`query LIKE %brand%` 聚合出品牌词曝光趋势卡片

---

## 9. 发布系统

### 9.1 Publisher

- `wp_insert_post`（status 由策略决定：draft / future / publish）
- 写 Yoast（`_yoast_wpseo_title` 等 post meta）/ RankMath（`rank_math_title` 等）——检测哪个插件激活
- FAQ JSON-LD 追加到 `post_content` 底部（`wp_head` 输出 schema）
- 特色图：`set_post_thumbnail`，alt 写 `_wp_attachment_image_alt`

### 9.2 Scheduler（排期）

- 设置：每日上限 N（默认 2）、时间窗口（如 09:00–18:00）、随机抖动
- 每次有 job 转 done 时，重算未来排期：取当天及后续已排数量，把新文章插到第一个「未排满」日期的窗口内随机时刻
- 排期即写 `post_date` + `post_status=future`，交给 WP 原生 cron 发布
- 发布后触发 IndexNow 提交（Bing/Yandex），记录到 `scp_publish_log`

---

## 10. REST API 设计（后台 UI 用）

命名空间 `scp/v1`：

| 路由 | 方法 | 说明 |
|---|---|---|
| /settings | GET/POST | 设置读写（Key 只写不回显） |
| /keywords | GET/POST/DELETE | 关键词池；POST 支持批量 |
| /keywords/expand | POST | 触发拓词任务 {seeds: []} |
| /keywords/metrics | POST | 勾选回填指标 {ids: []} |
| /queue | GET/POST | 队列；POST {keyword_ids: [], strategy} 入队 |
| /queue/{id}/retry | POST | 失败重试 |
| /jobs/{id} | GET | 任务详情（含中间产物预览） |
| /gsc/status | GET | 授权状态/站点 |
| /gsc/connect | GET | 返回 OAuth URL |
| /gsc/overview | GET | 看板聚合数据 ?range=28d |
| /gsc/opportunities | GET | 机会词列表 |
| /geo/overview | GET | GEO 看板聚合：mention/citation rate 趋势、AIO 引用率、品牌词趋势 |
| /geo/tracking | GET/POST | 追踪词管理（track_geo 标记、竞品列表） |
| /geo/checks | GET | Prompt Tracking / AIO 明细 ?engine=&week= |
| /geo/run | POST | 手动触发一次 GEO 检查 {keyword_ids, engines} |
| /publish/schedule | GET/POST | 排期策略 |

---

## 11. 安全与合规

- REST 全部 `manage_options` + `wp_create_nonce('wp_rest')`
- 所有外部输入 sanitize；DB 全部 `$wpdb->prepare`
- LLM/GSC Key 加密存储，日志脱敏
- Prompt 模板用户输入做变量白名单，防注入任意系统指令到日志
- 上架 wordpress.org 前的 checklist：无加密混淆代码、无远程代码引入、readme.txt 规范

---

## 12. 性能与边界

- 单任务分步执行，每步 < 90s（避开 WP-Cron / PHP max_execution_time 默认限制）；文档中提示用户配 `WP_CRON` 系统 cron
- `scp_gsc_daily` 行数估算：5 万查询/天 × 365 ≈ 1800 万行/年 → 加 `(date, query)` 索引；提供「保留最近 13 个月」清理任务
- DataForSEO 并发 ≤ 10；LLM 并发 ≤ 2（可配）
- PHP 内存：embedding 聚类分批（500 词/批）防 OOM

---

## 13. 错误处理与可观测

- 统一 `SCP\Logger`（写 `scp_logs` 表：level, context, message, meta JSON）
- job 失败保留最近 error + step，UI 可一键重试（从断点）
- 看板页显示：今日队列、失败数、LLM 用量（tokens/估算成本）、DataForSEO 余额

---

## 14. 开发顺序（对应 PRD 里程碑）

| Sprint | 交付 |
|---|---|
| S1 | 骨架（入口/设置页/React 挂载/加密存储）+ OpenAI 客户端 + 单篇管线到草稿 |
| S2 | Action Scheduler 队列 + 排期发布 + Yoast/RankMath 写入 |
| S3 | DataForSEO + Autocomplete 拓词 + 聚类打分 |
| S4 | GSC OAuth（含 Site Kit 兼容）+ 每日同步 + 看板 + 机会词 + AIO 检测（§8.2） |
| S5 | IndexNow + URL Inspection + 特色图 + Prompt Tracking（§8.1）+ GEO 规则注入（§8.3） |

---

## 15. 待决策项

| # | 问题 | 选项 |
|---|---|---|
| D1 | Google OAuth 正式验证 | 自己申请（周期长）/ 长期依赖 Site Kit token / 让用户自建 GCP 项目（BYO OAuth client，技术门槛高） |
| D2 | embedding 来源 | OpenAI text-embedding-3-small（便宜）/ 本地轻量模型（零成本但重） |
| D3 | 商业化 | 免费+BYOK / 托管 Key 订阅 / 按站点授权 |
| D4 | 多语言 UI | v1 仅英文后台 / 同步出中文 |
| D5 | Perplexity 接入 | 用户自带 Perplexity API Key / 跳过该引擎只做 chatgpt+gemini（v1 可接受） |
