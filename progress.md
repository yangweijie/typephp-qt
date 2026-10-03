# Progress Log — typephp-qt

## Session 26 — 2026-10-03（补交 .lib + WebView2 分支 W1–W5 真机验证）

### 任务
> 「补交 WebView2Loader.dll.lib + WebView2 分支 W1–W5 真机验证」

两件都是 Session 25（macOS）留下的尾巴：那台机器既**没有**这个 `.lib`，
也**跑不了** Windows 目标，只能交静态审查。

### 1. 补交 .lib（已完成并真机验证）
`.gitignore:9` 的 `*.lib`（构建产物规则）把 vendor 的
`third_party/webview2/x64/WebView2Loader.dll.lib` 吞了 —— 而 `examples/hello/project.yml`
与 `qtphp new` 模板都写死链接它。放行规则 `!third_party/**/*.lib` 上一轮已提交，
本轮把**文件本身**从本机补进去。

验证方式是最硬的那种 —— **克隆到干净目录再编译**：
```
git clone D:/git/php/typephp-qt D:/tmp/wvclone
cd D:/tmp/wvclone && php bin/qtphp build examples/hello
→ Build successful；链接命令行里 /LIBPATH 指向克隆自己的 third_party
→ LNK1181 不再出现
```
文件是 3590 B 的导入库（`!<arch>` 头，含 `__imp_CreateCoreWebView2EnvironmentWithOptions`），
不是空壳。

### 2. W1–W5 真机验证（结论：3 条成立、1 条不成立、1 条窄窗口风险）

写了个临时探针 `examples/wvprobe`（验完即删）。关键设计是**阳性对照** ——
先证明「进程过滤/采样」本身有效（`--hold` 时数到 6 个），再谈「数到 0」才有意义。

| # | 判定 | 真机证据 |
|---|---|---|
| W1 `loaded` value 恒空 + 泄漏 | ✅ 成立 | `value=[]` 两次都空，而 `title` 正确报 Page A/B |
| W2 `navigating` 慢一拍 | ✅ 成立 | 报 `about:blank` → `a.html`，目标其实是 `b.html` |
| W3 不调 Close 会攒进程 | ❌ **不成立** | 4 并发峰值 9 → 销毁 3 个后 6 → 全销毁 0 |
| W4 回调裸捕 this | ⚠️ 真实但窗口窄 | 20 轮建完即销毁：40 回调 / 0 次 AFTER-DESTROY / 无崩溃 |
| W5 三个动作是死代码 | ✅ 成立 | 确认无任何 PHP 入口 |

**W3 的反例最值得记**：`cleanup()` 会 `delete window_`，宿主窗口一销毁，控制器跟着
宿主 HWND 一起回收 ⇒ **实测不泄漏**。「不调 Close 就不释放」是文档约定，
不等于本工程真会泄漏；审查只能说「依赖了未承诺的行为」，不能说「会泄漏」。

**W4 也值得记**：回调能赢是因为环境是进程级单例 ⇒ 首个之后都走同步路径，
而 render 在帧内同步建树、帧内消息泵把回调送达 ⇒ 窗口被压得很小。
但代码缺陷是真的（确实 `delete window_`、确实裸捕 this），所以照修。

### 3. 修复与复验（全部真机复验）
- W1 → `takeCoTaskMemString(uri)`：`value=[file:///D:/tmp/wv/a.html]` ✅
- W2 → `args->get_Uri()`：`navigating` 报 `a.html`→`b.html` ✅
- W3 → 析构显式 `controller_->Close()`：峰值 9 → 全销毁 0，无回归 ✅
- W4 → `shared_ptr<atomic<bool>> alive_` 生命周期旗标：20 轮无崩溃、无残留 ✅
- W5 → 接进 `call` 表 + `qtWebViewCall` 分发 + PHP 三个薄封装：
  真机 `goBack`→a.html、`goForward`→b.html、`reload`→重新导航 ✅

**顺带修了一个新 API 的空洞**：WKWebView 本来就有 reload/goBack/goForward
（`WKNavigation`），所以 `qtWebViewCall` 也给它接了实现，否则这个新方法在 macOS 上
会静默失效。三个后端的能力矩阵已写进文档（中英）。

### 4. 回归
```
补交验证  克隆到干净目录编译 → Build successful（LNK1181 消失）
qtphp lint  契约一致
qtphp test  126 tests / 212 assertions（+3：三个动作 + 未知 id 静默）
hello       --selftest / --difftest / --shot 三个开关 exit 0，selftest 25/25
QTextBrowser 关掉 /DQT_WEBVIEW2 重编 → 三个动作静默无操作、exit 0（符合文档）
docs        63 页，链接与锚点全过
清理        examples/wvprobe、/d/tmp/wv*、wv4.log 全部删除（未入库）
```

### 5. mac 打包侧收口：真实体积 + webview 是否可用 + 两处缺陷（用户追问「打包多少 MB、支不支持 webview」）

**体积**：`qtphp package` 打印的 `99.8 MB` 是虚高的，真实 **83.6 MiB**（87,612,051 B / 54 文件），
`du -sh` 84 M，`ditto -c -k` 压成 zip **29.3 MiB**。构成：`libicudata.78.dylib` 31.66 MiB（**38%，最大单项不是 PHP**）
> 产物二进制 21.55 MiB（PHP/PHPX 静态链在里面，bundle 无 `libphp.dylib`）> Qt 三件套 15.6 MiB
> icu i18n/uc 4.2 > brew 传递依赖约 5 > 平台插件 1.6。
另发现 `macdeployqt` 会**顺手 strip** 产物：`build/hello` 25,564,904 B → bundle 内 22,594,160 B
（`nsyms` 121,795 → 6,890、`__LINKEDIT` 3.59 → 0.62 MB，`__text` 不变）。

**webview 在打包产物里可用**，三条独立证据（不靠单一判据）：
① bundle 内 `--shot` 出图 sha256 = `2728fb1a6a06…`，与开发态 cocoa 基线逐字节相同，裁分组标题读图 =
「WebView（backend=wkwebview，js=支持）」；② `otool -L` 里 Qt 三个 framework 全改写成 `@executable_path/…`、
WebKit 指向 `/System/Library/Frameworks/…`（系统框架，故意不进 bundle）⇒ 目标机不需要装 brew Qt；
③ `open Hello.app` 后**新起** `com.apple.WebKit.WebContent` 进程 ⇒ 内核真拉起来了，不只是链接通过。

**两处缺陷都修了**（详见 F31、task_plan 28.1）：
P1 `dirSize()` 跟随符号链接 ⇒ framework alias 重复计数（1 行修）；
P2 打包后 assets 在 `Contents/Resources` 而 `qtResolvePath()` 只查 exe 目录 → cwd ⇒ 托盘图标静默丢。
P2 按 bundle-aware 修，并把触发条件钉成「exe 目录的父目录名为 `Contents`」，
这样 Linux/Windows 上项目根恰好有 `Resources/` 也不会被抢先命中。
复验：`package` 打印 83.6 MB；bundle 从 `cwd=/tmp` 与 `cwd=/` 跑均无警告、哈希仍 `2728fb1a…`；
**负控制**（把 `Resources/assets` 改名）警告立刻回来；开发态二进制行为不变；
offscreen `--selftest` passed；`phpunit` **126 tests / 212 断言**、`lint` 契约一致、
`codesign --verify --deep --strict` 通过（临时改名已还原）。中英 `packaging.md`/`dialogs.md` + README 同步。

### 教训
1. **阳性对照不是可选项。** 如果只报「销毁后 0 个进程」，很可能只是过滤器没匹配上。
   先让 `--hold` 数到 6，后面的 0 才有意义。
2. **别去 kill 不认识的进程。** 一开始看到 24 个 `msedgewebview2.exe` 差点当垃圾清掉，
   查来源发现是 **Oray/向日葵**（`C:\ProgramData\Oray\Webview2\…`）。改成按
   `--user-data-dir` 过滤自己的，精确且不碰别人的。
3. **`g_alive` 这种进程级计数器会漏报 per-widget 的生命周期问题**：第 i 个控件的回调
   若在第 i+1 个存活期间到达，计数器不为 0 ⇒ 漏报。判据必须绑到控件本身。
4. **审查推断与真机结论可以背离，两边都要如实写。** W3 是我这轮唯一「推断错了」的一条，
   而它恰好是最有价值的产出。
5. **工具自己打印的数字也是待证假设。** 「打包 99.8 MB」这个数是 `dirSize()` 算的，我第一反应是引用它；
   是 `du -sh`（84 M）与逐文件求和（83.6 MiB）两个**独立口径**都对不上，才逼出「尺子跟随符号链接」这条缺陷。
   凡是交付物体积/耗时/百分比，报出来之前先找一个不由被审对象提供的量法。
6. **两个对照口径算出完全相同的数 ⇒ 先怀疑实验写错了，而不是结论成立。** 第一次做「跳过符号链接」的对照时，
   我把跳过那侧写成累加 8 字节，于是两种口径都得 83.6，差点下结论「不是重复计数」。
   同数不等于同因，尤其当差异本该是十几 MB 时。
7. **`--shot` 的 sha256 只覆盖窗口像素，抓不到 bundle 里的资源缺失。** 打包后托盘图标加载失败时哈希仍与基线逐字节相同
   （托盘不进 `grab()` 范围）⇒ 打包验收需要一条独立判据：**stderr 不得出现 `qWarning`**。
   这条现在靠人眼看日志，是待补的自动化缺口。

---

## Session 25 — 2026-10-03（macOS 本机重编 + 真跑 webview）

### 任务
> 「尝试重新编译运行 hello，我增加了 QTextBrowser 和 webview2」+「我说的编译是运行 mac 本机版，不是 linux 版」

即：用户侧完成 Phase 24（webview 双后端）后，在 **Apple Silicon 本机**重新编译并运行示例。
Linux 那条线（12.x 之后的跨发行版复验）本轮**不动**，容器 `tgl` 原样留着。

### 先看清代码面（不凭提交信息猜）
`git show 7f573fb` + 读 `cpp-src/qt_webview.cc`（412 行）：一个 `webview` 控件、`QT_WEBVIEW2`
编译期二选一；mac 上 `#else` 分支 = `QTextBrowser`（`anchorClicked` → `link` 事件、
`url`/`html` 都吃、`zoom` 静默忽略）。PHP 侧 `WidgetTree::webView()`/`html()` +
`QtApp::webViewBackend()`/`webViewSupportsJs()`，stub 两个新函数（`Bool` 不是 `bool`）。

### 实测（全部真 rc，`out=$(…); rc=$?`）
| 步骤 | 结果 |
|---|---|
| `php bin/qtphp lint` | ✅ 契约一致，**26 个桥接函数**（+`qt_webview_backend`/`_supports_js`） |
| `php bin/qtphp test` | ✅ **123 tests / 201 assertions**（用户 +7 条 webview 测试） |
| `php bin/qtphp build examples/hello` | ✅ rc=0，入口 `project.macos.yml`，**11 个 TU**，产物 Mach-O arm64 **25.5 MB** |
| `--selftest`（offscreen） | ✅ **25/25** rc=0 |
| `--difftest`（offscreen） | ✅ **20/20** rc=0 |
| `--shot`（cocoa 真窗口） | ✅ rc=0，3 秒出 760×720 PNG |
| `./build/hello`（实跑） | ✅ 窗口起来、事件循环正常，终止后 rc=0 |

读图确认：分组标题 `WebView（backend=textbrowser，js=不支持）`，`html` 里 `<h1>` 蓝色、`<b>` 加粗、
「加粗 · 斜体 · 中文」中文渲染，其余控件（菜单/三个分组/进度条/底部三按钮/状态栏「心跳 2 跳」）无回归。

### 关键结论：mac 入口 yml 一行都不用改
`sources` 继承 ⇒ `qt_webview.cc` 自动进 mac 编译；`cxx-flags`/`link-libs` 整体替换 ⇒
`/DQT_WEBVIEW2`、`/I…webview2/include`、`WebView2Loader.dll.lib` 全被清掉 ⇒ 编译期自动落到
QTextBrowser 分支。**代价是反过来的**：若哪天把 mac 段改成「追加」，MSVC 的 `/D` 会直接进 clang 而编译失败。

### 补验 `url` 分支（示例只用了 `html`，`url` 在 mac 上没人碰过）
临时把示例换成 `url => 'assets/webview_url_probe.html'`（含 `<code>`、相对 `<img src="icon.png">`、
`<a href>`）重编出图 —— **渲染成功**，相对图片按文档 base URL 解析正确 ⇒ `qtResolveUrl()` 的
「无 scheme → `QUrl::fromLocalFile(qtResolvePath())`」在 mac 上成立。
**第一次探针失败是我自己的路径写错**：裸文件名相对 **exe 目录**（`build/`）解析，而 assets 在
`build/assets/` ⇒ 文件不存在。这条暴露了一个真实差异：**QTextBrowser 后端在文件缺失时零诊断**
（不报错、不警告、rc 仍 0），而 WebView2 会发 `loaded` + `success=false`（见 F26 尾部）。
探针已全部还原：`git checkout -- examples/hello/src/main.php` + 删两处临时 html + 重编，
复验 `--selftest` 25/25、`--difftest` 20/20、`--shot` 出图与探针前逐内容一致。

### 补验清单三项（F26 尾部那三条「未 mac 验」→ F27 第 1–3 条）
> 「继续 未在 mac 验的清单」

| 项 | 结论 | 证据 |
|---|---|---|
| `anchorClicked` → `link` 的真实接线 | ✅ **成立** | `--selftest` 走 `$app->dispatch()`，**绕过 Qt 信号**，所以这条此前零覆盖。Accessibility 关、无 `cliclick` ⇒ 应用内合成：`qtWebViewProbeFirstLink()` 找 anchor → `cursorRect()` → 自证 `anchorAt()` 非空 → `sendEvent(viewport, press+release)`。probe 与 `PROBE-LINK-EVENT` 各打印一行。**鉴别力反证**：摘掉 `enqueue` 后 `anchorAt` 仍命中、事件消失 |
| `zoom` | ✅ **确认静默忽略、零副作用** | 设 `zoom=2.0` 与不设，两次 `--shot` 的 PNG **sha256 相同**（`633da154…`），rc 均 0 |
| 远程 `url` | ❌ **Qt 6 `QTextBrowser` 不支持**，且**文档在过度承诺** | Qt 打 `QTextBrowser: No document for …`、区空白、rc 仍 0。逐项排除：`curl` 200（有网）／补 `-framework QtNetwork` 后 PNG 一字不变／换 `http` 同样失败／`QT_TEXTBROWSER_SYNCHRONOUS=1` 无效／`file://` 对照能走渲染。⇒ `sourceInfo()` 只做本地与 `qrc:`。**查出** `docs/src/zh/widgets/webview.md:44`「带 scheme 的原样使用」在非 Windows 平台是错的（`data:` 未实测） |

**被追问「`source` 是 QUrl，你拿纯字符串测的吗？」之后重做到对象级**（F27 3.1/3.2）：桥接层本来就是
`tb->setSource(qtResolveUrl(...))` 传 QUrl（`qt_webview.cc:407`），而那句 Qt 警告打印的正是 `url.toString()`；
为排除「解析错」与「异步没等到」两种可能，另写**不经 PHP/AOT 的独立 Qt 程序**
（`/tmp/qtb_probe/qtbtest.cc` + `scheme.cc`，brew Qt 6.11.2 framework 直链，offscreen）实测：
`QUrl("https://example.com/")` → `valid=1 scheme=https host=example.com isLocalFile=0`、警告在 `setSource` **同步**出现、
真 `QEventLoop` 跑满 6 秒（50 ms 轮询）文档恒为 0 字符、同进程 `QNetworkAccessManager` 却 `err=0 http=200 bytes=577`、
`loadResource(remote img)` 返回 null；再加 `otool -L QtWidgets` 的 NEEDED **不含 QtNetwork** ⇒ 该后端发不出请求。
scheme 矩阵顺带量掉那条「未实测」：**`data:` 与 `data:;base64` 同样空白，只有 `file` 可用** ⇒ 文档缺陷范围比原先记的大。

### WebView2：本机跑不了 ⇒ 边界探测 + 静态审查 + 一个「克隆即坏」缺陷（F27 第 4–5 条）
先探可达边界，避免假装验过：`/Applications` 无 Parallels/VMware/UTM/Fusion、`~/.ssh/config` 只有 github、
无 wine、Apple Container 只能跑 Linux；`brew install mingw-w64` 是 GB 级而**启动卷只剩 3.5 Gi** ⇒
连「交叉语法检查」这条路都不成立 ⇒ `QT_WEBVIEW2` 分支在本机**不可能有运行时证据**。

改成读代码交出 W1–W5（详见 F27 第 4 节）：W1 `loaded` 的 value 恒为空串且每次导航泄漏一个 CoTaskMem 宽字符串、
W2 `navigating` 取的是导航**前**的源、W3 无析构函数从不调 `Controller::Close()`、
W4 三级 COM 回调裸捕 `[this]` 无生命周期保护、W5 `reload`/`goBack`/`goForward` 在 PHP 侧无入口 = 死代码。

**最硬的缺陷是环境外的 W0**：`.gitignore:9` 的 `*.lib`（本意排 MSVC 产物）把 `third_party/webview2/x64/WebView2Loader.dll.lib`
一起吞了，而 `examples/hello/project.yml:39` 与 `bin/qtphp:470` 的 `qtphp new` 模板都写死链接它、
`third_party/webview2/README.md:13` 还声称已 vendor ⇒ **新克隆在 Windows 上链接必报 `LNK1181`**。
本轮加 `!third_party/**/*.lib` 放行并用 dummy 文件三重确证（`check-ignore` rc=1、`git add -n` 可收、
`/build/` 规则未削弱）。**文件本身只能从 Windows 那台机器补交**（本机磁盘上没有）。

### 追问之后自查出的两条测量学缺陷（F28）
重做 QUrl 那件事时顺手复核了自己的证据，两把尺子都有问题：

1. **`--shot` 的 PNG sha256 跨次不稳定**。同一个二进制连跑 6 次 → 5 次 `fc9319b597…` + 1 次 `0876b10bf0…`。
   用 PIL `ImageChops.difference().getbbox()` 定位：行 62–83 是 **QLineEdit 光标闪烁**，行 362–380 是
   「打开日志窗口」按钮的 **hover/焦点描边**；PNG 无 `tIME` chunk ⇒ 差异是真实像素。
   ⇒ 之前所有「两次 sha256 相同」的证据只能**单向**读：相等 ⇒ 无变化（成立，噪声只会让哈希不同、不会抹平真实变化）；
   不同 ⇒ 回归（**不成立**）。今天自己就撞了一次假警报：两次干净重建分别出 `3c39bdc8…` 与 `fc9319b5…`。
2. **那次「还原探针后重编」不干净**。产物里仍查得到 `--linkprobe`/`WVURL`/`webview_url_probe`，
   `build/extension-hello.cc` 的 mtime 停在探针期。用一次性标记 `Hello, INCR-CACHE-MARKER!` 做可复现实验：
   改源码 → 增量构建会重写生成物（增量本身没坏）；**还原**源码 → 增量构建**不重写**生成物、
   字面量池只增不减（残留全在无引用的池条目里），而编出的代码是新的（还原后产物与全清重建产物
   标题行区域 `bbox=None`）。⇒ 已改成**删掉整个 `build/` 全清重建**重测：`build` rc=0（11 TU）、
   `--selftest` **25/25**、`--difftest` **20/20**、`--shot` rc=0、产物内探针字符串 **0 处**，
   出图哈希落回 `fc9319b597…`。工作树只剩 `.gitignore` + 三份计划文件。

