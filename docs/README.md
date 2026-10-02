# 文档站 / Documentation site

VuePress 2 构建的文档站，**中英双语**。源文件在 `src/`。

## 本地开发

```bash
cd docs
npm ci             # 首次（CI 同款；用 npm install 会重写 lock）
npm run dev        # http://localhost:8080
```

改 `src/` 下的 markdown 会热更新。

**Node 版本要求 `>=22.18.0`** —— `vuepress@2.0.0-rc.31` 的 `engines` 要求。已在 `package.json` 里声明，版本不符时 npm 会直接提示（配 `--engine-strict` 则硬失败），不会只在 CI 里刷一屏 `EBADENGINE`。

## 构建

```bash
npm run build      # 产物在 src/.vuepress/dist/
```

## 关于 lock 文件

**改依赖后用 `npm ci` 验证，不要只跑 `npm install`。**

`npm install` 会重写 lock，掩盖不同步问题；`npm ci` 严格按 lock 安装，正是 CI 的行为。本地 npm 与 CI 的 npm 版本不同时，这个差别会变成"本地好好的、CI 挂掉"。

一个具体教训：`sass` 是 theme-default 的**可选 peer 依赖**，不显式声明时 npm 10 和 npm 11 会把它记进 lock 的不同位置，导致 CI 的 `npm ci` 报 `Missing: sass@1.105.1 from lock file`。**已在 `devDependencies` 里显式声明**以消除歧义。

改动 lock 后建议用 CI 同款版本复核一遍：

```bash
npx --yes npm@10 ci
```

## 语言结构

| 路径 | 语言 |
|---|---|
| `/` | 英文（默认，搜索引擎看到的那份） |
| `/zh/` | 简体中文 |

两侧内容一一对应。加页面时**两种语言都要加**，并更新 `config.ts` 里对应的 `sidebar`。

## 检查

```bash
python check-links.py src/.vuepress/dist   # 站内链接死链（产物级）
python check-anchors.py src                # 跨页锚点是否指向真实标题（源码级）
```

两个脚本都覆盖中英两套页面。

### 三层防护，各管一段

| 机制 | 层次 | 失败方式 |
|---|---|---|
| `themePlugins.linksCheck: { build: 'error' }` | markdown 源码 | **构建失败** |
| `check-links.py` | 构建产物 HTML | rc=1 |
| `check-anchors.py` | 源码里的 `#锚点` | rc=1 |

**为什么需要三个**：VuePress 内置的 links-check 只验证 markdown 链接的**目标文件**存在，**不校验锚点**（实测：注入坏锚点后构建照常成功）。而锚点是文档里最容易腐烂的东西 —— 标题改一个字，所有指向它的链接就都死了，且只在用户点击时才暴露。

`check-links.py` 是产物级复核：它读最终 HTML，确认每个链接都能解析到实际生成的文件。它会**自动探测 base 前缀**（本地 `/`、GitHub Pages `/typephp-qt/`）。

`check-anchors.py` 按 VuePress 的 slug 规则把标题转成锚点再比对。规则实测为：小写 → 去行内代码标记 → 去粗斜体标记 → 空格转 `-` → 去 ASCII 标点（**保留 `-`**）→ 去全角标点 → 下划线转 `-`。

## 部署

推送到 `main` 后由 `.github/workflows/docs.yml` 自动构建并发布到 GitHub Pages。

首次部署前需要在仓库设置里把 Pages 的 Source 选成 **GitHub Actions**。

workflow 用 `github.event.repository.name` 推导 base 路径，所以仓库改名后不会静默 404。

## 目录结构

```
docs/
├── package.json
├── check-links.py / check-anchors.py   # 构建期检查
└── src/
    ├── .vuepress/config.ts             # 导航、侧边栏、base、locales
    ├── README.md                       # 英文首页
    ├── guide/ widgets/ advanced/ reference/ faq.md     # 英文（30 页）
    └── zh/                             # 中文（29 页，同结构）
```

## 写文档的约定

- **改 `src/` 下的 markdown，不要改 `src/.vuepress/dist/`** —— 后者是构建产物，已被 gitignore。
- **加新页面要更新 `config.ts` 的 sidebar**（中英两处），否则页面能访问但侧边栏里没有，构建时还会警告 `is missing sidebar config`。
- **跨页锚点要对着真实标题写**，写完跑 `check-anchors.py`。标题里的行内代码和下划线会改变 slug。
- **中文页的站内链接必须带 `/zh/` 前缀**（如 `/zh/guide/events.md`），否则会跳到英文站。
- **代码示例要能跑。** 涉及 API 的，对着 `src/QtApp.php` 和 `src/WidgetTree.php` 核一遍方法名。

## 搜索

用官方 `@vuepress/plugin-search`，索引内联进客户端 bundle（没有独立文件）。按语言分开配置占位提示：英文站 `Search docs`，中文站 `搜索文档`。

中文没有词间空格，默认的英文分词对中文命中率不高 —— 该插件用的是前缀匹配，实测常用词（`WidgetTree`、`闭包实参`）都能搜到。
