# agui-demo — AG-UI 协议离线回放示例

用 TypePHP\Qt 的 AG-UI 三层支持（`qt_sse_*` SSE 流式桥接原语 + `src/AGUI/` 纯 PHP
协议层 + 现有控件树组合渲染）演示一个 Agent 会话的回放，**全程零外部网络**，
无头可验收。

## 三种模式

| 命令 | 说明 |
|---|---|
| `build/agui-demo` | GUI 主循环：窗口显示回放后的消息列表 / 工具调用表 / 状态栏 |
| `QT_QPA_PLATFORM=offscreen build/agui-demo --selftest` | 无头自检三段：① 纯 PHP 回放断言（fixture → 解码 → 归约 → viewModel）② 控件树读回断言 ③ file:// SSE 桥接 smoke（150 帧泵帧上限）。全过 `selftest passed` + rc=0，任一失败 rc=1 |
| `build/agui-demo --bench [N]` | 纯 PHP 回放 N 事件（缺省 20000）计时，stdout 打三行 `METRIC agui_replay_events_per_s / agui_replay_total_ms / agui_bench_events` 供基准脚本解析；在 Qt 创建前完成并 exit(0) |

## 配对规则

`project.yml` 列了 `src/AGUI/SseClient.php` 就**必须**同时列 `cpp-src/qt_sse.cc`
—— SseClient 类体引用 `\qt_sse_*` 全限定桥接函数，缺 TU 则链接期 undefined symbol
（与 `qt_qml.cc` 静态表强制全应用链接的教训同族、方向相反）。本示例不定义
`QTPHP_NO_NETWORK`，qt_sse.cc 编进真实实现（QNetworkAccessManager）。

## fixture

`src/main.php` 的 `aguiFixtureEvents()` 内置 29 事件序列：run 生命周期 + step
配对 + 文本 delta 拼接 + 两个工具调用（args 双段拼接 / result 落位）+
STATE_SNAPSHOT/DELTA + reasoning 段 + MESSAGES_SNAPSHOT（全量替换，消息数断言
基准），故意混入一条 legacy `THINKING_TEXT_MESSAGE_CONTENT`（映射容错）与一条
未知事件（透传 warning）。