### 记账
`task_plan.md`：新增「Phase 24 分解（webview 后端，macOS 本机复验）」24.1/24.2/24.3/24.4、Phases 表 24 行补 mac 复验、
Errors 表 +3 行（url 空白与两后端可诊断性不对等 / 远程 url 不支持 + 文档过度承诺 / `.gitignore` 吞掉 vendor 的 import lib）、
目标产物补 webview 行并把 stale 的
「112 tests」「selftest 14/14」改成 123/201 与 25/25、规模表 5221 → **6039**（cpp-src 2676 含
`qt_webview.cc`、src 1109、CLI 2097、stub 157）。`findings.md`：F26 尾部补三小节
（mac 复验数字 / 可诊断性不对等 / **仍未在 mac 上验的清单**）、新增 **F27**（link 接线 / zoom / 远程 url 排除表 /
WebView2 静态审查 W1–W5 / `.gitignore` 吞 `.lib` 的证据）。
`.gitignore`：WebView2 块后加 `!third_party/**/*.lib` + 3 行说明（唯一的产品代码侧改动）。

### 24.5 让 `--shot` 出图可复现（接上一条「下一步」第一条，F28 §3）

**先补 forcing function**：`--shot` 分支加 TEMP 探针 `SHOTDELAY=<ms>`（每 10ms 泵一帧），把 grab 推到墙钟
不同相位。这一步顺带解释了上一条记录里那个反证失败（「摘掉冻结 16/16 同哈希」）—— 不额外延时时 grab 太快，
噪声根本没机会出现，**不是**冻结有效。

五轮全清重建（① 冻结+心跳定时器 → ⑤ 控制组，逐轮数字见 F28 §3 表格）逼出**两层**噪声，
**都不是** F28-1 当时记的 caret/hover：

| 层 | 现象 | 改法 |
|---|---|---|
| 应用自己的 1 秒心跳 | 差异带 `(25,366)–(159,714)`，读图是「心跳 2 跳 → 3 跳」 | `main.php` 把 `setTimer('clock',1000)` 移到 `--shot` 分支之后 |
| 输入框有没有焦点 | 整行 QLineEdit 边框 `#b6b6b6` ↔ Fusion 焦点高亮 `#7e9dc2` + 光标有无 | `snapshot()` grab 前 `clearFocus()`、抓完还原 |

**关键更正**：F28-1 那版 `qtFreezeTransientUi`（`setCursorFlashTime(0)` + `clearFocus(); setFocus();`）
实测**不收敛** —— 有焦点时它把焦点留着、没焦点时它什么都不做，冻结开/关两轮跑出**同一对哈希值**。
harness `focus3.cc`（`none`/`refocus`/`clear` × 两个起点）量化：2 种 / **2 种** / **1 种**
⇒ 判据是「从不同瞬态起点必须收敛成同一帧」，函数已删、逻辑内联进 `snapshot()`。

两条差点写进去的**错归因**被控制组否掉：`repaint()` 版稳定 19 样本，但它在第一次 grab **之前**只多两次
`focusWidget()` 读取，不可能改像素 ⇒ 那是环境漂移（去掉 repaint 的 ⑤ 同样 15 样本 1 种）；
`WA_UnderMouse`/hover 在本机无法按需复现（只置属性不改变像素），保留 1 行清理并**如实标未证**。

终验（删探针 + 全清重建）：`--selftest` 25/25、`--difftest` 20/20、`phpunit` 123/123（201 断言）、
`--shot` cocoa 8/8 → `a70cd7ae05b6…`、offscreen 8/8 → `4b777e2ed74e…`；`a70cd7ae` 与修复前那几轮的灰框哈希
逐字节相同 ⇒ 干净帧未被改动，只是不再取决于激活竞态与秒针。**sha256 现在可以当 CI 基线**（按平台各存一份）。

### 24.6 mac 上「走 Qt WebView 类」的路线勘查（F29，未动任何安装）

用户给的新方向。三条路线的事实先量完（启动卷只剩 **2.5 Gi**，所以这一轮不装东西）：

| 路线 | 实测事实 | 结论 |
|---|---|---|
| 装 `brew qtwebview` | 依赖表里写着 `qtwebengine`（+ `qtdeclarative`/`qtpositioning`/`qtwebchannel`），本机只有 `qtbase`；QtWebEngine 沿用仓库既有口径 1.5–2 GB | ❌ 磁盘装不下，且它在 mac 上只是 WKWebView 的 QML 壳 ⇒ **零净收益** |
| 直接用系统 WKWebView（`.mm` 桥） | 探针实测 JS / 远程 https / `data:` 三条全过（`title='JS-RAN-3'` / `'Example Domain'` / `'DATA-OK'`），**零安装**；tpc 原生支持 `.mm`（`Translator.php:2313-2323`、`examples/apple-native/`） | ✅ 能力已钉死；缺的是工程接线：`bin/qtphp:1995` 只 `glob('cpp-src/*.cc')`、脚手架清单 `:448-450` 同样写死，且 NSView 嵌进 `QWidget::winId()` 未跑通 |
| `QNetworkAccessManager` 取字节再 `setHtml` | 能补远程与 `data:`，但**永远没有 JS**，mac/Linux 要引入 QtNetwork 依赖 | ⚠️ 只算半个方案 |

Qt 官方文档口径（F29 §1）：「On macOS, the system web view is used in the same manner as iOS」
⇒ 「Qt WebView 类」在 mac 上就是 WKWebView，所以真问题不是「能不能拿到那个类」，
而是「要不要给本仓加第三个后端（`.mm`）」。已把选择权交回用户，**未擅自开工**。

### 24.7 WKWebView 嵌入 spike（用户选定 `.mm` 后端后的 #9 第 ① 步，F29 §5）

路线勘查里唯一没跑通的一环就是「NSView 能不能嵌进 `QWidget::winId()` 并真的合成」，先把它钉死再动本仓。
探针在工作区外（`/tmp/wkv_probe/spike.mm`、`spike2.mm`），纯 AppKit + brew Qt6，不碰仓库文件。
挂载就三行，ARC 下 `WId → NSView *` 必须走 `(__bridge NSView *)(void *)wid`（`reinterpret_cast` 直接编译失败）：

```objc
NSView *parent = (__bridge NSView *)(void *)host->winId();
auto *web = [[WKWebView alloc] initWithFrame:parent.bounds];
web.autoresizingMask = NSViewWidthSizable | NSViewHeightSizable;
[parent addSubview:web];
```

四条实测（800×600 窗口、宿主 bounds 760×510，判据全部数值化）：

| 待证 | 手段 | 结果 |
|---|---|---|
| 引擎在 Qt 宿主里活着 | `evaluateJavaScript:"document.title"` | `js_title=WK-JS-42` |
| **真的合成进 Qt 窗口**（不是只挂在层级里） | `screencapture -x -R<global rect>` + 读图/采样 | 中心 `#88bcdd`（网页蓝），洋红宿主底色 `#ff00ff` **一点没露** ⇒ frame 与坐标换算正确 |
| 缩放跟随 | `resize(420,300)` 后打印 frame + 再抓 | `host.bounds=380x210 web.frame=0,0,380,210`，再抓仍是蓝 ⇒ `autoresizingMask` 够用，Qt 侧不必同步几何 |
| `--shot` 取帧路径 | 同一时刻 `window.grab()` / `host->grab()` 存 PNG 采样 | 中心均 `#ff00ff` ⇒ **原生子 view 不进 `QWidget::grab()`**，抓出来是宿主底色空洞 |

最后一行是 F27 已记的「`--shot` 截不到 WebView2」的**第二次确认**（同根因：独立原生表面，Qt 绘制引擎看不见）。
新信息在于**基线回退**：mac 当前默认后端 QTextBrowser 是 Qt 自己画的、能截到，换成 WKWebView 后
`--shot` 帧里的 webview 区域会变成空洞，刚锁定的 mac 基线 `a70cd7ae05b6…` 必然改变。
所以 #9 第 ④ 步要连带把 webview 的验收手段从像素换成 `evaluateJavaScript` 读回 —— 这比像素更强
（像素只能证明「画了」，读回属性能证明「页面真的加载并执行了脚本」）。
备选（未做，成本高）：`WKWebView takeSnapshotWithConfiguration:` 能把页面渲成 `NSImage`，
理论上可合成回 snapshot，但 `QtWindowBox::snapshot()` 是同步接口、要接异步回调。

### 24.8 WKWebView 第三后端落地 + 构建接线（#9 第 ②③ 步，F29 §6）

用户裸「继续」→ 按上一步留下的计划做第 ②③ 步：写 `cpp-src/qt_webview_wk.mm`（256 行）并把 mac 的
构建链打通（`examples/hello/project.macos.yml` + `bin/qtphp` 里 `qtphp new` 的模板两处同步）。
验收**不看像素、看事件** —— §5 已经量过原生子 view 不进 `QWidget::grab()`，而 `title` 只有 JS 真跑了才会出现。

| 契约项 | 实测（verbatim，临时探针 `[wkev]`，验完已删） |
|---|---|
| `html` + JS | `navigating value=about:blank` → `loaded value=about:blank` → `title value=WK-JS-42` |
| 远程 `url` | `navigating value=https://example.com/` → `title value=Example Domain` → `loaded value=https://example.com/` |
| `zoom` | `flushPending -> pageZoom=2.50`、`didFinish -> pageZoom=2.50`（对照 1.00，跨导航保持） |
| 链接点击 | `decidePolicy type=0` → `link value=https://link-probe.invalid/x`，之后无 `navigating` ⇒ 拦下且未跳转 |

一次编译吐 12 条 ObjC++ 错（`@interface` 不能进匿名 namespace、ivar 名 `link` 撞 POSIX `::link`、
Qt6 `toNSString()` 无参、`QUrl` 没有 `toNSString`），链接再吐 `_OBJC_CLASS_$_NSString/NSURL/NSView` 未定义
⇒ 显式 `-framework Foundation/AppKit`；**QtGui 自己依赖 AppKit 不等于 ld 会替目标文件去它的依赖里找符号**。
配置侧的坑是 tpc 的 `mergeConfig` 对 **list 整体替换** ⇒ mac 入口必须把整份 `sources` 重写一遍才加得进 `.mm`。

**新引入的真缺陷（已修）**：`QT_QPA_PLATFORM=offscreen --shot` SIGSEGV（rc=139、PNG 不生成）——
offscreen 的 `winId()` 不是 NSView。修法是在 `attach()` 首行按 `platformName()` 设闸门，而不是加 `isKindOfClass:` 兜底。

终验（删探针 + 全清重建）：selftest 25/25（cocoa 与 offscreen 各一遍）、difftest 20/20、
`php bin/qtphp test` 123 tests / **203** 断言、`lint` 契约一致；
cocoa `--shot` ×8 → `2728fb1a6a06…`、offscreen ×8 → `6c4832a6b738…`。
**控制组**：只注释 `-DQT_WEBVIEW_WK` 重建 ⇒ 帧与 24.5 的旧基线 `a70cd7ae05b6…` 逐字节相同；
开/关像素差 129,830 px（23.73%），差异包围盒 x[33..726] y[454..664] 全在 WebView 分组内、y=454 以上零差异。
脚手架闭环：`qtphp new demo` → 生成的 mac yml 含 `.mm`/`-DQT_WEBVIEW_WK`/`-framework,WebKit` → `build .` rc=0
（25,316,424 B）→ `--selftest` passed。文档同步改了 6 处（中英 `widgets/webview.md`、中英 `reference/api.md`、
中英 `guide/qt-setup.md`）+ `php-src/qt.stub.php` + `WidgetTree`/`QtApp` docblock。
**顺带揪出测试替身在说谎**：`FakeBridge::qt_webview_supports_js()` 写死 `=== 'webview2'`，
会把 `wkwebview` 报成不支持 JS —— 正好骗过它自己注释里说要防的那类误判，已改为两个原生后端都算支持。

### 24.9 第 ④ 步收口：一次「按后端优化泵帧」被实测否掉（F28 §4）

计划里第 ④ 步写着「把 `main.php` 那段无条件泵 120 帧的 TEMP 按 `webViewBackend()` 收口」。
照做（`webViewSupportsJs() ? 120 : 3`）→ 全清重建 → **控制组直接炸**：关开关（QTextBrowser）那一版
cocoa `--shot` 8 次跑出 **4 种**哈希（`2fec2364`×1 / `4393a1b2`×2 / `a2dd7139`×3 / `db380cff`×2）。

定位：PIL 两两 `difference().getbbox()` ⇒ 四张图**所有**差异都在 `(622,66)-(636,80)` 一个 14×14 的格子里，
放大看图是 `name_input` 右边的**清除按钮（灰底 ✕ 圆圈）**，四种帧只差它的不透明度
（同格主灰阶 `#bababa`/`#ededed`/`#eeeeee`/`#dcdcdc`）⇒ Qt 的 `QLineEdit` 清除按钮自带淡入淡出动画，
3 帧抓到的是动画中途。**所以那句 `// TEMP: 给 WebView2 异步初始化留时间` 是错归因**：泵帧数真正管的是
「瞬态动画停下来」，与 webview 后端无关 ⇒ 改回无条件 120 帧，注释换成实测出来的两条真理由。

连带发现**文档在教一条会坏基线的配方**：`headless.md`、`qtphp new` 模板、技能 references 里都写
`$app->runFrames(3)`。四处全改成「泵到动画停（120 帧）」，并在中英 `advanced/headless.md` 新增
「把 PNG 变成 CI 基线」一节：三条前置条件（无墙钟内容 / 泵到动画停 / 抓前清焦点）+
收敛判据（×8 必须 1 种）+ 当前三条基线值（cocoa `2728fb1a…`、offscreen `6c4832a6…`、
关开关 `a70cd7ae…`）+ 「哈希只能单向证明没变」。

能力侧收口：`webViewBackend()` 三值定稿，`QtAppTest` 新增后端 × JS 能力矩阵测试。

终验（恢复开关 + 全清重建）：cocoa ×8 → `2728fb1a6a06…`、offscreen ×8 → `6c4832a6b738…`（各 1 种），
两平台 `--selftest`/`--difftest` 全 rc=0，`php bin/qtphp test` **124 tests / 209 assertions**、`lint` 契约一致；
脚手架 `qtphp new demo` → `build .` rc=0 → `--selftest` passed → `--shot` ×3 同哈希（临时目录已清）。

### 24.10 #10「关瞬态动画」：把动画推到终点，泵帧从 120 降回 3（F28 §5）

24.9 的收口是**止血**（多等 117 帧），不是终解 —— 用户直接点破：「#10 先做，关瞬态动画」。

先撞上三条 Qt6 API 事实（都是编译期纠正的，不是查文档得来的）：
`Qt::UIEffect` 只有 menu/combo/tooltip/toolbox 五种，**没有**我在 24.9 里猜的
`QT_UI_EFFECT_ANIMATE_UI_CHANGE`；`QAbstractAnimation::State` 是 `{Stopped, Paused, Running}`，
**没有** `NotRunning`；`jumpToEnd()` 只在 `QPropertyAnimation` 上。
可用的通路是：`QLineEdit` 那条淡入淡出是 parented 到该 line edit 的 `QTimeLine` ⇒
`window_->findChildren<QAbstractAnimation *>()` 抓得到，对在跑的做 `setCurrentTime(totalDuration())` + `stop()`。
另一条因果链值得记：那条动画**正是上一句 `clearFocus()` 自己触发的**（焦点丢了清除按钮才淡出），
所以「清焦点」和「3 帧不稳定」是同一根链上的两端，不是两个独立噪声。

**阶梯测量（每步只动一个变量，都是全清重建 + cocoa ×8）**：
| 冻结 | 泵帧 | 结果 |
|---|---|---|
| ✗ | 120 | 1 种 `2728fb1a…`（= 24.9 基线） |
| ✓ | 120 | 1 种 `2728fb1a…` ⇒ 120 帧时动画本就停妥，冻结是 no-op（与首行的比对是**跨时间窗**比同一串哈希，按 F28 §1 单向读法成立） |
| ✓ | **3** | cocoa 8/8 `2728fb1a…`、offscreen 8/8 `6c4832a6…` ⇒ **两条基线值未漂移** |
| **✗**（控制组 A） | 3 | **4 种**：`a70ad48f`×1 / `1b586809`×3 / `e8f85628`×3 / `4b9d1455`×1 ⇒ 冻结承重 |
| ✓ + 关 WK 开关（控制组 B） | 3 | 8/8 `a70cd7ae…` ⇒ 24.9 那次「3 帧 4 种」确实只由动画引起，与后端无关 |

**控制组必须在同一时间窗、同一配置下跑** —— 24.5 的教训是「现象消失可能是环境漂移」，
这次反过来用：去冻结的那 8 次是在装好冻结代码之后的同一个窗口里跑的，跑出 4 种才排除掉漂移。

终验：两平台 `--selftest` 25/25、`--difftest` 20/20 rc=0；`--shot` **445 ms**；
`php bin/qtphp test` 124/209、`lint` 契约一致；恢复开关后全清重建 cocoa/offscreen ×8 各 1 种；
脚手架 `qtphp new demo` → `build .` rc=0（25,317,288 B）→ selftest passed → `--shot` ×8 distinct=1。
文档/模板/技能四处配方从「泵到动画停（120 帧）」改回 `runFrames(3)` + 说明动画由 `snapshot()` 冻结。
已知语义变化（写进注释与文档）：`--shot` 只能拿到动画**终点**，将来要验中间态得自己构造帧。

### 教训
**「编译运行通过」不等于「新分支被走过」**。示例只喂 `html`，`url` 那条（含 `qtResolveUrl` 的
scheme 判定、相对路径解析）在 mac 上是零覆盖 —— 一验就撞出「文件缺失静默空白」这种没有信号的失败形态。
以后加控件属性时，**每个属性都要在示例里至少出现一次**，否则它就没有真机覆盖。

**「文档写了」不等于「平台都能用」**。`webview.md` 那句「带 scheme 的原样使用」把两个后端的能力压成一句，
而真正决定行为的是编译期二选一的后端：`url` 带 `https://` 在 QTextBrowser 上是**能力缺口**，不是配置问题。
跨平台控件的文档得按后端分列能力，否则用户在 mac/Linux 上照文档写就只有空白。

**可达边界要先探，再决定给什么证据**。Windows 目标探测（VM/ssh/wine/交叉工具链 + 磁盘余量）5 分钟就结论
「本机不可能有运行时证据」——此时正确交付物是静态审查 + 一条可复现的构建期缺陷，而不是含混地说「应该没问题」。

**「排除到组件」不等于「组件内部契约验过」**。被追问「`source` 是 QUrl，你用字符串测的吗」才暴露：
我之前只把外部变量（网络/QtNetwork/TLS/异步开关）排掉，没有直接看 `QUrl` 对象的解析结果，也没排除
「`setSource` 之后异步填充」这条 —— 结论虽然没变，但证据链有洞。补的方法是**把 PHP/AOT 从回路里摘掉**：
一个 100 KB 的独立 Qt 程序 + 6 秒真事件循环 + `otool -L` 三条就把问题钉死，成本远低于在 AOT 应用里加探针。
以后判「某控件某能力不存在」，先问自己：对象层面看过了吗、异步路径等过了吗、库级依赖链能不能支撑该能力。

**追问的价值不止于改结论，还在于暴露尺子坏了**。这次结论一条没翻（远程 url 确实不支持），
但复核过程揪出两条测量学缺陷：`--shot` 的 PNG sha256 跨次不稳定（光标闪烁 + hover 描边，6 次里 1 次不同），
以及 tpc 增量构建在源码回退时不重写生成物（探针字符串留在无引用的字面量池里）。
⇒ 立两条纪律：sha256 相等只用于**证明无变化**，不用于证明有回归；自证「还原干净」必须**删 `build/` 全清重建**
＋行为比对，不能靠 `strings 产物`。
**（24.5 勘误）** 这条里括注的成因「光标闪烁 + hover 描边」后来被推翻：真噪声是**应用自己的 1 秒心跳文案** +
**输入框有没有焦点**，而当时那版冻结写法根本不收敛。详见 F28 §3。

**给「是这一步改动让输出稳定了」这种归因配一个控制组**。24.5 里加了 `repaint()` 的那版连 19 个样本全同，
差点就被写成解法；但它在第一次 grab **之前**只多了两次 `focusWidget()` 读取，按代码路径不可能改像素，
于是去掉 `repaint()` 只留状态打印再跑一轮 —— 同样 15 样本全同 ⇒ 那是**环境漂移**，不是改动生效。
凡是「改完之后现象消失」的结论，先问：不改的这一版在同一时间窗里还出不出得来。

**换渲染后端时，spike 要连「我的验收尺子还量得到吗」一起测**。24.7 的 spike 如果只验「WKWebView 能不能显示」
就会得出「通过，开工」；顺手在同一时刻跑一次 `QWidget::grab()` 才发现抓出来是空洞 ——
而 `--shot` 的 sha256 基线正是上一轮刚锁的。功能可用性验收通过、验收手段本身却失效，这种组合
不在「先跑通功能再管测试」的直觉路径上，所以要在 spike 阶段就把两条判据并列写出来。
代价：一次改动会同时改基线哈希，得提前想好新的验收手段（这里是 `evaluateJavaScript` 读回），别等改完再补。

**「原生窗口句柄」在非原生平台上不是同一种东西**。`winId()` 在 cocoa 下是 NSView，在 offscreen 下是一个
非 ObjC 的指针 —— 类型系统不报错、编译器不警告，第一次真跑就 SIGSEGV（rc=139）。凡是拿 `WId` 去发消息的代码，
**必须显式按 `QGuiApplication::platformName()` 设闸门**；而且无头路径要一起进验收矩阵（这次是
`QT_QPA_PLATFORM=offscreen --shot` 跑出来的，只看 cocoa 就漏了）。

**改契约时把「替身」当第二个实现来审，不是当测试脚手架**。新增 `wkwebview` 后端后，C++ 侧
`qtWebViewSupportsJs()` 变成「两个原生后端都返回 true」，而 `FakeBridge` 仍写死 `=== 'webview2'` ——
它的注释恰好说明「报错误导会骗过应用侧能力判断」，自己却成了那个误导源。
以后扩枚举型契约（后端名、平台、能力位）时，同一次改动里 grep 出**所有**对该值做相等判断的地方，
包括测试替身与 stub 文档。

**别人写的注释归因，要当成待证假设而不是事实**。`// TEMP: 给 WebView2 异步初始化留时间` 这句话让
「120 帧」看起来只服务于 webview，于是自然的优化就是按后端收口 —— 一收口就炸出第三类噪声
（QLineEdit 清除按钮的淡入淡出）。省事的证伪办法不是读代码猜，而是**把那个数调小再看哈希分不分叉**：
一次改动、8 次运行就定位到 14×14 的格子。凡是「某个魔法数字/延时/重试是为了 X」的注释，
只要它决定了正确性，就该用「去掉它还成立吗」来验，而不是默认成立。

**止血方案要标明它是止血，并且别把它写进文档当配方**。24.9 我把「无条件泵 120 帧」连同
「泵到动画停（120 帧）」这条配方一起写进了中英文档、`qtphp new` 模板和技能 references ——
现象是不再分叉，但真正的不变量是「grab 时动画必须已停」，而不是「必须泵 120 帧」。
把副作用（慢 —— 本轮 3 帧实测 445 ms，120 帧配方早前记的是「3 秒出图」，两个数不在同一时间窗背靠背测的，
只作量级参考；另外它还依赖具体控件树里恰好没有更长动画）当成契约教出去，下次就得再改一遍四处文档。
⇒ 记录里区分三层：真不变量（动画停在终点）／当前实现（`snapshot()` 冻结）／使用者该怎么写（`runFrames(3)`）。

**对上游 API 的记忆要当成待编译的假设**。这轮按 Qt4/Qt5 的直觉写了
`QAbstractAnimation::NotRunning` 和 `anim->jumpToEnd()`，两条都是编译错 —— 而 24.9 结尾我甚至把
「先 spike 确认 `QT_UI_EFFECT_ANIMATE_UI_CHANGE` 是否存在」列成了未做项，却仍然凭记忆写了它不存在会怎样。
最省事的确认方式不是查文档，而是**让编译器回答**：一次 `#include` + 三个符号名的编译尝试 30 秒就出结论，
且顺带暴露了真正可用的通路（`findChildren<QAbstractAnimation *>()`）。

---

## Session 24 — 2026-10-03（增加 webview 支持）

### 任务
> 「增加 webview 支持」

### 先确认可行性（三条路，代价差别很大）
| 方案 | 需下载 | 能力 | 打包 |
|---|---|---|---|
| QTextBrowser | 无（QtWidgets 自带） | HTML 子集，**无 JS** | 不变 |
| QtWebEngine | ~300MB → 解压 1.5–2GB | 完整 Chromium | +几十 MB |
| **系统 WebView2** | 运行时本机已有（148.x），SDK 需自取 | 完整 Chromium（Edge 内核） | 仅 195KB loader |

**用户选择「QTextBrowser 和 WebView2 都做」** —— 轻量的跨平台默认 + Windows 上的完整浏览器。

### 实现
- **`cpp-src/qt_webview.cc`**（新，约 380 行）：一个 `webview` 控件，两种后端按 `QT_WEBVIEW2`
  编译期二选一；对外同一组属性（`url`/`html`/`zoom`）与事件（`loaded`/`title`/`navigating`/`link`）。
- **WebView2 SDK vendor 到 `third_party/webview2/`**：从 NuGet 取 `Microsoft.Web.WebView2`
  1.0.4258.31，只留编译需要的（`WebView2.h` 2.9MB + import lib 3.5KB + loader DLL 195KB），
  刻意**不 vendor** 那 11MB 的静态库。压缩后仅约 200KB，用户无需任何额外下载步骤。
- **PHP 侧**：`WidgetTree::webView()` / `WidgetTree::html()`；`QtApp::webViewBackend()` /
  `webViewSupportsJs()`（应用据此做降级提示，而不是白屏）；FakeBridge 同步。
- **`qtphp build` 自动部署 `WebView2Loader.dll`**，并加进 `run` 的依赖自检。

### 踩到的坑（都实测确认）
1. **`Q_OBJECT` 用不了** —— 本项目不走 moc。改用 `dynamic_cast` 替代 `qobject_cast`。
2. **`wil::unique_cotaskmem_string` 不可用** —— 没有 WIL 头。改用手写 `takeCoTaskMemString()`。
3. **`bool` vs `Bool`** —— `qtphp lint` 的正则只认 `Variant|void|Bool|String|Int|Array`，
   写 `bool php_qt_...` 会被判成「缺失实现」。改用 `Bool`。
4. **缺 `WebView2Loader.dll` → `0xC0000135`** —— 系统报错只说「找不到 DLL」不说哪个。
   加进部署与自检后能点名。
5. **webview 被压成 694×16**（最费时的一个）—— 两层原因叠加：
   - WebView2 画在自己的子窗口里，对 Qt 的 `sizeHint` 毫无贡献；占位标签删掉后内层布局为空、
     sizeHint 归零，外层布局就把它压扁 → 设 `Expanding` + `sizeHint()`
   - 窗口内容比窗口高时 Qt 会压缩可压缩项，`setMinimumSize(1,1)` 等于允许压到 0
     → 改为 `setMinimumHeight(120)`
6. **`--shot` 截不到 WebView2 画面** —— 它画在独立子窗口，`QWidget::grab()` 不含那块区域。
   这是后端限制（QTextBrowser 能截到），已写入文档。

### 验证
```
WebView2 后端    → 编译通过；真实运行 NavigationCompleted success=1；尺寸 694×123
                   （修复前 694×16，画面完全看不见）
QTextBrowser     → 编译通过；--shot 截图确认渲染出「WebView OK」+ 加粗/斜体/中文
                   标签正确显示 backend=textbrowser, js=不支持
两后端同一份 PHP → 关掉 /DQT_WEBVIEW2 重新编译，程序照常运行
脚手架            → qtphp new → build（自动带 WebView2 配置与 loader）→ selftest 通过
qtphp test       → 123 tests（+7：webview 工厂 4 个、后端查询 3 个）
qtphp lint       → 契约一致（26 个桥接函数）
示例 selftest    → 25/25，exit 0
docs             → 63 页（+2 中英 webview 页），链接与锚点全过
```

### 顺带修正的文档矛盾
`qt-setup.md` 刚写完「只用 Core/Gui/Widgets、没有别的 Qt 模块」。加了 webview 后要说明：
**webview 不需要 QtWebEngine** —— Windows 走 WebView2（非 Qt 模块），其余走 QtWidgets 自带的
QTextBrowser。中英两版都补了这个 tip，避免读者以为要装 1.5–2GB。

### 教训
**异步初始化的控件，尺寸问题会伪装成「渲染失败」。** 我一度以为 WebView2 没渲染出来，
查了几轮才发现导航是成功的、只是控件被压成 16px 高 —— **画面为零不是因为没画，而是因为没地方画**。
以后遇到「控件空白」，先量尺寸再怀疑渲染链路。

---

## Session 23 — 2026-10-02（文档补 Qt 安装与编译，跨系统）

### 任务
> 「文档补充 qt 相关库的编译和安装（跨系统）」

### 先摸清事实（不凭印象写）
```
桥接实际用到的 Qt 模块 → 对着 cpp-src/ 每个 #include 核：只用 Core / Gui / Widgets
                          （无 QML/Quick、Network、Sql）
findQt() 探测的路径      → 硬编码 6.9.3；Windows 三处 + brew keg-only 四处
各平台链接方式           → Windows .lib / macOS framework(-F 且编译期也要) / Linux -lQt6Xxx
qtphp new 生成的 yml     → Qt 路径以纯字符串写入，可手改
```
验证 `qmake -query QT_VERSION` → 6.9.3（文档里的命令是真跑过的）。

### 新增文档（中英各一页，共 +369 行）
`guide/qt-setup.md` / `zh/guide/qt-setup.md`，覆盖：

- **最小模块集**：只要 Core/Gui/Widgets，附各模块用途
- **版本要求**：Qt 6.0+，实测 6.9.3，自动探测只认 6.9.3 → 其他版本用 `QT_DIR`
- **Windows**：官方在线安装器（该勾/不该勾哪些组件）+ aqtinstall（可脚本化）+ 验证命令
- **macOS**：`brew install qtbase libiconv`；解释 keg-only 与 framework 布局，
  以及**为什么 `-I` 与 `-F` 必须同时给**（转发头用限定名）
- **Linux**：Debian/Ubuntu 一条命令；**Fedora / Arch / openSUSE 的包名与扁平路径**
  （Debian 多架构布局 vs 其他发行版的 `/usr/include/qt6`）
- **指定非默认 Qt**：`QT_DIR` 或直接改 yml
- **排错表**：8 条常见症状 → 原因 → 解法（含 LNK2038、`-F` 缺失、平台插件未部署）

同时从 `installation.md` 链过去，并加进中英 sidebar。

### 顺带修掉 2 个真问题

**1. `QT_DIR` 优先级错误（功能缺陷）**
写文档时验证发现：`findQt()` 把 `QT_DIR` **放在候选列表之后**，
于是「机器上恰好有一份候选路径里的 Qt」时，`QT_DIR` 被完全无视 ——
而那正是最需要覆盖它的场景（想用 6.10、或切到另一份 Qt）。
改成 `QT_DIR` 最优先，指向不存在目录时告警并回落。
实测：`QT_DIR=D:/temp/fakeqt2` 现在确实生效（改之前无效）。

**2. `check-anchors.py` 的 slugify 又错了（检查器撒谎）**
它把 `Linux（Debian/Ubuntu）` 算成 `linux-debianubuntu`（全角括号与斜杠直接删掉），
而 VuePress 的真实 id 是 `linux-debian-ubuntu`（转成分隔符）。
于是把**正确**的链接报成死锚点。修了两处：全角括号/斜杠转 `-`、去掉首尾多余 `-`。
**修完又做了坏锚点注入反证**，确认仍有鉴别力 —— 这是第二次栽在这个脚本上，
教训是：**检查器本身也要被检查**。

### 验证
```
qmake -query QT_VERSION      → 6.9.3（文档命令实跑）
QT_DIR 优先级                → 修复前被无视；修复后 D:/temp/fakeqt2 生效
                              + 不存在路径会告警并回落
findQt 候选 vs 文档描述       → 逐条一致
qtphp new                    → 生成的 yml Qt 路径正确，且会采纳 QT_DIR
docs 构建                    → 61 页（+2），链接与锚点全过（含坏锚点注入反证）
qtphp test / lint            → 116 tests · 契约一致
示例真实构建                  → Build successful（findQt 改动后复验）
README                       → 平台支持段补最小模块集 + 指路链接
```

### 教训
**写文档的过程本身就是一次审计。** 这次为了写「怎么指定别的 Qt 版本」去验证 `QT_DIR`，
才发现它根本不生效 —— 如果不实跑、照着代码「看起来是这么设计的」写，就会写出一条假文档。

---

## Session 22 — 2026-10-02（更新 README）

### 任务
> 「更新 README.md」

Session 21 改了 8 个新事件、托盘能力、`margin`/`spacing`、offscreen 插件，
README 里的事件表和托盘段已经过时，需要同步。

### 改动（+65 / −18 行）

| 位置 | 改了什么 |
|---|---|
| 特性列表 | 「无头测试」补上三个开关的完整说明（原来只提 `--shot`） |
| 平台支持 | Linux 那段的 `14/14`、`20/20` 是 Session 12 的实测数字，示例后来扩充过 —— 改为「三个开关全部 rc=0」并注明当时的用例数，避免读者误当现状 |
| 托盘段 | 从「左键点击」改写为四种手势 + `menu` 右键菜单（含可运行示例）+ 相对路径解析规则；新增「看不到托盘图标？」的排查提示（Windows 溢出区） |
| 事件表 | 10 个 → **18 个**（补 `press`/`release`/`commit`/`itemClick`/`cell`/`expand`/`collapse`/`close`，`toggle` 扩到 checkable button/group，`tray` 标注手势）；补 `on`/`onAny` 双触发说明 |
| 常用属性 | 补 `editable` 与 `closable`，说明 `editable` 在 table 上开启单元格编辑；`margin` 补四元组写法 |
| 命令表 | `build` 行补「部署 qwindows/qoffscreen/qminimal 三个平台插件 + 拷 assets/」 |
| 无头模式 | 新增 warning：offscreen 下 `--shot` 渲染不出中文（方框），另两个开关不受影响；补 Windows 平台插件已修好的说明 |
| 测试段 | 112 → 116 个测试；`--selftest` 14/14 → 25/25 |

### 核对（防止 README 又一次「撒谎」）
逐项对着代码验，不是凭印象改：
```
控件目录    README 30 个 vs WidgetTree 30 个方法 → 完全一致
常用属性    41 个逐个 grep 桥接实现 → 全部存在，无虚假声明
事件表      18 个 vs 桥接实际 enqueue → 全覆盖，无遗漏
命令表      7 个 vs CLI case 分支 → 一致
markdown    表格列数不齐 0 行；代码围栏 32 个（偶数，配平）
```

### 教训
README 是**最容易腐烂**的文件 —— 它不在构建链里，改代码时不会有人提醒你。
这次是因为刚做完审计（F25 的核心教训就是「文档说有、代码没有」），
所以改 README 时**先跑一遍交叉比对再动手**，而不是照着记忆改。

---

## Session 21 — 2026-10-02（审计「还有哪些组件漏了」）

### 任务
> 「检查是否还有其他组件漏了的 修复一下」

起因是 Session 20 发现托盘只转发了 Qt 5 种激活方式里的 1 种。怀疑同类
「能力被悄悄丢掉」的问题还有别处，于是做系统审计。

### 方法
把「桥接连了哪些 Qt 信号（19 个）」「实现了哪些属性键（52 个）」与
「文档声称支持什么」做**三方交叉比对**：
文档说有 / 代码没有 = 撒谎；代码有 / 文档没写 = 漏了。

### 查实 5 个缺陷（都修了）

| # | 缺陷 | 后果 |
|---|---|---|
| D1 | **`margin` / `spacing` 文档写了 4 处、有示例，代码里根本没实现** | 写了没效果，完全静默 |
| D2 | **`checkable` 按钮/group 不发 `toggle`** | `display.md` 教用户用它，实际拿不到状态 |
| D3 | **`build` 不部署 `qoffscreen.dll`** | offscreen 下三个无头开关全崩（`0xC0000409`），而文档正是让 CI 这么跑 |
| D4 | **`onAny` 被 `on` 静默吃掉** | 埋点/日志类代码静默失效；文档明写「两个都会触发」 |
| D5 | `editable` 不支持 table | 新加的 `cellChanged` 永不触发 |

**D3 最严重**：按文档配的 CI（`QT_QPA_PLATFORM=offscreen`）一跑就崩。
macOS 早有同类修复（F16→F17），Linux 也拷 `platforms/` 全量，
`package` 阶段有 `vendorWindowsOffscreenPlugin()` —— **唯独 Windows 的 `build` 分支漏了**，
历史记录里那句「Windows 同款已写（12.5，未实测）」从没验证过。

**D4 最阴险**：`handleEvent()` 命中特例后 `return`，通配分支走不到。
本次排查就踩到了（我加的 `onAny('timer')` 一条没收到，一度误判成「文件写入坏了」）。
更麻烦的是**单测 `testSpecificHandlerWinsOverWildcard` 断言的正是这个旧行为**，
与文档直接矛盾 —— 经确认采用文档语义，改代码 + 改测试。

### 新增信号（用户选「尽量补全」）
`press` / `release`、`commit`（失焦提交）、`itemClick`（含重复点同一行）、
`cell`（表格编辑）、`expand` / `collapse`、`close`（标签页关闭按钮），
`toggle` 扩展到 checkable button / group。全部沿用 `{type,id,value,payload}` 约定。
配套把 `editable` 扩展到 table（否则 `cellChanged` 永不触发）。

### 一个排查陷阱：tpc 增量缓存
一度以为 `spacing` 实现有 bug（一加属性就崩）。实际是 **tpc 复用了上次带崩溃代码的
编译产物**；改 `main.php` 强制重建就正常了。→ **改 C++ 后行为诡异，先 `rm -rf build`。**

### 已知限制（非缺陷，已写文档）
offscreen 下 `--shot` **渲染不出中文**（方框）：Qt offscreen 插件的字体枚举取不到中文字体。
`--selftest` / `--difftest` 不受影响。要检查中文渲染，`--shot` 别加 offscreen。

### 验证
```
margin/spacing        → 生效（截图对比：间距/边距明显变化；数组形式 [上,右,下,左] 也验了）
press/release         → 真实鼠标点击 → 事件到达 PHP（日志确认）
onAny 修复            → 修复前 0 条 timer 事件，修复后 8 秒收到 7 条（真实 AOT 二进制）
offscreen 三开关       → 修复前全崩；修复后 --selftest/--difftest/--shot 全 exit 0
无虚假事件            → 无交互跑 7 秒，新信号一条都不发（属性签名去重有效）
qtphp test            → 116 tests（+2：onAny 双触发、8 个新信号分发）
示例 --selftest        → 25 例（原 17）
docs                  → 59 页，链接与锚点全过
```

### 教训
**「文档说有、代码没有」是最值得优先修的一类缺陷** —— 它同时骗了用户和未来的自己。
这次 5 个缺陷里 3 个（D1/D2/D4）都是文档与实现不一致，靠三方比对才挖出来。
另外**测试也可能固化 bug**（D4 那个单测就是），所以「测试全绿」不等于「行为正确」。

---

## Session 20 — 2026-10-02（「Qt 不支持托盘右击吗？」）

### 用户的问题很准
> 「文档上只写了托盘左击事件，qt 不支持右击事件吗？」

**Qt 支持，是我们只转发了 5 种激活方式里的 1 种。** 实测拿到硬数据：

```
REASON=3  ← 左击 Trigger
REASON=1  ← 右击 Context      ← Qt 确实上报了，但桥接没往外传
REASON=2  ← 双击 DoubleClick
（中键 MiddleClick=4 未测到：这台机器的鼠标没有中键）
```

枚举：`Unknown=0, Context=1, DoubleClick=2, Trigger=3, MiddleClick=4`。

### 改动

**桥接（`qt_bridge.cc`）**
- `activated` 回调从「只认 Trigger」改为**全部转发**，手势放在 `$event['value']`：
  `left` / `right` / `double` / `middle`（沿用 link 用 value 报 href 的既有约定）。
- 新增**托盘右键菜单**：`setTray([... 'menu' => [...]])`。
  复用了菜单栏那套 `parseMenuItems` / `buildMenu`，菜单项点击走同样的 `menu` 事件。
- 语义细节：**绑了菜单后右击由 Qt 接管，不再发 `right` 事件**（实测确认：绑菜单后
  日志里只剩 left）。这是 Qt 自身行为，已在文档写明。
- `trayMenu_` 成员 + `cleanup()` 里释放（先删托盘再删菜单，避免悬垂的 contextMenu 指针）。

**测试 / 示例 / 脚手架**
- `tests/QtAppTest.php` +2 例：激活手势透传、托盘菜单项按 menu 事件分发。**114 tests**。
- 示例 `--selftest` 从 14 例增到 **17 例**（补 left / double / tray.hello / tray.show）。
- 示例与 `qtphp new` 模板都加了托盘菜单演示（`tray.hello` / `tray.quit`）。

**文档（中英双语）**
- `guide/events.md`：托盘小节从「左键点击」改写为四种手势的表格 + 绑菜单的语义说明。
- `guide/dialogs.md`：`setTray` 示例补 `icon` 与 `menu`；说明行同步更新。
- `reference/api.md`：`tray` 事件行的 `value` 列填上四种手势；`setTray` 注明支持的字段。

### 验证
```
真实鼠标点击（hello）→ left / right / double 三种手势都到达 PHP（日志确认）
绑菜单后            → 右击不再发 right 事件（Qt 接管），符合设计
右键菜单渲染         → 截图确认「显示主窗口 / --- / 退出」正常弹出
示例 --selftest     → 17/17，exit 0
qtphp new→build→selftest → 全通过
qtphp test / lint   → 114 tests · 契约一致
docs 构建           → 59 页，链接与锚点全过（含新增的 #tray / #托盘）
```

### 教训
用户「文档只写了 X」的提问，往往不是文档疏漏，而是**功能真的只有 X**。
这次先去读 Qt 的枚举定义、再用真实点击验证，才发现桥接把 4/5 的激活方式丢掉了 ——
**文档写得没错，是能力不完整**。这类问题值得当成缺陷修，而不是改文档描述。

（中途踩了个小坑：用 python 字符串写 C++ 的 `"\n"` 时被解释成真换行，编译报
「常量中有换行符」；改用 Edit 工具直接改就好。）

---

## Session 19 — 2026-10-02（「hello 示例里没看到托盘」）

### 先查事实，再下结论
用临时插桩（`QSystemTrayIcon` 的 `activated` 回调写日志）+ 真实鼠标点击，
拿到了硬证据：

```
TRAY-DIAG available=1 visible=1 iconNull=0 supportsMsg=1     ← 托盘建出来了、show 了、图标非空
ACTIVATED reason=3                                            ← 真实点击 → Trigger（枚举 Trigger=3）
UI 显示「心跳 12 跳 · 托盘点击 3 次」                          ← 点 3 次，计数 3
```

**结论：托盘机制完全正常。** 那用户为什么看不到？查注册表找到答案：

```
hello.exe    IsPromoted = 1     ← 曾被提升，常驻任务栏
newapp2.exe  IsPromoted = (空)  ← 在溢出区，要点 ^ 箭头才看得到
```

**Windows 默认把新出现的托盘图标收进溢出面板**，不是框架的问题。

### 但排查过程揪出 4 个真实缺陷（都已修）

1. **图标路径按 cwd 解析，而不是 exe 目录** —— 文档明确承诺「相对于可执行文件」，
   实现却没做。用户从仓库根启动时 `'assets/icon.png'` 必然失效。
   新增 `qtResolvePath()`（放在 `qt_common.h`，两处 .cc 共用）：绝对路径直用；
   相对路径先试 exe 目录、再试 cwd。顺带发现 `cleanPath()` 是**死代码**（定义后从未调用）。
2. **显式图标路径加载失败时静默变成空图标** —— 而空图标的托盘项在 Windows 上**直接不显示**。
   实测：把路径改成不存在的文件，蓝色图标从任务栏消失（对比截图确认）。
   改为逐级兜底：显式路径 → 窗口图标 → 系统标准图标，并在加载失败时打警告。
3. **`build` 阶段不拷 `assets/`** —— `copyDir` 只在 `package` 里调用。
   于是开发期相对路径必失效、打包后才正常，属于「打包才暴露」的坑。
   新增 `deployAssets()`，并接到 **macOS/Linux 分支**（原先那两支连运行时都不部署，
   assets 自然也没人管）。
4. **示例与脚手架都没有图标资源** —— 示例连 `assets/` 目录都没有；
   `qtphp new` 只建空目录。已给示例加一个 64×64 蓝色「T」图标（入 git，
   `.gitignore` 里本来就有 `!examples/**/assets/*.png` 的例外规则），
   并让脚手架**内嵌**同一份图标 + 一个托盘演示（含 `onAny('tray')` 计数）。

### 文档
中英双语的托盘小节都补了 `::: warning「我调了 setTray 但看不到图标」`：
Windows 溢出区行为 + 查 `NotifyIconSettings` 注册表的命令 + 另两个静默原因。
（写这段时踩了个小坑：PowerShell 路径里的 `\N` 在 Python 普通字符串里是转义，
改用 raw string。）

### 验证
```
托盘功能（真实鼠标点击）→ hello：3 次点击 → 3 次 Trigger → UI 计数 3
                        newapp2：注册表条目 + 图标快照齐全
坏图标路径反证            → 修复前图标消失；修复后回落到系统标准图标（对比截图）
qtphp build              → 自动拷 assets（Windows 与 macOS/Linux 两支都改了）
示例 --selftest          → 14/14，exit 0
脚手架 new→build→selftest → 全通过，图标与托盘演示都在
qtphp test / lint        → 112 tests · 契约一致
docs 构建                → 59 页，链接与锚点全过
```

### 教训
**「看不到」不等于「没工作」。** 这次如果直接去改托盘代码，就会在正确的实现上乱动。
先用插桩 + 真实点击把「机制是否工作」和「用户看到什么」分开验证，
才发现真正的问题是 ① Windows 的溢出区默认行为 ② 图标路径解析这一串静默失败。
**静默失败最贵**：路径错了不报错、图标空了不报错、assets 没拷也不报错 ——
三处叠加起来，表现就是「托盘不见了」。

---

## Session 18 — 2026-10-02（修 CI：node 版本 + lock 不同步）

### 任务
> 「ci 报错」（贴了 `npm ci` 的完整日志）

日志里其实是**两个互不相干的问题**，都出在 docs 的 CI 上：

### 问题 1：CI 的 node 太旧
`vuepress@2.0.0-rc.31` 的 `engines` 要求 **`node >=22.18.0`**，而 workflow 写的是 `node-version: 20`。
所以 `npm ci` 一上来就刷了一屏 `EBADENGINE`（涉及 `vuepress`、`@vueuse/*`、`@mdit/*` 等）。
本地是 node 24，所以从没复现过。

**修**：workflow 改 `node-version: 22`（22.23.3 满足要求）。

### 问题 2：lock 文件不同步
```
npm error `npm ci` can only install packages when your package.json and package-lock.json are in sync
npm error Missing: sass@1.105.1 from lock file
npm error Missing: markdown-it@15.0.2 from lock file   (×2)
```

**根因**：`sass` 是 `@vuepress/theme-default` 的**可选 peer 依赖**（`peerDependenciesMeta.sass.optional = true`），
而我的 `package.json` **从未显式声明它**。这种"可选 peer"的落位依赖 npm 的解析策略 ——
**npm 10 与 npm 11 会把它记进 lock 的不同位置**，于是本地（npm 11 + node 24）生成的 lock
到了 CI（npm 10 + node 20）就"缺包"。

**修**：
- 在 `devDependencies` 里**显式声明 `sass`**，消除歧义。
- 用 **CI 同款 npm 10** 重新生成 lock（`npx --yes npm@10 install`）。

### 顺带加的护栏
`package.json` 里补 `engines: { node: ">=22.18.0" }`，把 node 要求变成**显式契约**：
版本不符时 npm 会直接指出是我们自己的包不满足要求，而不是淹没在第三方包的告警里。
（实测：npm 确实认这个字段 —— 约束不可满足时告警，配 `--engine-strict` 则硬失败。）

**踩坑**：`"//comment"` 形式的注释键**只能放顶层**，放进 `devDependencies` 里会被 npm 当成包名
报 `EINVALIDPACKAGENAME`。已移到顶层。

### 验证（关键：两个 npm 版本都过）
```
npm 10（CI 同款）npm ci   → rc=0，无 EBADENGINE
npm 11（本地）   npm ci   → rc=0，无 EBADENGINE
lock 内容                → sass@1.105.1 ✓、两处 markdown-it@15.0.2 ✓
engines 护栏             → 不可满足约束时 npm 正确告警 / --engine-strict 硬失败
完整 CI 流程复现          → npm ci → 构建 59 页 → check-links → check-anchors 全过
两种 base（'' 与 /typephp-qt/）→ 链接检查均无死链
```

### 教训
**`npm install` 会掩盖 lock 不同步，`npm ci` 才会暴露。** 本地与 CI 的 npm 版本不同时，
这个差别会变成"本地好好的、CI 挂掉"。改依赖后应该用 CI 同款版本复核：

```bash
npx --yes npm@10 ci
```

已写进 `docs/README.md`。

---

## Session 17 — 2026-10-02（搜索插件 + 中英双语）

### 任务
> 「加搜索插件与多语言版本」

用户决策：**英文为默认（根路径）、中文 /zh/**；**30 页全部双语**。

### 搜索
接入官方 `@vuepress/plugin-search@2.0.0-rc.137`（版本与 theme-default 同线）。
索引**内联进客户端 bundle**，没有独立文件 —— 实测在 JS 产物里能搜到 `WidgetTree`、`闭包实参`、`qtphp doctor`。
按语言分别配占位提示（英文 `Search docs` / 中文 `搜索文档`）。

### 多语言
```
src/
├── README.md + guide/ widgets/ advanced/ reference/ faq.md   # 英文 29 页（默认）
└── zh/  同结构                                                # 中文 29 页
```
- `config.ts` 用 `locales` 配置两套 navbar/sidebar/editLink/lastUpdated 文案
- 中文页的 52 处站内链接批量改写为 `/zh/...` 前缀（否则会跳回英文站）
- 构建 **59 页**（29 英 + 29 中 + 404），2.8 秒，无警告
- 验证中英**文件名一一对应**（`diff` 通过）、语言切换器双向可用、中文导航显示中文

### 顺带发现：官方 links-check 抓不到锚点

排查时发现 theme-default **内置了 links-check 插件**（可通过 `themePlugins.linksCheck` 配置），
我先前单独 `plugins: [linksCheckPlugin(...)]` 是**重复注册**（构建报 `used multiple times`）。改为用主题配置项，设 `build: 'error'`。

但实测：**它只验证 markdown 链接的目标文件存在，不校验锚点**（注入坏锚点后构建照常成功）。
所以自建的 `check-anchors.py` 仍有独立价值，保留；`check-links.py` 改为"产物级复核"（官方是源码级）。

三层防护各管一段：

| 机制 | 层次 | 失败方式 |
|---|---|---|
| `themePlugins.linksCheck: build 'error'` | markdown 源码 | 构建失败 |
| `check-links.py` | 产物 HTML | rc=1 |
| `check-anchors.py` | 源码 `#锚点` | rc=1 |

### 踩到的坑

1. **VuePress 与官方插件是两套独立版本号**（vuepress 到 rc.31，插件已到 rc.137），
   必须查 `npm view <pkg>@<ver> peerDependencies` 找配对，不能写同一个号。
2. **重复注册 links-check** —— theme 已内置，自己再加会告警且只有最后一个生效。
3. **heredoc 写长文件被截断两次**（`aot-notes.md`、`api.md`）—— 是命令长度限制，
   不是语法问题。改为分段 `cat >>` 追加。截断处都做了行数核对才发现。
4. **Write 工具的 staleness 检查**对"文件被 mv 走了"的状态会误判，
   需先用 shell 建占位文件再写。

### 验证
```
npm run build           → 59 页，2.8s，无警告
check-links.py          → 无死链（base='' 与 base='/typephp-qt' 双模式，59 页）
check-anchors.py        → 全部锚点有效（10 个目标文件，中英两套）
                         + 中英两侧各做过坏锚点注入反证（rc=1）
dev server              → / /zh/ /guide/ /zh/guide/ /faq.html /zh/faq.html 全部 200
事实核对                → 英文 api.md 的 40 个 QtApp 方法 + 30 个 WidgetTree 方法
                          逐个对照源码，全部存在
中英结构                → 文件名 diff 完全一致
qtphp test/lint         → 112 tests · 契约一致（项目未受影响）
```

### 待你手动做
仓库 Settings → Pages → Source 选 **GitHub Actions**（首次部署前）。

### 未做
未提交。搜索插件用的是前缀匹配，中文命中率可用但不如分词型全文检索（如 algolia / meilisearch）。

---

## Session 16 — 2026-10-02（用 VuePress 加文档站）

### 任务
> 「用 vuepress 给项目添加文档」

用户决策：**VuePress 2**（rc.31）+ **GitHub Pages 自动部署** + 「整体项目分析后添加内容」。

### 先做项目分析
通读了源码全貌，作为文档素材的准确性依据：QtApp 40 个公开方法、WidgetTree 30 个控件、
24 个桥接函数、10 个事件类型、6 个 `patch` call 方法、7 个 CLI 子命令、
属性白名单、示例的 3 个无头开关、跨平台能力。

### 交付

```
docs/
├── package.json / package-lock.json
├── README.md                           # 怎么写文档
├── check-links.py / check-anchors.py   # 构建期检查（CI 里跑）
└── src/
    ├── .vuepress/config.ts             # 导航 / 侧边栏 / base
    ├── README.md                       # 首页
    ├── guide/      6 页                # 安装·快速上手·项目结构·架构·状态与视图
    │               + 6 页              # 事件·属性·布局·对话框·多窗口托盘定时器·增量补丁
    ├── widgets/    5 页                # 目录总览 + 容器·输入·数据·展示
    ├── advanced/   5 页                # 总览 + AOT 注意事项·手写桥接·diff 引擎·无头验收
    ├── reference/  5 页                # 总览 + CLI·API·打包·平台支持
    └── faq.md                          # 14 个常见问题
```

**共 30 页**，`npm run build` 2.2 秒完成，无警告。

### 踩到的坑

1. **VuePress 与官方主题是两套独立版本号。** `vuepress` 最高到 `2.0.0-rc.31`，
   而 `@vuepress/theme-default` 已到 `2.0.0-rc.137`。我一开始给两者写了同一个版本号，
   `npm install` 直接 ERESOLVE 失败（theme 的 peer 要求 `vuepress@2.0.0-rc.12`，
   是它自己没更新的旧值）。查 `npm view <pkg>@<ver> peerDependencies` 才找到配对：
   **theme-default rc.137 ⇄ vuepress rc.31**。
2. **孤儿 sidebar 警告。** `/faq.html` 没进 sidebar 配置，构建警告 `is missing sidebar config`。
   补上 `'/faq.html'` 条目。
3. **两处死锚点。** 标题 `` ### 为什么一定要给 `row_ids` `` 的产物 id 是
   `为什么一定要给-row-ids`（下划线转连字符），而我链接里写了 `-row_ids`。
   写检查脚本才发现 —— 见下。
4. **Git Bash 的 MSYS 路径改写** 把 `DOCS_BASE=/typephp-qt/` 改写成
   `C:/Program Files/Git/typephp-qt/`，让我一度以为 base 没生效。
   用 `.bat` 在 cmd 下验证才排除干扰。
5. **cmd 里 `set "VAR=v" && node x` 变量传不下去**（`&&` 前作用域问题）——
   正是技能里记过的坑，改用批处理文件。

### 两个检查脚本（构建期守门）

VuePress **不会**因为死链或死锚点而构建失败 —— 它照常出 30 页，问题只在用户点击时暴露。
所以写了两个脚本并接进 CI：

- `check-links.py` —— 站内链接死链。**自动探测 base 前缀**（本地 `/`、Pages `/typephp-qt/`），
  两种产物都能直接查。
- `check-anchors.py` —— 跨页锚点是否指向真实标题。按 VuePress 的 slug 规则转换：
  小写 → 去行内代码 → 去粗斜体 → 空格转 `-` → 去 ASCII 标点（**保留 `-`**）→
  去全角标点 → 下划线转 `-`。

**两个脚本都做了鉴别力反证**：注入一个坏链接/坏锚点，确认报错且 rc=1，再还原。
（这是从 Session 14 那个「脚本误报」教训里来的 —— 一个会撒谎的检查器比没有更糟。
本次 `check-anchors.py` 的 slug 规则第一版就写错了：ASCII 标点类里把 `-` 也删了，
导致它把**正确**的锚点报成死链。修完才敢信。）

### 部署
`.github/workflows/docs.yml`：推 `main`（`docs/**` 变更）→ `npm ci` → 构建 →
跑两个检查 → 上传 → 发布到 Pages。base 用 `github.event.repository.name` 推导，
仓库改名后不会静默 404。

**需要你手动做一步**：仓库 Settings → Pages → Source 选 **GitHub Actions**（首次部署前）。

### 验证
```
npm run build           → 30 页，2.2s，无警告
check-links.py          → 无死链（base='' 与 base='/typephp-qt' 两种模式都过）
check-anchors.py        → 12 处跨页锚点全部有效
npm run dev             → :8080 起服务，index 与 /guide/ 均 200
产物正文抽查            → 导航/侧边栏/正文/代码块/中文渲染正确
事实核对                → 112 测试 · 24 桥接函数 · 30 控件 · 40 个 QtApp 方法
                          · 30 个 WidgetTree 方法 · 6 个 patch call —— 文档与代码逐项一致
```

### 未做
- 未提交（文件已就位，等你确认）。
- 未加搜索（VuePress 2 的搜索插件需额外依赖，可后加）。
- 未做文档的多语言版本。

---

## Session 15 — 2026-10-02（更新 typephp-qt-app 技能）

### 任务
> 「更新 typephp-qt-app 技能」

### 起点
技能（1285 行，位于 session 技能目录，非 git 跟踪）教的是**手写 C++ 桥接**路线，源自
TypePHP 编译器仓库的 `examples/qt-taskboard`。它**完全不知道**我们已把同样的东西产品化成了
`yangweijie/typephp-qt` 包 —— 全文零处提及 `qtphp` / `WidgetTree` / `QtApp` / FakeBridge，
也缺了本项目验证出的一批 AOT 硬坑（`require_once`、闭包 arity、无头阻塞、tpc 供给路线）。

### 用户决策
- 方向：**全面改写为包优先**（包为主线，手写降为进阶路线）
- 坑的归处：**SKILL.md 硬规则 + reference 详解**

### 改动

| 文件 | 变化 |
|---|---|
| `SKILL.md` | 161 → 258 行。重写为「路线 0 用包 / 路线 1 手写」双轨，包为默认；新增 14 条硬规则（1–6 为 AOT 专属、7–14 通用）；description 加入 `qtphp`/`WidgetTree` 触发词 |
| `references/aot-pitfalls.md` | **新建**（193 行）。8 个 AOT 坑（症状/成因/修法）+ 两条 tpc 供给路线 + 调试纪律 |
| `references/bridge-pattern.md` | 247 → 274 行。顶部加「包路线不用读」指路；第 8 节截图模式改用 argv 并补 `--selftest`；**新增第 9 节**声明式 diff；checklist 补 3 条 |
| `references/build-and-deploy.md` | 271 → 340 行。顶部加 `qtphp` 一键链；版本 0.9.3→0.9.4；macOS 补静态链接与 offscreen 插件；Linux 补 ldd 闭包/`patchelf --force-rpath`/per-plugin `ldd`；截图段重写为 `--shot`+`--selftest` 双开关 |
| `evals/evals.json` | 4 → 6 个用例，补「闭包 arity 崩溃」与「PHPX runtime not found」两个真实报障场景 |
| `scripts/scaffold.sh` | 默认 `PHP_HOME_DIR` 版本号 0.9.3→0.9.4（4 处） |

### 验证
- `evals.json` JSON 合法（6 用例）
- SKILL.md 引用的 7 个 reference、11 个 template、2 个 script **全部存在**
- **逐项对照真实代码**（防文档写错误导）：QtApp 22 个公开方法、WidgetTree 30 个控件、
  10 个事件类型、6 个 `patch` call 方法、7 个 CLI 子命令、3 个无头开关、包名 —— 全部一致
- `scaffold.sh` 实跑：生成 11 个文件，`lint: all .bat files clean`，版本号正确注入

### 说明
技能位于 session 目录，不在 git 仓库内，故本项目的 `git status` 不含这些改动。

### 补充：技能同时入库到仓库（`.ohmyagent/skills/typephp-qt-app/`）
用户要求仓库里也放一份。放置位置选 `.ohmyagent/skills/`（本工具的原生项目技能目录）。
复制 25 个文件，并修掉入库才会暴露的三个问题：

1. **shell 脚本是 CRLF** —— 源目录在 Windows 上创建，两个 `.sh` 全是 `\r\n`。
   在 Linux/macOS 上会报 `bad interpreter: /usr/bin/env bash^M`。已转 LF（两份同步）。
   注意 `grep -c $'\r'` 在 Git Bash 下会误报行数，验字节要用 `od` 或 python 数 `\r`。
2. **git 记录为 `100644`** —— 文件系统上是可执行（`-rwxr-xr-x`），但 Windows 下
   `git add` 不保留执行位。用 `git update-index --chmod=+x` 改成 `100755`。
3. **没有 `.gitattributes`** —— 仓库此前无此文件、`autocrlf=false`，意味着换行符原样入库。
   新增之，把规则固化：`*.sh` → LF、`*.bat`/`*.cmd` → CRLF、`*.png`/`*.ico`/`*.b64` → binary。
   **踩坑**：gitattributes 是**后面的规则优先**，通配兜底 `* text=auto eol=lf` 必须写在最前，
   否则会覆盖掉 `*.bat` 的 crlf 设置（首次写反了，`git check-attr` 验出来的）。

验证：从 git 索引 `git archive` 导出后逐字节比对（含二进制模板哈希）、
脚本仍为 `100755` 且纯 LF、导出副本实跑 `scaffold.sh` + `lint-bats.sh` 均 rc=0。

**未做**：未提交（`git add` 已暂存，等你确认）。中途 `git add --renormalize .` 顺带改了
`examples/hello/.gitignore` 的行尾，已还原——不属于本次范围。

---

## 当前状态（Session 25：Phase 1–12 + 13–24 完成，最新为 macOS webview 本机复验）

> **本表是 Session 14 时点的快照**，只有下面加粗/更新的行是 Session 25（2026-10-03）在 macOS 上重测的。
> 标着 `14/14`、`112/183`、`24 个函数` 的 Windows 与 Linux 行**都是 Phase 20/21/24 之前的时点**：
> 断言集此后从 14 涨到 25（托盘手势 + 补全信号）、单测 112 → 123、桥接函数 24 → 26，
> 那两个平台**没有在新代码上复验过**，别把旧数字当现状。

| 项 | 状态 |
|---|---|
| 全部 9 个 Phase（Windows 路线） | ✅ done |
| **macOS 端到端（Session 25 复验，含 webview）** | ✅ `build`（11 TU，Mach-O arm64 25.5 MB）→ `--selftest` **25/25** → `--difftest` **20/20** → cocoa `--shot` 760×720 PNG（读图确认 webview 分组渲染）→ `test` **123/201** → `lint` **26 个函数**契约一致，全部真 rc=0 |
| **`--shot` 可复现（24.5，F28 §3）** | ✅ 修好两层噪声（应用 1 秒心跳文案 + 输入框焦点瞬态），终验全清重建：cocoa 8/8 → `a70cd7ae05b6…`、offscreen 8/8 → `4b777e2ed74e…`，`--selftest` 25/25、`--difftest` 20/20、`phpunit` 123/123 同时全绿 ⇒ **sha256 可按平台当 CI 基线** |
| **`webview` 控件（Phase 24，mac 侧）** | ✅ mac 入口 `project.macos.yml` **零改动**即落到 QTextBrowser 后端（`sources` 继承 + `cxx-flags` 整体替换）；`html` 与 `url`（本地文件，相对 exe 目录）两条分支都真机出图，相对 `<img>` 解析正确。补验三条全部收口（F27）：`link` 接线 ✅（含鉴别力反证）、`zoom` ✅ 确认静默忽略（PNG sha256 相同）、**远程 `url` ❌ Qt 6 `QTextBrowser` 不支持**（追问后重做到对象级：`QUrl` 解析正常、警告同步出现、6 秒真事件循环恒 0 字符、同进程 QNAM 可 200/577、`otool -L QtWidgets` 不含 QtNetwork；scheme 矩阵另查出 `data:` 也不支持，**只有 `file` 可用**），并据此把 `docs/src/zh/widgets/webview.md:44` 的过度承诺范围确定下来 |
| **WebView2 后端（`QT_WEBVIEW2` 分支）** | ⚠️ **本机零运行时证据**（无 Windows 目标：VM/ssh/wine 皆无、Apple Container 只跑 Linux、启动卷 3.5 Gi 使交叉工具链不可行）。只交静态审查 W1–W5（F27 第 4 节）。附带抓出并修好 **W0**：`.gitignore` 的 `*.lib` 吞掉 vendor 的 `WebView2Loader.dll.lib` ⇒ 新克隆 Windows 链接必报 `LNK1181`；规则已加 `!third_party/**/*.lib` 并验证，**那 3.5 KB 的 `.lib` 需从 Windows 机器补交** |
| **WKWebView 第三后端（`.mm`，用户选定路线）** | 🟡 **第 ① 步 spike ✅**（24.7 / F29 §5）：合成、缩放跟随、`evaluateJavaScript` 三条实测通过；同时量出**代价** —— 原生子 view 不进 `QWidget::grab()`，换默认后端后 mac `--shot` 帧里 webview 区域变空洞、`a70cd7ae05b6…` 必改。第 ②–④ 步（后端实现 / `bin/qtphp` 认 `.mm` / 优先级与文档）**未开工** |
| Windows 端到端（Session 14 复验） | ✅ `build` → `--selftest` 14/14 → `--shot` 21KB PNG（读图确认）→ `test` 112/183 → `lint` 契约一致；`doctor` 6 项全 OK |
| tpc 供给路线解析（Session 14） | ✅ 改按运行时体检选路，不再硬编码路径（F24）；带运行时的原生包不再被 composer 驱动抢占 |
| Phase 10（macOS 原生编译路线） | ✅ done：`qtphp build examples/hello` 在 mac 上产出真实 Mach-O arm64 可执行文件，`--selftest` 10/10、`--shot` 出图 |
| Phase 11（macOS 运行/打包/脚手架） | ✅ done：11.1–11.6 全绿，见下三行 |
| `qtphp test` | ✅ 112 tests / 183 assertions（macOS 与 Windows 均已实测） |
| `qtphp lint` | ✅ 契约一致（24 个函数） |
| 示例 `build` / `--shot` / `--selftest` / `package` | ✅ Windows 全部实测通过 |
| 示例在 macOS 无头运行（FakeBridge） | ✅ 曾 `--selftest` 10/10、`--shot` 渲染出完整控件树（该 10 条为 12.3 之前的时点） |
| 示例在 macOS **真实 AOT 二进制 + Qt 6.11.2** | ✅ `QT_QPA_PLATFORM=offscreen`：`--selftest` **14/14** 干净退出；`--shot` 760×560 PNG，状态栏与新增的「实时」分组已在图上（读图确认；菜单/分组/进度条的核对在 Phase 10–11 完成） |
| 多窗口 / 托盘 / 定时器（12.3） | ✅ 示例演示双窗口按帧轮流泵 + `setTimer` 心跳 + 托盘点击写日志表；`QtApp::isOpen()` 与托盘兜底图标两处缺口已补；离屏 3 秒存活实测 |
| 示例 `--difftest`（真实二进制，表格/树 diff + `patch` 的 `call`） | ✅ 12.1 新增 12 条、12.2 再加 8 条 = **20/20**；12.1 首轮曾 7/12 失败暴露 4 个 C++ 真缺陷（F13），12.2 用「临时禁用签名作废」反证断言有鉴别力（F14） |
| `qtphp new` 模板 | ✅ 生成即可编译 + 自检通过；11.6 起同时给出 `project.macos.yml` + `Info.macos.plist`，全新项目在 mac 上 build→run→package 全链实测通过 |
| `qtphp run` / `package`（macOS） | ✅ 11.1/11.2/11.5 完成：run 找无扩展名产物 + `otool -L` 自检 + 参数透传；package 产出 `dist/Hello.app`，12.4 起 bundle 自带 offscreen 插件 ⇒ `env -i` + offscreen 下 `--selftest` 14/14、`--difftest` 20/20、cocoa 下 `--shot` 出图，均真 rc=0 |
| bundle 的无头边界 | ✅ 已修（12.4）：`package` 在 macdeployqt 后补拷 `libqoffscreen.dylib` 并改写 Qt 引用 ⇒ 产物 `env -i QT_QPA_PLATFORM=offscreen` 可跑（F16→F17）。Windows 同款已写（12.5，**未实测**） |
| `qtphp doctor`（macOS） | ✅ 6 项全 OK（含 11.3 新增的「PHP 运行时库」与按平台分派的 C++ 工具链检查） |
| **Linux（Debian 12 arm64 + Qt 6.4.2）build→run→验收** | ✅ 12.7 真实测：tpc 自建 embed 运行时、产出 ELF PIE aarch64 58 MB，offscreen 下 `--selftest` 14/14、`--difftest` 20/20、`--shot` 760×560 PNG，全部 rc=0（F21） |
| `qtphp test` / `lint` / `new` / `doctor`（Linux） | ✅ 112 tests / 183 assertions、契约一致、`project.linux.yml` 三元组推导正确、doctor 7 项全 OK（12.9 起含「Linux 构建前置」探测） |
| `qtphp package`（Linux） | ✅ 12.8 真实测：`dist/hello/` = 二进制 + 85 个 `.so` + 10 个 Qt 插件 + `qt.conf`，140.3 MB；`readelf -d` 只剩 `(RPATH) [$ORIGIN/lib]`，`ldd`（含 `libqxcb.so` 自己那次）没有一行落在产物外；`env -i QT_QPA_PLATFORM=offscreen` 下 `--selftest` 14/14、`--difftest` 20/20、`--shot` 全 rc=0，且 PNG 与开发产物**逐字节一致**（F22） |
| 代码规模 | **6039 行**（C++ 2676 含 `qt_webview.cc` / PHP 框架 1109 / CLI 2097 / 契约 157）；Session 14 时点为 5221 |

**Phase 11 收尾复验（2026-10-02，无代码改动）**
`php bin/qtphp test` → OK (103 tests / 162 assertions)；`lint` → 契约一致（24 个函数）；
`doctor` → 6 项全 OK，其中「PHP 运行时库」指向 `~/.typephp/php-builder/php-8.5.11-5852a1ce211cc711/install/lib`。
task_plan.md 的 macOS 环境段同步：私有 embed 运行时从「缺」改为「已建成并缓存」，标题改为「Phase 10–11 交付环境」。

**下一步（可选，未开始）**
- **WKWebView 第三后端 #9 的第 ②–④ 步**（用户已定路线，① spike 已收口见 24.7）：
  ② 写 `cpp-src/qt_webview_wk.mm`，把 `url`/`html`/`zoom`/`link` 对齐现有两后端契约
  （QTextBrowser 的 `zoom` 是静默忽略，WKWebView 有真 `pageZoom` ⇒ 这条能力首次三后端不对等，文档要按后端分列）；
  ③ 构建接线：`bin/qtphp:1995` 的 `glob('cpp-src/*.cc')` 与脚手架清单 `:448-450` 要认 `.mm`，
  mac 入口 `link-libs` 加 `-framework WebKit`、`cxx-flags` 加 `-fobjc-arc`；
  ④ 后端优先级 `webview2 > wkwebview > textbrowser` + `webViewBackend()` 返回值 + 测试，
  **并且**按 24.7 量出的代价把 webview 的验收从 `--shot` 像素换成 `evaluateJavaScript` 读回
  （换默认后端会让 mac 基线 `a70cd7ae05b6…` 改变，得同时更新基线与文档）
- ~~**让 `--shot` 可复现**（F28 第 1 节）~~ ✅ **已做（24.5）**：但当时记的成因是错的 —— 真噪声两层
  （应用 1 秒心跳文案 + 输入框焦点有无），不是 caret/hover；sha256 现在可按平台当 CI 基线
  （cocoa `a70cd7ae05b6…` 8/8、offscreen `4b777e2ed74e…` 8/8）
- **`--shot` 基线的两个前提别忘**（24.5 的边界）：① 被测应用**不能在截图路径上注册墙钟定时器**，
  否则文案跳字照样翻哈希（框架管不了应用自己的状态）；② 基线**按平台各存一份**，
  cocoa 与 offscreen 的产物本来就不同（`a70cd7ae` vs `4b777e2e`），不能互相比
- **`strings 产物` 不能自证干净**（F28 第 2 节）：tpc 增量构建在源码回退到「曾构建过的状态」时不重写
  `build/extension-hello.cc`，字面量池只增不减 ⇒ 验「还原是否彻底」要**删 `build/` 全清重建**再做行为比对。
  上游改进点（生成文件不重写 / 池不收缩）属 `typephp-compiler` 侧，**未验**、未提 issue
- **Windows：补交 `third_party/webview2/x64/WebView2Loader.dll.lib`**（3.5 KB，`.gitignore` 规则本轮已放行、
  本机磁盘上没有）。不补上，新克隆在 Windows 上 `build` 必报 `LNK1181`
- **Windows：`QT_WEBVIEW2` 分支的运行时验证 + W1–W5 修复**（F27 第 4 节）。其中 W3（不调 `Controller::Close()`）
  与 W4（COM 回调裸捕 `this`）是同一根因 —— webview 控件的析构路径没被设计，需真机确认是否攒浏览器子进程
- **修文档**：`docs/src/zh/widgets/webview.md:44`（及英文版）那句「带 scheme 的（`http(s)://`、`file://`、`data:`）
  原样使用」要按后端分列 —— 实测 QTextBrowser **只认本地文件**（`file:`/裸路径），`http://`、`https://`、
  `data:`（含 base64 变体）三种都是「空白 + 一句 Qt 警告、rc 仍 0」，而 WebView2 三种都吃
- **要不要给 QTextBrowser 后端补网络/`data:` 能力**（产品决策，未动）：自己用 `QNetworkAccessManager` 取字节
  再喂 `setHtml`（`data:` 可直接解码），代价是 mac/Linux 引入 QtNetwork 依赖
- **QTextBrowser 后端在本地文件不存在时零诊断**（空白、无警告、rc 仍 0），而 WebView2 会发
  `loaded(success=false)` ⇒ 要么 `qWarning`，要么补发同一事件，让两后端可诊断性对齐
- **`--linkprobe` 式的信号接线验法要不要长期保留**：本轮为临时探针（已 `git checkout` 还原）。
  selftest 走 `dispatch()` 绕开 Qt 信号，意味着「桥接 → PHP」这条主干**至今没有自动化回归**
- **`--shot` 的「3 帧 + `snapshot()` 冻结」配方只在 mac 上收敛过**：Windows/Linux 侧要各自重跑 ×8 建自己的基线值
  （现有三条基线都是 macOS 的）。动画冻结是跨平台的 Qt 层改法，理论上等效，但**未实测**
- ~~**打包缺陷 P1**~~ ✅ **已修（28.1）**：`dirSize()` 跳过符号链接，`package` 现在打印 83.6 MB（F31 §1）
- ~~**打包缺陷 P2**~~ ✅ **已修（28.1）**：`qtResolvePath()` 变成 exe 目录 → bundle `Contents/Resources` → cwd
  （第二层只在父目录名为 `Contents` 时启用），打包产物托盘图标恢复；负控制（改名 `Resources/assets`）能按需复现警告（F31 §3）
- **打包验收缺一条自动化判据**：资源缺失只体现在 stderr 的 `qWarning`，而 `--shot` 哈希覆盖不到（托盘不进 `grab()`）
  ⇒ 候选做法：`package` 自检里跑一次产物并断言 stderr 无 `could not be loaded`
- **Windows / Linux 在新代码上复验**：断言集从 14 涨到 25（Phase 20/21）、单测 112 → 123、桥接函数
  24 → 26、示例加了 `webview` 分组（窗口高 560 → 720）。两侧都还停在 Session 14/13 的时点，
  Linux 侧容器 `tgl` 留着，重跑要先按 F20 起宿主代理并核对网关 IP
- Linux 产物的**跨发行版**验证：现在只有 Debian 12 → Debian 12，「glibc 家族留系统 + 其余全搬」在
  Ubuntu 24.04 / Fedora 上成不成还没测；`xcb` 插件也只在**没有 X server** 的容器里验过依赖闭包，真实桌面未验
- Windows 的 `vendorWindowsOffscreenPlugin()` 待有 Windows 环境时实测（12.5 写了但未跑过）
- `FakeBridge::qt_fake_default_value()` 对 `table`/`tree`/`list`/`combo` 的返回值形态与真实桥接不一致（F13 尾部）
- `--selftest`/`--difftest` 失败时退出码仍是 0，CI 里得靠 grep 判定
- `call` 的方法表还可以长：`insertRow`/`removeRow`/`appendText`（日志流）目前都只能用整表重建绕；
  W5 那三个 `reload`/`goBack`/`goForward` 也该接进同一张表（或删掉）

---

## Session 14 — 2026-10-02（Windows：修 tpc 供给路线选错）

### 任务
> 「按照新代码重新运行 hello」

### 遇到的障碍（不是代码回归，是环境解析选错了编译器）
`php bin/qtphp build examples/hello` 首次失败：

```
Fatal error: The PHPX runtime library was not found at:
  D:\git\php\tpc_v0.9.4_windows_x64\phpx\build\phpx.dll
Build PHPX first (for example, run `nmake phpx` ...)
```

### 根因
`findTpc()` 的候选表把 **composer 驱动排在原生发行包前面**，且最后一项是硬编码的
`D:/git/php/tpc_v0.9.4_windows_x64/tpc.exe`。系统里两个 tpc 的运行时来源完全不同：

| | 原生发行包 | composer 驱动 |
|---|---|---|
| 位置 | `tpc_v0.9.4_windows_x64/tpc.exe` | `vendor/bin/tpc.php` |
| 运行时 | 自包含（旁边就有 `phpx.dll`/`SDK`） | 依赖 `vendor/swoole/phpx` **源码树** |
| 状态 | 解压即用 | 源码树无编译产物，需 `nmake phpx` 自建 |

`PhpxLocator::resolve()` 去找 `vendor/swoole/phpx/build/phpx.dll` —— 该包只有
`CMakeLists.txt`/`src`/`include`，没有 `build/`，于是直接报错。

### 修法（`bin/qtphp`）
**不硬性排序，改为按运行时体检** —— 两条路线在各自平台上都是对的（macOS/Linux 全链路
本就建立在 composer 驱动 + tpc 自建私有运行时之上，见 F11.3），所以只解决
「选中了跑不通的那个」：

- `tpcHasRuntime()` —— 判据与 `findRuntimeLibDir()` 的标记集一致（`phpx/`、`SDK/` 目录
  或 php 运行时 DLL/静态库）；**`.php` 驱动一律 false**（运行时在 `~/.typephp/php-builder`，
  可能尚未构建，正是本次故障来源）。
- `findTpc()` —— 保留 composer 优先的原顺序，但选中的候选缺运行时、候选表里另有带运行时的，
  就改用后者。
- `nativeTpcSearchDirs()` —— 替掉硬编码路径（`~/tpc*`、`~/.typephp/tpc`、`/opt/tpc` 等）。
- `whichAll()` —— `where` 在 Windows 上可能返回多个，全部纳入候选。
- `TPC` / `TPC_DIR` 显式指定时不做体检，照用。

**先做错又改回的一条**：最初我把 composer 驱动整体降为兜底（"原生优先"），
随后意识到这会改变 macOS/Linux 上已验证的行为 —— 那是**推倒重来而非修复**，遂 `git checkout`
还原后改成上面的体检方案。

### 验证（真实 AOT 二进制，Windows）
```
qtphp doctor  → tpc: ...tpc_v0.9.4_windows_x64\tpc.exe  ✅
                PHP 运行时库: ...tpc_v0.9.4_windows_x64  ✅
qtphp build examples/hello → Build successful（不设任何环境变量）
hello.exe --selftest（精简 PATH）→ exit 0，14/14 ok
hello.exe --shot shot.png → exit 0，21KB PNG（读图确认：菜单/分组/进度/表格/中文全部正确）
qtphp test  → 112 tests / 183 assertions 全绿
qtphp lint  → 契约一致！
```

### 未测
macOS / Linux 上「无原生发行包、只有 composer 驱动」的退回路径 —— 逻辑上仍走原顺序，
但本窗口无 mac/Linux 环境复验。

### README 更新（本轮）
- 修掉**孤儿表格**：命令表（`qtphp doctor`/`new`/`build`/…）此前没有标题，直接挂在
  「增量补丁」的 `call` 说明后面，补上 `## 命令`。
- 新增 `### tpc 从哪来`：两条供给路线的对照表、混用时的报错原文、
  `TPC`/`TPC_DIR` 用法，并指向 `doctor` 打印的 tpc 与运行时库两行。
- 无头验收段补实测数字：三个平台都是 `--selftest` 14/14、`--difftest` 20/20。
- 事实核查（逐项对照代码，全部一致）：控件目录 30 项 vs C++ 类型分派、
  事件表 10 项 vs `enqueue(QStringLiteral(...))`、`call` 六方法 vs `method == QLatin1String(...)`、
  `qtphp new` 产物、112 测试数、`doctor` 6 行输出。

### 一次自造故障（记录以免重犯）
核查 README 时想验「`--difftest` 在 Windows 上是否可用」，注入诊断标记定位——
结果 `--difftest` 报 `0xC0000409`，`--selftest` 却正常，看起来像平台缺陷。
实际是**我的注入破坏了源文件**：python heredoc 把 `"\n"` 写成了字面换行，
`php -l` 仍报「无语法错误」（换行在双引号内合法）但语义已变；我还在受损文件上继续叠加 Edit。
还原后干净重建：**两者都通过**（14/14、20/20）。
教训：注入诊断代码后必须真正跑一遍再下结论；`php -l` 通过 ≠ 语义正确。

### 教训
"上次还好好的"不等于代码回归 —— 也可能是**环境解析**选到了另一条供给路线。
两条 tpc 路线的运行时来源完全不同，混用必炸。已记入 `findings.md` F24。

---

## Session 13 — 2026-10-02（12.9：Linux 硬前置落到 `doctor`）

### 任务
> 「继续」—— Session 12 记账时留下的「下一步」第一条：Linux 的构建/打包前置只在撞墙时才报，
> 该让 `qtphp doctor` 提前查（F21 首轮死在 `mpfr.h`、F22 才发现 slim 镜像没 patchelf）。

### 先实测探测点（不抄网上的路径）
容器里 `dpkg -L` + `ls -l` 量出来的关键事实（全写进 F23 的表）：
- **`gmp.h` 只在 `/usr/include/<三元组>/` 下**（`gmpxx.h` 在 `/usr/include`）⇒ 头文件搜索必须带多架构目录。
- **`/usr/include/onigmo.h` 不存在**，Debian 的 `libonig-dev` 给的是 `oniguruma.h`
  ⇒ 照 `--enable-mbstring` 的习惯探 `onigmo.h` 会永久误报缺失。
- `libxml2` 的头是嵌套的 `/usr/include/libxml2/libxml/parser.h`；`xz-utils` 这个包对应的命令是 `xz`。

### 代码改动（`bin/qtphp` 1828 → 1893 行）
- 新增 `linuxMissingPackages()`：12 项前置（`bison`/`re2c`/`autoconf`/`pkg-config`/`xz-utils`/`patchelf`
  按命令探测，`libgmp-dev`/`libmpfr-dev`/`libonig-dev`/`libxml2-dev`/`libsqlite3-dev`/`zlib1g-dev`
  按头文件在 `/usr/include` 与 `/usr/include/<三元组>` 两处探测），返回**缺失的 apt 包名列表**。
- `cmdDoctor()` 在 C++ 检查后加 Linux 专属一块：全齐 → `[OK] Linux 构建前置: 齐全`；
  有缺 → `[WARN] Linux 构建前置缺失: …` + `[INFO]   apt install -y <一串包名>`（提示就是可直接粘贴的命令）。
  **一律 warning、不进 `$allOk`** —— 保持「doctor 的 rc 只由 error 级项决定」的原语义，
  `build`/`package` 在真正需要时仍会各自报错并给同一条 apt 提示。

### 实测
- 正向：Linux `doctor` rc=0，7 行检查项全 `[OK]`（含新增那行）。
- **命令分支真机逼验**：`apt-get remove -y patchelf` → `[WARN] Linux 构建前置缺失: patchelf` +
  `apt install -y patchelf`；装回来恢复齐全。（`.deb` 已不在 apt 缓存里，重装要先起 F20 的宿主代理。）
- **头文件分支逼验**：往探测表塞一条 `definitely-not-here.h` → 报出该包名；还原后齐全。
  多架构目录那条分支是隐式验的：`gmp.h` 只在 `<三元组>` 目录下而检查通过。
- Linux 全套回归（`bin/qtphp` 与 mac 侧 `sha256 d6835d62…` 一致）：`doctor` 0、`test` 112/183、`lint` 0、
  `package examples/hello` 0（140.3 MB 不变）。
- macOS 回归：`php -l` 干净、`doctor` rc=0 且输出仍是原来 6 行（Linux 块不外溢）、`test` 112/183、`lint` 0。

### 记账与收尾
`findings.md` 新增 **F23**（探测点实测表 + 两条逼验 + 「warning 不影响 rc」的取舍）；
`README.md` 的 `doctor` 行补 Linux 前置探测；`task_plan.md` 加 12.9、规模改 5128。
本窗口为装卸 patchelf 重起的宿主代理收尾停掉；`tgl` 与已装好的 patchelf 保留。

---

## Session 12 — 2026-10-02（12.8：Linux `package` 在真机上实现并验收）

### 任务
> 「继续」—— `progress.md`「下一步」第一条：Linux 打包路线（F21 收尾时只剩它）。
> 环境还是 F20/F21 那台 `tgl`（Debian 12 arm64 + Qt 6.4.2），宿主转发代理按 F20 的命令重起
> （网关这次仍是 `192.168.65.1`，`/etc/apt/apt.conf.d/99hostproxy` 没改就通了）。

### 先测再定方案
1. `build/hello` 现状：`(RUNPATH) /root/.typephp/php-builder/php-8.5.11-142201d298b578e5/install/lib`，
   NEEDED 11 个，`ldd` 传递闭包 51 行全解析到 `/lib/aarch64-linux-gnu`；**ELF 里没有 libgmp/libmpfr**
   ⇒ 那两个 `-dev` 只是编译期头依赖，打包不用搬。
2. `libqoffscreen.so` 自己没有 RPATH/RUNPATH，NEEDED 全在可执行文件闭包里（先被加载，dlopen 直接复用）；
   `libqxcb.so` 另有 14 个 xcb 家族依赖**不在** exe 闭包 ⇒ 插件必须自己带 rpath，
   「在 `project.linux.yml` 加链接期 `-Wl,-rpath`」这条路覆盖不到插件，遂放弃。
3. 闭包尺寸：`du` 直接量软链只得 2.5 MB，`readlink -f` 后是 **89 个对象 / 83.2 MB**
   （`libicudata.so.72.1` 单挑 29.8 MB）⇒ 决定 `platforms/` 整目录全拷不心疼。
4. 新硬前置：slim 镜像没有 `patchelf`，`apt install patchelf` → `0.14.3-1+b1`。

### 代码改动（`bin/qtphp` 1608 → 1828 行）
- `cmdPackage()`：Linux 从「显式报错」改为派发 `packageLinuxDir()`；剩下非 Win/mac/BSD 仍显式报错。
- 新增 `packageLinuxDir()` / `linuxQtPluginDirs()` / `elfDeps()` / `isSystemSoname()` /
  `setLinuxRpath()` / `verifyLinuxPackage()`。产物布局
  `dist/<name>/{<name>, lib/*.so, plugins/{platforms,xcbglintegrations}/*.so, qt.conf, assets/}`。
- 两个关键点写进了注释与自检：**必须 DT_RPATH**（RUNPATH 不向二级依赖传递，`libQt6Gui` 的
  `libglib/libEGL` 会回落系统库），所以 `patchelf --force-rpath` + `verifyLinuxPackage()` 里读
  `readelf -d` 断言「有 `(RPATH)` 且无 `(RUNPATH)`」；**落地名用 soname** 而非 `readlink` 后的真名。
- glibc 家族（libc/libm/libdl/librt/libpthread/libresolv/libnsl/libutil/libgcc_s/ld-linux）留系统，
  **libstdc++ 搬**（GLIBCXX 符号版本卡 ABI）。

### 实测（全部真 rc）
```
php bin/qtphp package examples/hello    → rc=0，85 个 .so + 10 个插件，dist/hello 140.3 MB
readelf -d dist/hello/hello             → (RPATH) [$ORIGIN/lib]，无 RUNPATH
ldd 落在产物外的行（glibc 家族除外）      → 空
ldd plugins/platforms/libqxcb.so 同上    → 空（插件 RPATH=$ORIGIN/../../lib）
cd dist/hello && env -i QT_QPA_PLATFORM=offscreen ./hello --selftest → 14 条 ok rc=0
                                                       --difftest     → 20 条 ok rc=0
                                                       --shot         → rc=0 31841 B
```
打包产物 PNG 与开发产物 PNG **逐字节一致**（sha256 `c79f6cc5cd4e…43a3` 两边相同）⇒ 搬库+改 rpath 没改渲染。
自检的「仍指向产物之外」不是摆设：分支逼验（搬运循环故意漏 `libQt6XcbQpa.so.6`）逼出
`libqxcb.so 的 libQt6XcbQpa.so.6 => /lib/aarch64-linux-gnu/…` + `rc=1`。第一次逼验（把 `libxcb.` 塞进跳过名单）
**没触发** —— 搬运与自检共用 `isSystemSoname()`，跳过即免检；真正的漏检形态是「非 system 的 soname 不在 `lib/`」。
重复 `package`（整棵重建）rc=0；项目目录内 `php ../../bin/qtphp package .` rc=0。
macOS 侧无回归：`php -l`、`test` 112/183、`lint` 契约一致、`doctor` 全部 rc=0。

### 记账与收尾
`findings.md` 新增 **F22**（含 DT_RPATH/DT_RUNPATH 那条加载器规则的取舍、soname 落地名、`du` 软链坑，
以及明确列出**未测**：真实 X 桌面、`eglfs/linuxfb/vnc` 运行、跨发行版）。
`README.md`：平台矩阵 Linux 行改 ✅ + 实测数字、apt 行加 `patchelf`、命令表 `package` 行补 Linux 路线、
无头验收段加 Linux 那条命令与自检语义。
宿主侧本窗口自建的 `python3 proxy.py 192.168.65.1 3128` 收尾停掉；`tgl` 保留为 Linux 测试台。

---

## Session 11 — 2026-10-02（12.7：Linux 首次在真机跑通 —— Apple Container + Debian 12 arm64）

### 任务
> 「继续 linux 有 app container 应该可以模拟测试的」—— 把 F19 那句「Linux 是成建制缺口」
> 换成真实环境证据：能在 Linux 上编译、能无头验收，就照实说；不能，就照实记。

### 先解决容器网络（F20）
容器 DNS 通但**任何出站 TCP 都不通**（`223.5.5.5:443/80`、`mirrors.aliyun.com:443`、路由器 `192.168.31.1:53`、
甚至宿主自己的 LAN IP `192.168.31.96:19024` 全 FAIL），而容器 → 网关 `192.168.64.1:53/19022` 通。
排除过程：全新机器同样 FAIL（不是单机坏）、`/proc/net/route` 默认路由正常、
`--option mode=bridged` 拿到的还是 host-only 段、`container system stop/start` 无效、
无特权助手也无网络系统扩展、应用防火墙 disabled。
⇒ 绕行：在**宿主网关 IP** 上起只读转发代理（只放 `GET`/`HEAD`/`CONNECT`），apt/curl 指过去。
`apt-get update` → `Fetched 9279 kB in 11s (808 kB/s)`，`https://packages.sury.org/php` 也通（CONNECT 有效）。
副作用记两条：重启后 `default` 网段从 `192.168.64.0/24` 变成 `192.168.65.0/24`（代理要重绑），
且一度两台机器同为 `.2` 造成 ARP 冲突（删掉探测机才恢复）。

### 装出来的 Linux 环境
`qt6-base-dev 6.4.2+dfsg-10`、`cmake 3.25.1`、`g++-12`、宿主 PHP 8.4.25 跑 CLI；
PHP embed 源码构建依赖 `bison re2c autoconf pkg-config zlib1g-dev libxml2-dev libsqlite3-dev libonig-dev`。
容器盘 `504G / 502G avail`，装完 Qt 后从 2.7 G 涨到 3.0 G（宿主启动卷 9.9 Gi 未受明显影响）。

### 改动（`bin/qtphp` 1487 → 1608 行 + 一个新文件）
1. **`examples/hello/project.linux.yml`（新增）**：Debian 多架构布局的编译入口 ——
   `-I/usr/include/<三元组>/qt6{,/QtCore,/QtGui,/QtWidgets,/…/mkspecs/linux-g++}`、
   `-fPIC -DQT_*_LIB`、`-L/usr/lib/<三元组> -lQt6Widgets -lQt6Gui -lQt6Core`（无 framework、无需 rpath）。
2. **`linuxMultiarchTriple()` + `findQt()` 的 Linux 分支**：以 `/usr/lib/<三元组>/cmake/Qt6` 存在为判据，
   命中才返回 `/usr`。Linux 上 `doctor` 从「Qt: 未找到」变成「Qt: /usr」。
3. **`doctor` 的 C++ 探测**：`clang++` → `g++` 依次试（原来只认 clang++，Linux 必然误报缺工具链）。
4. **`checkLinuxLibraryDeps()`**：`ldd` 版依赖自检，只认 `not found` 与不存在的非系统绝对路径；
   `checkSharedLibraryDeps()` 按 `PHP_OS_FAMILY` 分派 —— F19 第 2 条（Linux 上静默返回 []）修掉。
5. **`cmdNew()` 生成 `project.linux.yml`**：三元组在生成时按当前机器写死
   （tpc 的 yml 只对 `sources` 支持 `PHP_OS_FAMILY` 条件，路径不插值；Debian 也没有 `/usr/include/qt6` 软链）。
6. **`cmdRun()` 的缺依赖提示**按平台分三档，Linux 档指向 `apt install qt6-base-dev`。

### 验收（全部真 rc，`out=$(…); rc=$?`）
```
Linux（Debian 12 arm64，Qt 6.4.2，offscreen）
  tpc 自建 embed 运行时  → /root/.typephp/php-builder/php-8.5.11-142201d298b578e5（与 macOS 哈希不同 ⇒ 按平台分桶）
  qtphp build examples/hello → rc=0，产物 ELF 64-bit PIE aarch64，58 MB；ldd 无 not found
  --selftest → 14/14 rc=0        --difftest → 20/20 rc=0        --shot → 760×560 PNG rc=0（读图确认控件齐全）
  qtphp run examples/hello --selftest → rc=0（走新的 ldd 自检）
  qtphp test → OK (112 tests / 183 assertions)；qtphp lint → 契约一致
  qtphp new probeapp → project.linux.yml 三元组 = aarch64-linux-gnu（正确）
  qtphp doctor → 6 项全 OK
  qtphp package → rc=1 +「未实现 Linux 平台的打包」（12.6 那条分支第一次在真 Linux 上跑到）

macOS 回归（改完 CLI 立刻复验）
  php -l bin/qtphp → 无语法错误；doctor → 6 项 OK（clang++ 仍排第一）
  build examples/hello → rc=0；offscreen --selftest → rc=0
  test → 112/183；lint → 契约一致
```

### 关键结论
**Qt 6.4.2 与 6.11.2 上断言集完全一致**（14 + 20 全绿）⇒ 声明式 diff 引擎没踩到 6.4→6.11 的行为差。
F19 说的「成建制缺口」现在收窄成一件事：**Linux 打包路线**（`package` 仍是显式未实现）。

### 未做
Linux 的 `dist/` 自包含打包；`doctor` 还没把 `libgmp-dev/libmpfr-dev` 这类 Linux 硬前置列成检查项；
Windows 分支依旧未实测。

### 收尾补验
入库版 `project.linux.yml` 用的是相对路径（`../../cpp-src`、`../../php-src`），与最初在容器里手写的
绝对路径版不同 ⇒ 用**入库文件**在容器里重跑一遍：`build` rc=0、offscreen `--selftest` 14/14 rc=0、
`--difftest` 20/20 rc=0。容器 `tgl` 保持 running（Linux 测试台），宿主侧的转发代理与文件 HTTP 服务已停。

---

## Session 10 — 2026-10-02（12.6：Linux 打包不再冒充 macOS）

### 任务
> 「继续」—— 清单第一条：Linux 打包分支缺失（F18 推断出的那条）。

### 结论先说
查完的结论是 **Linux 支持是成建制的缺口，不是一行判断**（F19 列了三处），所以本轮**没有假装实现打包**，
只修掉真正误导人的那一处：`cmdPackage()` 以前非 Windows 一律走 `packageAppBundle()`，
Linux 上报出来的是「找不到 macdeployqt / Info.macos.plist」，指不到真正缺的东西。

### 改动
1. **`bin/qtphp`**：`cmdPackage()` 改成显式三路 —— Darwin → `.app`；Windows → `dist/`；其余 →
   `未实现 <平台> 平台的打包` + rc=1。CLI 行数 1481 → 1487。
2. **`README.md`**：命令表 `qtphp package` 行写明支持范围（Windows / macOS，Linux 明确报错未实现）。
3. **`findings.md`**：F19（三处「非 Windows 即 macOS」假设：分派已修、`checkSharedLibraryDeps()` 是 Mach-O
   专属且 Linux 静默返回 []、`cmdBuild()` 的 brew 注入与 Linux 链接方式）。

### 验收（分支可达性用鉴别力反证，本机是 Darwin）
```
临时把 Darwin 条件改成 'TEMP-NEGATIVE-TEST' → php bin/qtphp package examples/hello
  → [ERROR] 未实现 Darwin 平台的打包：…   rc=1   dist/ 未被改动
还原 → grep -rn TEMP-NEGATIVE-TEST bin/qtphp src cpp-src examples → rc=1（干净）
php -l bin/qtphp → 无语法错误
重打包 → 99.7 MB；bundle env -i + offscreen --selftest → 14/14 ok, rc=0
php bin/qtphp test → OK (112 tests, 183 assertions)
```
**边界**：这验的是新分支可达 + 退出码 + 不碰 dist，不是 Linux 上的真实行为（平台名取自 `PHP_OS_FAMILY`）。

---

## Session 9 — 2026-10-02（12.5：Windows 打包分支补 offscreen 插件）

### 任务
> 「继续」—— 清单第一条：Windows 侧同款（`windeployqt` 之后是否要补 `qoffscreen.dll`）。

### 结论先说
`windeployqt` 的默认插件清单**没查到权威结论**（Qt 文档只说"collects all required plugins"，
源码 raw 抓取超时）⇒ 不押注它的行为，把补拷写成**幂等**：产物里已有 `platforms/qoffscreen.dll` 就直接返回。

### 改动
1. **`bin/qtphp`**：新 `vendorWindowsOffscreenPlugin(qtDir, distDir)`，在 `cmdPackage()` 的
   windeployqt 之后调用；缺文件才拷、源也找不到只 warning。CLI 行数 1454 → 1481。
2. **`findings.md`**：F18（windeployqt 结论不可得 → 幂等设计；以及顺带查到的 Linux 打包缺口）。

### 验收
- **macOS 回归（实测）**：`php -l bin/qtphp` 无语法错误；重打包 → 99.7 MB；
  bundle `env -i` + offscreen `--selftest` → **14/14、真 rc=0**；`php bin/qtphp test` → OK (112 tests, 183 assertions)。
- **Windows 分支：未实测**（本机无 Windows 环境）。逻辑安全性靠同构论证：`qwindows.dll` 今天就从
  `platforms/` 加载并解析到 exe 同目录的 `Qt6*.dll`，新插件走同一套解析路径。

### 顺带查到（未修）
`cmdPackage()` 用 `PHP_OS_FAMILY !== 'Windows'` 一律走 `packageAppBundle()` ⇒ **Linux 打包走不通**
（会去要 `macdeployqt` 与 `Info.macos.plist`）。按代码路径推断，未实测 Linux。已记为下一步候选。

---

## Session 8 — 2026-10-02（12.4：让 bundle 支持无头验收）

### 任务
> 「继续」—— 清单第一条：让打包产物也能 `QT_QPA_PLATFORM=offscreen` 无头验收（F16 的修法）。

### 改动
1. **`bin/qtphp`**：新增 `vendorHeadlessPlugin(qtDir, contents)` —— 拷 `libqoffscreen.dylib` 进
   `Contents/PlugIns/platforms/`，并把 `@rpath/Qt*` 引用改写成 `@executable_path/../Frameworks/...`；
   插件路径同时喂给 `vendorBundleDeps()` 兜绝对依赖。`packageAppBundle()` 在 macdeployqt 之后调它，
   找不到插件只 warning（打包不该因缺测试插件而失败）。CLI 行数 1404 → 1454。
2. **`README.md`**：无头验收段从「产物不能 offscreen」改成「package 会自动补插件」+ 两条验收命令；
   命令表 `qtphp package` 行补注。
3. **`findings.md`**：F17（含 macdeployqt 不修 `LC_RPATH` 这个关键点、为何不拷 minimal、全部实测数字）。

### 验收（`env -i` 裸环境，真退出码）
```
package            → 「已补无头验收插件」，99.7 MB（比上一轮 +0.1 MB）
bundle --selftest  (offscreen) → 14/14 ok, rc=0
bundle --difftest  (offscreen) → 20/20 ok, rc=0
bundle --shot      (cocoa)     → rc=0, 760×560 PNG 正常（读图确认，仅 F12 的 IMK 噪声）
otool -L 插件       → 只剩 @executable_path/../Frameworks 与系统框架
codesign --verify --deep --strict → OK
php bin/qtphp lint → 契约一致；php bin/qtphp test → OK (112 tests, 183 assertions)；php -l bin/qtphp → 无语法错误
```

### 未做
Windows 的 `windeployqt` 路径没动 —— 本机无 Windows 环境，`qoffscreen.dll` 是否随之部署未实测。

---

## Session 7 — 2026-10-02（12.3 之后复验打包链）

### 任务
> 「继续」—— `progress.md`「下一步」清单第一条：12.3 加了托盘/定时器后重跑 `package` → 裸环境自检。

### 实测
```
php bin/qtphp package examples/hello
  → 打包完成 dist/Hello.app（CLI 报 99.6 MB，du -sh 83M）
env -i PATH=/usr/bin:/bin HOME=$HOME Hello.app/Contents/MacOS/hello --selftest   → 14 行 ok + "selftest passed"，rc=0
env -i … --difftest   → 20 行 ok + "difftest passed"，rc=0
env -i … --shot /tmp/qtphp-pkg.png   → rc=0，PNG 760×560，「实时」分组与状态栏在图上（读图确认）
```

### 新发现（详见 F16）
**bundle 不能 offscreen**：`Contents/PlugIns/platforms/` 里只有 `libqcocoa.dylib`，
所以 `env -i QT_QPA_PLATFORM=offscreen Hello.app/.../hello --selftest` 直接 SIGABRT（真 rc=134，
stderr 是 `Available platform plugins are: cocoa.`）。build 目录那个二进制能 offscreen 是靠开发机的
`/opt/homebrew/share/qt/plugins/platforms/libqoffscreen.dylib` —— 该能力不随 bundle 走。
⇒ 对产物的裸环境验收必须走 cocoa（需 GUI 会话），上面三条就是这么跑的。

### 仍未验证
`--selftest` 里的 `tray` 用例是 `dispatch(['type'=>'tray'])` **合成事件**，只证明 handler 挂得上；
真机上托盘图标是否出现、点得到点不到，本轮没看。

### 自我纠正两处
1. 一开始把 `headless(true)` 当成 QPA 平台开关读了 —— 实际它只绕开模态框（`src/QtApp.php:192`）。
2. 第一次跑 offscreen 那条用了 `cmd 2>&1 | tail`，`$?` 拿到的是 `tail` 的 0，**差点把 SIGABRT 记成通过**；
   改成先 `out=$(cmd 2>&1); rc=$?` 才看到真 rc=134。

---

## Session 6 — 2026-10-02（Phase 12.3：多窗口 / 托盘 / 定时器演示）

### 任务
> 「继续」—— 下一个计划项 12.3：示例补多窗口 / 托盘 / 定时器演示，同时 `--selftest`、`--shot`、`--difftest` 三者保持全绿。

### 本轮改动
1. **`src/QtApp.php`**：新增公开 `isOpen()`（`window !== null && qt_window_is_open(...)`）。
2. **`cpp-src/qt_bridge.cc`**：`setTray()` 补**兜底图标** —— 原来只在 `icon` 非空时 `setIcon()`，
   现依次回退「窗口图标 → `style()->standardIcon(QStyle::SP_ComputerIcon)`」。
3. **`cpp-src/qt_common.h`**：`#include <QStyle>`。
4. **`examples/hello/src/main.php`**：`$state` 增 `ticks`/`tray`/`log`；视图新增「实时」分组
   （`live_count` label + `open_log_btn`）；`setTray()` + `onAny('tray')`、`setTimer('clock',1000)` +
   `on('clock','timer')`；`open_log_btn` 懒建副窗口（`new QtApp()` + `createWindow` + 一次性 `render`，**不是 `view()`**，
   否则每帧重渲染会把 `appendRows` 的行冲掉）；新全局 `log_append()` 走 `patch/call appendRows`；
   `main()` 尾部由 `$app->run()` 换成**双窗口按帧轮流泵** + `$app->destroy()`。
5. **`tests/QtAppTest.php`**：+4 例（`isOpen` 生命周期 / 未建窗口不抛 / 副窗口独立泵 / tray+timer 分派）。
6. **`examples/hello/src/main.php`（验收）**：`self_test()` +4 条用例（open_log_btn / timer / tray / close_log_btn），
   `--selftest` 分支补 `$state['log']->destroy()` 避免副窗口实例滞留。
7. **`README.md`**：新增「多窗口 / 托盘 / 定时器」章节（泵循环片段、timer/tray 事件口径、托盘兜底理由、
   托盘不可用与 `notify` 回退）；无头验收改为「三个内置开关」、断言数 20 条；测试数 108→112。
8. **`task_plan.md` / `findings.md`**：Phase 12 收口（12.1/12.2/12.3 全 `[x]`）、F14、F15。

### 两处真实缺口（不是美化）
- **缺 `isOpen()`**：`QtApp::run()` 每帧只 `process_events` + `drainEvents` **自己那个窗口**的队列，
  所以多窗口必须自己写轮流泵的外层循环，而循环条件需要「主窗口还开着吗」这个问法。
- **托盘无图标不显示**：macOS/Linux 上空的 `QSystemTrayIcon` 根本不出现 ⇒ 「托盘演示」会是看不见也点不到的空壳。
- **`tray` 事件不带 id**：`handleEvent` 的分支是 `if ($id !== '' && …)`，`enqueue("tray")` 无 id ⇒ 只能用 `onAny('tray', …)`。
- **`appendRows` 前必须先泵一帧**：`patch` 查的是已存在的控件，`render()` 之后立刻追加会静默丢掉第一条日志。

### 验收（本轮实测，`QT_QPA_PLATFORM=offscreen`）
```
php bin/qtphp build examples/hello   → Build successful: examples/hello/build/hello
./build/hello --selftest             → 14 行 ok + "selftest passed"
./build/hello --difftest             → 20 行 ok + "difftest passed"
./build/hello --shot /tmp/qtphp-123.png → rc=0，PNG 760 x 560（「实时」分组已在图上，读图确认）
php bin/qtphp test                   → OK (112 tests, 183 assertions)
php bin/qtphp lint                   → [OK] 契约一致
```
离屏泵循环 + 真实 `QTimer` 后台跑 3 秒：`ALIVE after 3s (pid=74522)`，日志仅 1 行字体噪声、无 PHP 错误，进程已清理。
**边界说明**：3 秒存活只证明不崩，不替 handler 可调用背书 —— 后者的证据是 `--selftest` 那 4 条新用例。

### AOT 侧新验证
`date('H:i:s')` 在编译产物里可调用；把 `QtApp` 实例存进 `array<string,mixed>` 的 `$state` 再用 `instanceof` 取出来用，可行（此前仓库无先例）。

---

## Session 5 — 2026-10-02（Phase 12.2：`patch()` 的 `call` 操作）

### 任务
> 「继续实现 12.2」—— 把 `qt_window_patch` 里那个 `Q_UNUSED` 预留桩变成真行为。

### 本轮改动
1. **`cpp-src/qt_bridge.cc`**：`call` 分支实现六个方法（`appendRows`/`clear`/`setText`/`setValue`/`select`/`focus`），
   `args` 统一按位置参数取；新增 `QtWindowBox::forgetProps(id, keys)` 作废被命令式改过属性的 diff 签名。
   `select` 复用 `qtApplyProp("current")`，`setValue` 在 `text`/`value` 间按控件类别分派 —— 两条路径共用一套口径。
2. **`cpp-src/qt_widgets.cc`**：新 `qtAppendTableRows()`（尾部追加、不清空、不动选中，写 `Qt::UserRole` 行 id，
   按最宽行扩列）与 `qtClearContent()`（按类型清：table 去行 / tree、list、combo 去条目 / 文本类置空）。
3. **`cpp-src/qt_common.h`**：上述三者的声明。
4. **`php-src/qt.stub.php`**：`qt_window_patch` docblock 从两行示例扩到完整方法表 + 位置参数约定 +
   「命令式旁路、下一次 render 以树为准」的语义说明。
5. **`src/FakeBridge.php`**：镜像六个方法，并加「未知 id 整条 `call` 跳过」守卫（真实桥接是 `if (!widget) continue;`）。
6. **`tests/QtAppTest.php`**：+5 例（appendRows / clear 按类型 / setText+setValue / select+focus / 未知方法与未知 id）。
7. **`examples/hello/src/main.php`**：`--difftest` +8 条真实 Qt 断言（含追加行按 id 可选中、`clear` 后置空、
   **命令式改过后重渲染必须以树为准**、label/lineedit/progress 的 `setText`/`setValue`、`focus` 不改值）。
8. **`README.md`**：新增「增量补丁」章节（`set` 与 `call` 两种形态 + 六个方法表 + 旁路语义）；
   测试数 103→108、`--difftest` 断言数说明改 20 条。

### 为什么需要 forgetProps（这轮的真正收获）
`propSigs_` 记的是「上次应用过的值」，而命令式改控件不经过它。不作废就会：`clear` 清空表 →
下一次 `render()` 同一棵树算出的 `rows` 签名与存储值**相等** → `structuralChanged()` false → 不重建 → **表永久为空**。
摘掉键后比较的是 `""` vs 真实签名（连 undef 的签名都是 `"\x01"`），必然不等 ⇒ 下一次 render 重新同步。

### 验收（本轮实测，`QT_QPA_PLATFORM=offscreen`）
```
build examples/hello                          → Build successful
--difftest                                    → 20 行 ok + "difftest passed"
--selftest                                    → "selftest passed"
--shot /tmp/qtphp-122.png                     → PNG 760 x 560
php bin/qtphp test                            → OK (108 tests, 173 assertions)
php bin/qtphp lint                            → [OK] 契约一致
```

### 鉴别力反证（防止断言空跑）
把 `forgetProps()` 临时改成 `return;` 重编译 → 第 16 条 `FAIL table 命令式改过后重渲染以树为准 -> {"row":-1,"value":""}`，
正是预测的「永久空表」失效模式；删掉临时行重新编译后恢复 20/20，并 `grep TEMP-NEGATIVE-TEST cpp-src/*` 确认源码干净（rc=1）。

---

## Session 4 — 2026-10-02（Phase 12.1：表格/树 diff 边界验收）

### 任务
> 用户指定的三条里的第一条：「更多控件行为测试（表格/树的 diff 边界）」。

### 结论先说
在真实 AOT 二进制 + Qt 6.11.2 下新写的 12 条断言**首轮 7/12 失败** —— 也就是说 README 那句
「表格选中/滚动位置在重渲染后保留」此前是**空头承诺**。四个根因都已修掉，现在 12/12。
这类缺陷 FakeBridge 测不到：它不重建控件、不持有 Qt 选中状态。

### 本轮改动
1. **`cpp-src/qt_common.h`**：新增 `qtIsStructuralKey(type,key)` / `qtStructuralKeys(type)` —— 结构键按控件类型判定
   （`table` → `columns`/`rows`/`row_ids`，`tree` → `headers`/`nodes`）；`QtWindowBox` 声明私有
   `bool structuralChanged(id,node,type)`。
2. **`cpp-src/qt_bridge.cc`**：
   - `buildNode()` 里表格/树的重建**移到 `applyNodeProps()` 之前**，且只在 `structuralChanged()` 为真时执行；
   - `applyNodeProps()` 跳过键改为 `id`/`type`/`children` + `qtIsStructuralKey(type,key)`
     （顺带救活 `QTextEdit` 被误杀的 `rows` 属性）；
   - 新 `structuralChanged()`：复用 `propSigs_[id]` 里的签名，不另建一套状态；
   - `patch()` 的 `set` 分支原先把**操作数组**传给重建函数（`qtField(node,"rows")` 恒取不到 ⇒
     `setRowCount(0)`/`clear()` 把控件清空），改为先按 `props` 重建、再跳过结构键。
3. **`cpp-src/qt_widgets.cc`**：`qtRebuildTable`/`qtRebuildTree` 在数据非数组时**提前 return，不做任何破坏性调用**；
   重建后按 `Qt::UserRole` 的**行 id / 节点 id** 恢复选中（`row_ids`/`id` 缺失才退化成索引），
   恢复段包在 `QSignalBlocker` 内以免发出用户没做过的 `select` 事件；`columns` 为空时列数扩到最宽行
   （控件默认构造是 `QTableWidget(0,1)`）。
4. **`examples/hello/src/main.php`**：新增 `--difftest` 开关 + `diff_table_node()`/`diff_tree_node()`/`diff_test()`，
   12 条断言。用独立窗口跑，避免主窗口 `view()` 的每帧重渲染把表格删掉。
5. **`README.md`**：无头验收从「两个开关」改成「三个内置开关」并写明为何必须用真实二进制；
   补 `current` 在 table/tree/list/combo/tabs/stack 上的各自语义与「按 id 跨帧保留选中」。

### 验收（本轮实测，`QT_QPA_PLATFORM=offscreen`）
```
./examples/hello/build/hello --difftest   → 12 行 ok + "difftest passed"，rc=0
./examples/hello/build/hello --selftest   → "selftest passed"
./examples/hello/build/hello --shot …     → PNG 760 x 560
php bin/qtphp test                        → OK (103 tests, 162 assertions)
php bin/qtphp lint                        → [OK] 契约一致
```

### 踩坑
- clang++：`no matching member function for call to 'get'` —— `php::Array::get` 只接 `const char*`/`size_t`，
  不接 `QString`；`const QByteArray name = key.toUtf8(); spec.get(name.constData())`。
- 结构键跳过表初版类型无关，直接把 `QTextEdit` 的 `rows` 属性判成表格结构键 ⇒ 属性静默失效。

---

## Session 3 — 2026-10-02（macOS）

### 任务
> 尝试本地运行 `examples/hello`；随后：`findTpc()` 改跨平台、补 Qt 环境、`project.yml` 改平台条件。

### 平台事实（先纠正一条误记）
- `~/../typephp-tinygui/tools/build-macos.sh` 在 mac 上跑通的是 **clang++ 原生壳 + 系统 PHP shebang 后端**，
  脚本注释自己写明「tpc AOT is a Windows-only toolchain」；Linux 侧同样只有 shebang 后端 + `--nano` 实验。
  ⇒ 本项目此前从未在 mac 上做过 tpc AOT 编译，`~/.typephp`（私有 PHP 运行时缓存）不存在。
- 但 tpc **v0.9.4 本身在 macOS 可用**：`php vendor/bin/tpc.php --version` 正常，
  `Initialized platform/backend: macOS + Clang (clang++)`，源码里 `Build/CompilerToolchain.php:119`、
  `NativeSourceProjectBuilder.php:111` 都有 Darwin 分支。

### 本轮改动
1. **`bin/qtphp` `findTpc()` 跨平台**（+16/−3）
   - 补 `vendor/bin/tpc.php`、`vendor/swoole/typephp/bin/tpc.php` 候选：composer 只发 `bin/tpc.php`，
     `bin/tpc` 是各平台预编译二进制，安装时被跳过（`Skipped installation of bin bin/tpc`）。
   - PATH 探测按平台在 `where tpc` / `command -v tpc` 间切换；`file_exists`→`is_file`。
   - 新增 `tpcCommand()`：扩展名为 `.php` 的入口必须用 `PHP_BINARY` 起，不能当二进制执行。
   - `cmdBuild()` 改用 `tpcCommand()` + `escapeshellarg()`；Windows 的 `tpc.exe` 候选与 `vcvars64.bat` 分支不变。
2. **Qt6 环境补齐**：`brew install qtbase`（6.11.2，只装 base，避开 `brew install qt` 的 qtwebengine 全家桶）。
   `findQt()`/`project.yml` 尚未接 macOS 路径 —— 这是下一步。
3. **Phase 11.1 run 跨平台**：`cmdRun()` 按平台找产物（mac 无 `.exe`）；新增 `machODeps()`/`isSystemDepPath()`
   走 `otool -L` 自检；`deployRuntimeDlls()` 收进 `PHP_OS_FAMILY === 'Windows'` 分支。
4. **Phase 11.2 package 跨平台**：`cmdPackage()` mac 早退到 `packageAppBundle()`，
   对齐上游 `qt-taskboard`（`Info.macos.plist` → `macdeployqt -always-overwrite -no-codesign`
   → `vendorBundleDeps()` 兜底搬 brew 依赖 → ad-hoc 深度签名 → `verifyAppBundle()` 校验
   「无 bundle 外绝对依赖 + `plutil -lint` + `codesign --verify --deep --strict`」。
5. **Phase 11.3 「tpc 在哪」与「运行时库在哪」拆开**：删掉 `findTpcDir()`（composer 装下它返回
   `vendor/bin`，那里一个运行时库都没有），改为 `findRuntimeLibDir()` —— 按标记文件
   （`phpx.dll`/`php8ts.dll`/`libphp.a`/`libphp.dylib`）筛候选：native tpc 同目录 → `TPC_RUNTIME_DIR`
   → `~/.typephp/php-builder/php-‹版本›-‹指纹›/install/lib`（多份取 mtime 最新）。
   `deployRuntimeDlls()` 定位不到时点名原因并给补救命令（此前 null 会拼出 `/phpx.dll`）；
   删除 `cmdNew()` 里从未被模板插值的死 `$tpcDir`；`cmdDoctor()` 增加「PHP 运行时库」检查，
   MSVC 检查收进 Windows 分支、非 Windows 改查 `clang++`。
6. **Phase 11.4 勘误**：README 项目树里「仓库根 project.yml = 桥接单独编译检查」是幻影条目，
   按磁盘实况改写为两份真实入口 yml，并说明桥接的验证路径就是 `qtphp build examples/hello` +
   `qtphp lint`；Session 1 文件清单同步加注勘误。CLI 表也按平台重写。
7. **Phase 11.5 `cmdRun()` 参数透传**：签名加 `array $args = []`，`main()` 用 `array_slice($argv, 3)`
   递进来；命令拼接从手搓双引号改成 `escapeshellarg($exe)` + 逐个 `escapeshellarg($arg)`。
   README 里 `--shot`/`--selftest` 的示例改成平台无关的 `qtphp run <path> …`，
   上手第 4 步不再只列 `.bat`，测试数 89 → 103。
   **顺带修真缺陷**：`run .` / `package .` 因 `basename('.')` 一直是坏的（且 `file_exists('./build/.')`
   为真导致校验失效），新增 `projectDir()`（`rtrim` + `realpath`）供两处使用（见 F11.11）。

### 验证
| 检查 | 结果 |
|---|---|
| `php bin/qtphp doctor` | ✅ `[OK] tpc: .../vendor/bin/tpc.php`、`[OK] PHPUnit`（此前 tpc 是 ERROR） |
| `php bin/qtphp test` | ✅ OK (103 tests / 162 assertions)，改 CLI 后复跑仍全绿 |
| `php bin/qtphp lint` | ✅ 契约一致 |
| 无头跑示例（PHP + FakeBridge） | ✅ `--selftest` 10 个事件全 ok；`--shot` 渲染 2 帧并记录 snapshot 调用 |
| Qt6 编译+链接（`/tmp/qtlink.cpp` QLabel 程序） | ✅ rc=0，`otool -L` 确认链到 `QtWidgets/QtGui/QtCore.framework` |
| `php bin/qtphp build examples/hello` | ✅ `Build successful` → `examples/hello/build/hello`（Mach-O arm64，24 MB）。途中依次踩过：yml 硬编码 D:/ → 缺 embed 运行时 → phpx 缺 gmp/mpfr 头 → framework 转发头缺 `-F` → 缺 `-liconv` |
| 产物依赖 | ✅ `otool -L`：QtWidgets/QtGui/QtCore 6.11.2 + libiconv/gmp/gmpxx/mpfr/onig，rpath 已写 |
| 真实二进制 `--selftest` | ✅ 10/10 全 ok 并干净退出（首轮卡在 `copy_btn`，根因见 `notify()` 修复） |
| 真实二进制 `--shot` | ✅ `/tmp/hello-shot.png` 760×560，肉眼核对：菜单栏、greeting、输入框+打招呼、设置组（复选+下拉）、进度组（进度条+推进/重置+点击次数）、三个底部按钮+链接、状态栏「就绪」全部正确 |
| 不带手工 env 的可复现性 | ✅ 删掉 `~/.typephp/.../phpx-build` 后 `php bin/qtphp build` 裸跑：`Building static PHPX runtime` → `Build successful`，缺头文件报错 0 次（证明 CLI 自动注入 `CPATH`/`LIBRARY_PATH` 生效） |
| Phase 11.1：`qtphp run examples/hello`（mac） | ✅ 找到无 `.exe` 的产物并真正起进程（Qt 字体日志证明已初始化），不再误报「缺 DLL」；`deployRuntimeDlls()` 收进 Windows 分支 |
| `checkSharedLibraryDeps()` 正/负双向 | ✅ 真产物 → 无缺失；`install_name_tool` 改坏依赖的副本 → 精确报出那一条（系统库不误报） |
| 按上游约定改名 `project.darwin.yml` → `project.macos.yml` 后重编 | ✅ 仍 `Build successful`（`-Wl,-framework` 形式实测可用） |
| Phase 11.2：`qtphp package examples/hello`（mac） | ✅ 产出 `dist/Hello.app` 99.4 MB，macdeployqt + ad-hoc 签名零告警 |
| bundle 自包含性 | ✅ `otool -L` 主二进制无 bundle 外绝对路径；**`env -i` 裸环境跑 `Contents/MacOS/hello --selftest` → 10/10 通过**（默认 cocoa 插件，`codesign -dv` 显示 adhoc） |
| Phase 11.3：`findRuntimeLibDir()` 四条路径 | ✅ 临时副本（`sed '$d' bin/qtphp > bin/.tmp-lib-check.php`）逐个验：空 HOME+无发行包 → `NULL`；`TPC_RUNTIME_DIR` 指向放了假 `phpx.dll` 的目录 → 命中该目录；真实 HOME → `/Users/jay/.typephp/php-builder/php-8.5.11-5852a1ce211cc711/install/lib`；`deployRuntimeDlls()` 无库时输出 2 条可操作警告，无 PHP warning/TypeError |
| `php bin/qtphp doctor`（11.3 后） | ✅ 新增 `[OK] PHP 运行时库: ~/.typephp/.../install/lib`；MSVC 检查按平台分派，mac 改报 `[OK] C++: /usr/bin/clang++`，6 项全 OK |
| `php bin/qtphp test` / `lint`（11.3 后复跑） | ✅ OK (103 tests / 162 assertions)、契约一致 |
| Phase 11.4：仓库根 `project.yml` 是否存在 | ❌ 不存在，计划项前提为假：`find` 只有 examples/hello 两份 yml、`git log --all -- project.yml` 零记录、`.gitignore:13` 把它列为生成物。出处是 README 项目树 + Session 1 文件清单，两处已勘误（见 F11.9） |
| 剩余 `D:/` 硬编码复核 | ✅ `git grep -n D:/`：`bin/qtphp` 4 处是 `is_file`/`is_dir` 候选（mac 永不命中，无害）、`cmdNew()` 的 Qt 回落属 11.6、`examples/hello/project.yml` 是**故意的** Windows 入口 |
| Phase 11.5：`qtphp run examples/hello --selftest` | ✅ 参数透传生效，10/10 全 ok（这是 mac 上第一次用 CLI 一条命令跑完无头验收，不必手敲 `build/hello`） |
| 带空格的多参数透传 | ✅ `qtphp run examples/hello --shot "/tmp/qtphp 11 5.png"` → 文件按原名落盘，`file` 确认 760×560 PNG |
| 退出码传递 | ✅ 造一个假产物脚本（`echo "args: $*"; exit 7`）当 `build/fakeproj`：`qtphp run /tmp/fakeproj --selftest '--shot /tmp/a b.png'` → 应用侧收到的参数与传前一致，`rc=7` 逐位传出 |
| **`qtphp run .` / `package .`（修 `basename('.')` 前）** | ❌ 复现真缺陷：`run .` → `sh: ./build/.: is a directory` rc=126；`package .` → 「未找到 bundle 描述文件」。根因 `basename('.')` = `.`，且 `file_exists('./build/.')` 因它是目录而**为真**，前置校验失效（F11.11） |
| **`qtphp run .` / `package .` / `build .`（加 `projectDir()` 后）** | ✅ 在 `examples/hello` 目录内：`run . --selftest` 10/10；`package .` 重出 `dist/Hello.app` 99.4 MB，`env -i` 裸环境 `--selftest` 仍 10/10；`build .` 复用缓存 3.6 s 成功 |
| `php -l` / `test` / `lint`（11.5 后） | ✅ 语法通过、OK (103 tests / 162 assertions)、契约一致 |
| 产物是否会被误提交 | ✅ `git check-ignore -v` → `examples/hello/.gitignore:/build/` 与根 `.gitignore:examples/hello/dist/` 都命中，未跟踪项只剩两份 mac 配置 |

### 环境噪声
- anaconda 的 Qt 5.15.2 在 PATH 上遮蔽 brew 的 `qmake`/`qtpaths`（brew 自己会提示），
  且它没有 `QtWidgets` 顶层 include 目录。构建时一律用 `/opt/homebrew/opt/qtbase` 绝对路径，不吃 PATH。
- 磁盘：数据卷剩 ~11Gi。`~/.typephp` 实测占用：php-src 源码 + 构建 + `install/lib/libphp.a`（62 MB）+
  `install/bin/php`（23 MB）+ `phpx-build/lib/libphpx.a`，一次建成后第二次构建秒级复用（日志出现 `Reusing private PHP runtime`）。
- 本轮新暴露的**跨平台缺陷**（非 mac 专有）：`QtApp::notify()` 漏了 `headless` 守卫，
  任何没有系统托盘的环境（Linux CI、offscreen）都会在 `notify` 处永久阻塞。

### 已知教训延续
- 「本地测试全绿」≠「AOT 二进制能跑」这条本轮再次成立：mac 上能验证的只有 PHP 领域层（FakeBridge），
  C++ 桥与 AOT 严格性必须等 tpc 真编译。

---

## Session 2 — 2026-10-01（续）

### 用户报告的问题
> 点各个按钮和"关于"菜单都报 `stdClass::{closure}() expects exactly 0 arguments, 1 given`

### 根因
ZendPHP 对用户函数的多余实参**静默忽略**，AOT 编译后的闭包做**精确校验**。
`QtApp` 统一用 `$handler($event)` 调用，导致所有 0 参处理器（`function () {...}`）全部报错。
这是"本地测试全绿、编译后一点就崩"的典型 —— 因为 FakeBridge 跑在 ZendPHP 上。

### 修复
- `QtApp::on()/onAny()` 在**注册时**用反射探测必需参数个数，存 `[handler, arity]`
- 分发时按 arity 调用（0 → `$handler()`，≥1 → `$handler($event)`）
- 实测 AOT 支持反射，闭包/方法数组/函数名字符串三种 callable 都能正确探测

### 顺带修复
- **无头阻塞**：`--selftest` 触发 `msg_btn`/`menu.about` 时卡死在模态对话框。
  新增 `QtApp::headless()`，消息框返回 default、文件对话框返回空。
- **`QtApp::lastError()`**：无头环境看不到错误框，需要能程序化读取失败原因。
- **AOT 类型推断**：`$ref` 不能先绑 `ReflectionMethod` 再绑 `ReflectionFunction`，
  必须用两个变量（报 `Cannot re-assign typed object`）。

### 验证

```
示例 --selftest（真实 AOT 二进制，精简 PATH）
  ok   click greet_btn        ok   click copy_btn
  ok   submit name_input      ok   click msg_btn
  ok   toggle dark_toggle     ok   click doc_link
  ok   change theme_combo     ok   menu menu.about
  ok   click step_btn         selftest passed
  ok   click reset_btn

qtphp test → 103 tests, 162 assertions 全绿（新增 14 个 arity/headless 回归）
qtphp new newapp && build && --selftest → selftest passed
```

### 新增回归测试（防复发）
- `testZeroArgHandlerIsCalledWithoutEvent` —— 直接覆盖本次 bug
- `testZeroArgWildcardHandler`
- `testMethodArrayHandlerArity` / `testStringCallableHandler`
- `testHeadless*`（4 个）—— 无头模式行为
- `testLastError*`（3 个）

### 教训
FakeBridge 跑在 ZendPHP 上，**无法复现 AOT 的严格性**。凡是依赖"PHP 宽容行为"
（多余实参、弱类型转换、动态特性）的代码，都必须用真实 AOT 二进制验收，
`--selftest` 就是为此加的。

---

## Session 1 — 2026-10-01

### 开始状态
- `D:\git\php\typephp-qt` 为**空目录**。
- 工具链：Qt 6.9.3 MSVC / tpc v0.9.4 / MSVC 2022 BuildTools。

### 交付结果（全部完成并实测）

| 交付标准 | 状态 | 证据 |
|---|---|---|
| `composer.json` 可 `composer validate` | ✅ | `./composer.json is valid` |
| `qtphp doctor/new/build/run/package/test/lint` 可用 | ✅ | 逐个实跑通过 |
| 示例能 build → run 出窗口 → package 自检通过 | ✅ | 见下 |
| `qtphp test` 无 Qt 环境全绿 | ✅ | 当时 89 tests（Session 2 增至 103） |
| README 说清 5 分钟上手 | ✅ | `README.md` |

### 端到端验收（实测）

```
qtphp build examples/hello
  → Build successful: examples/hello/build/hello.exe
  → 已部署 11 个运行时文件到 build/

hello.exe（精简 PATH: C:\Windows\System32;C:\Windows）
  → GUI 模式：窗口启动，事件循环运行
  → --shot shot.png：退出码 0，生成 17KB PNG，渲染正确

qtphp package examples/hello
  → dist/ 70.2 MB，自检"关键文件齐全"
  → dist/hello.exe 在精简 PATH 下运行成功（退出码 0）
```

### 本轮修复的 4 个真实缺陷

1. **`Array*` 隐式转 bool**（9 处）—— `qtPropInt(&spec, ...)` 传指针变成"值为 bool 的数组"，
   运行时抛 `parameter 1 must be \`array\`, got \`bool\``。
2. **自动 id 每帧递增** —— 无 id 节点每帧重建控件，删旧控件留悬垂指针，第二次渲染崩溃
   （`0xC0000005`）。改为按结构路径稳定生成。
3. **`removeStale()` 直接 delete** —— 布局留悬垂 item。改为先摘除再 `deleteLater()`。
4. **CLI 未加载 MSVC 环境** —— 只查 `cl.exe` 误判（它在 PATH 上但 `INCLUDE` 未设）。
   改为查 `INCLUDE`，并自动 `call vcvars64.bat`。

### 架构决策修正

- **放弃预编译桥接库**（实测证伪）：tpc `-m lib` 只导出有函数体的 PHP 实现，
  桥接 stub 按契约必须空体，故生成的 stub 恒为空。改用**源码内联** ——
  桥接 `.cc` 直接列进应用 `sources`。
- **FakeBridge 改为全局函数**：与真实桥接形态一致（`qt_*` 是全局函数），
  领域层代码写 `qt_window_render(...)` 在测试和 AOT 下都成立。

### 文件清单

```
bin/qtphp                  CLI（7 个子命令）
cpp-src/qt_common.h        共享头：转换工具、Box、ChildSlot
cpp-src/qt_bridge.cc       窗口/渲染 diff/装饰/对话框/24 个包装符号
cpp-src/qt_widgets.cc      控件工厂/属性应用/取值
php-src/qt.stub.php        桥接契约（24 个函数）
src/QtApp.php              应用框架
src/WidgetTree.php         声明式控件树构建器
src/FakeBridge.php         纯 PHP 桥接替身
tests/*.php                3 个测试文件 + bootstrap
examples/hello/            示例应用（build/run/package/--shot/--selftest）
README.md                  使用文档
findings.md                调研结论与踩坑（F1–F10）
task_plan.md               计划与决策
（本清单曾列有仓库根 project.yml —— Session 3 核实该文件从未入库、磁盘也不存在；
  桥接 .cc 只能随应用源码内联编译，见 F7，因此没有独立的根编译配置）
```
