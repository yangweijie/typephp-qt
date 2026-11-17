# AG-UI

AG-UI（Agent-User Interaction Protocol）：agent 生产方用 HTTP POST 收一个 `RunAgentInput`，
回应体是 `text/event-stream`，帧内容是**带类型的 JSON 事件** —— 消息、工具调用、推理、
状态更新，消费方边到边渲染。TypePHP\Qt 的 AG-UI 支持分两层，渲染层就是你现有的控件树：

| 层 | 位置 | 职责 |
|---|---|---|
| **协议层**（纯 PHP） | `src/AGUI/`：`JsonPatch` / `Decoder` / `Session` / `SseClient` | 解 SSE 帧、展开紧凑 chunk、把事件归约成视图模型。不碰 Qt、不碰网络 —— `qtphp test` 可单测 |
| **SSE 原语**（C++ 桥） | `cpp-src/qt_sse.cc` + 4 个 `qt_sse_*` stub + `QtApp::sse*` | 一条异步流：开一次、从每流独立缓冲里拉帧、读状态、关 |
| **渲染** | 你的 `view()` 控件树 | 用 `viewModel()` 配普通控件画消息 / 工具调用 / 状态栏 —— **v1 不新增控件类型** |

数据路径：`qt_sse_read()` 拉已完成的帧 → 每帧的 `data` 做一次 `json_decode` → `Decoder`
把紧凑 chunk 展开成规范事件（合成隐式 `END`）→ `Session` 归约 → `viewModel()` 是渲染层
唯一的读口。

## 用 `SseClient` 接活流

`SseClient` 是原语之上的薄缝：一个 run = 一条流。真实桥与测试里的 `FakeBridge` 用**同一个
类**，没有注入缝。

```php
use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;
use TypePHP\Qt\AGUI\SseClient;

function main(int $argc, array $argv): void
{
    $app = new QtApp();
    $app->create(['name' => 'AgentRun']);
    $app->createWindow('Agent run', ['width' => 640, 'height' => 420]);

    $client = new SseClient();
    $client->run('http://agent.example:8080/run', [
        'threadId' => 't-1',
        'runId'    => 'r-1',
        'messages' => [['id' => 'm-1', 'role' => 'user', 'content' => 'Deploy the release']],
    ]);

    // 帧到达靠窗口事件泵：定时器每帧拉一轮；poll() 同时驱动展开与归约。
    $app->setTimer('pump', 16);
    $app->on('pump', 'timer', function (array $event) use ($client): void {
        $client->poll();
        if (($client->status()['state'] ?? 'unknown') !== 'open') {
            $client->close();
        }
    });

    $app->view(function () use ($client): array {
        $vm = $client->viewModel();
        $lines = [];
        foreach ($vm['messages'] as $message) {
            $lines[] = $message['role'] . ': ' . $message['content'];
        }
        $tools = $client->session()->toolCalls();
        $rows = [];
        foreach ($tools as $call) {
            $rows[] = [$call['id'], $call['name'], $call['args'], $call['result']];
        }
        return WidgetTree::vbox([
            WidgetTree::scroll([WidgetTree::textEdit(implode("\n", $lines), ['id' => 'transcript'])]),
            WidgetTree::table(['id', 'tool', 'args', 'result'], $rows, ['id' => 'tools']),
            WidgetTree::label('run: ' . (($vm['run']['status'] ?? 'idle') ?: 'idle'), ['id' => 'run_status']),
        ]);
    });

    $app->run();
}
```

这段形态的几个要点：

- `run()` POST 的 `RunAgentInput` 是 JSON，`Content-Type: application/json`。
  `threadId` / `runId` / `messages` 必填 —— `SseClient::buildRunInput()` 缺键直接抛
  `InvalidArgumentException`，错误在开流之前就暴露。
- 桥接的 -1 **不抛异常**：记成结构化错误、可从 `status()['error']` 读 —— 应用走降级分支
  而不是崩。
- `viewModel()` 六个键：`run`（生命周期，含 `subagents` 记账）、`messages`（按序，文本
  delta 按 messageId 拼接）、`state`、`steps`、`activity`、`warnings`。工具调用是独立的表
  —— `session()->toolCalls()`（`toolCallId → [id, name, args, result]`，`args` 已拼接）。

::: warning 泵限制
SSE 帧只在 Qt 事件泵转的时候到达，而唯一的泵入口是窗口的 `processEvents`（16ms 嵌套
循环）。只要还有一个窗口活着，帧就流；**最后一个窗口销毁后，存活流永不再收到数据**
—— 无窗口的 CLI 式用法是 v1 范围外。流本身是**进程级资源**：关窗不丢未读帧，只是泵停摆。
:::

## 四个 SSE 原语

四个桥接函数（`php-src/qt.stub.php` 的「SSE 流式」分区）+ 四个 `QtApp` 转发方法。流**不挂
窗口句柄**，所以这四个方法都不带窗口参数。

| 桥接函数 | `QtApp` 方法 | 作用 |
|---|---|---|
| `qt_sse_open(array $spec): int` | `sseOpen(array $spec): int` | 异步开流，返回从 1 起的流 id。`$spec`：`url`（必填）/ `method`（缺省 GET；AG-UI run 用 POST）/ `headers` / `body` / `timeout_ms`（缺省 0 = 不设传输超时，流可长命） |
| `qt_sse_read(int $stream, int $max = 64): array` | `sseRead(int $stream, int $max = 64): array` | 一次取至多 $max 个**完整**帧。每帧 `['data' => string, 'event' => ?string, 'id' => ?string]`（`data` = 帧内 `data:` 行按 `\n` 拼接；`event`/`id` 键仅当帧里出现才带）。空数组 = 本轮没有新帧，不是错误 |
| `qt_sse_status(int $stream): array` | `sseStatus(int $stream): array` | `['state' => 'open' \| 'done' \| 'error' \| 'unknown', 'status' => int, 'error' => string]` |
| `qt_sse_close(int $stream): void` | `sseClose(int $stream): void` | abort + 释放，幂等（重复调用、未知 id 均无副作用） |

状态词表：

| `state` | 含义 |
|---|---|
| `open` | 请求进行中（生产方还没关响应体） |
| `done` | 生产方正常关闭了响应体 |
| `error` | **流存在**但请求失败 / 被 abort（`status` 0 = 连接级失败） |
| `unknown` | 流 id 不存在或已关闭 —— **含 `qt_sse_status(-1)`**（open 失败没有流）。`error` 只留给「流存在但请求失败」 |

真桥上 `qt_sse_open` 返回 **-1** 共三条：① `url` 空；② `QApplication` 尚未创建（惰性
单例 —— 只调过 `qt_app_create()`、还没建过窗口的应用，`QNetworkAccessManager` 依赖它）；
③ `QTPHP_NO_NETWORK` 构建。`FakeBridge` 没有 Qt / 网络概念：**-1 仅 `url` 空**；未预置时
合成一条空 `done` 流，行为完全由预置决定。

## 事件类型

`Decoder` 认识 AG-UI 1.0 的 31 个事件类型、8 族（常量表在 `src/AGUI/Decoder.php`）。
`META_EVENT` 在规范里还是 draft，不在常量表 —— 按未知类型透传，带 `'unknown' => true`。

| 族 | 类型 |
|---|---|
| 生命周期 | `RUN_STARTED` `RUN_FINISHED` `RUN_ERROR` `STEP_STARTED` `STEP_FINISHED` |
| 文本 | `TEXT_MESSAGE_START` `TEXT_MESSAGE_CONTENT` `TEXT_MESSAGE_END` `TEXT_MESSAGE_CHUNK` |
| 工具 | `TOOL_CALL_START` `TOOL_CALL_ARGS` `TOOL_CALL_END` `TOOL_CALL_RESULT` `TOOL_CALL_CHUNK` |
| 推理 | `REASONING_START` `REASONING_END` `REASONING_MESSAGE_START` `REASONING_MESSAGE_CONTENT` `REASONING_MESSAGE_END` `REASONING_MESSAGE_CHUNK` `REASONING_ENCRYPTED_VALUE` |
| 状态 | `STATE_SNAPSHOT` `STATE_DELTA` `MESSAGES_SNAPSHOT` |
| 活动 | `ACTIVITY_SNAPSHOT` `ACTIVITY_DELTA` |
| 子 agent | `SUBAGENT_STARTED` `SUBAGENT_FINISHED` `SUBAGENT_ERROR` |
| 透传 | `RAW` `CUSTOM` |

外加 0.x 退役的三个名字 `THINKING_TEXT_MESSAGE_START` / `CONTENT` / `END`：映射进
`REASONING_MESSAGE_*` 族 + 映射 warning（映射表名就是 `THINKING_TEXT_MESSAGE_*`，没有
`THINKING_MESSAGE_*` 这类名字）。

### 紧凑 chunk 与隐式 `END`

三个 `*_CHUNK` 是紧凑编码：消费方**先展开再校验 / 应用**（`TEXT_MESSAGE_CHUNK` →
`START`/`CONTENT`/`END`，工具调用与推理消息同）。`END` **由消费方合成** —— 生产方从不发送。
五个触发：

1. 同一 lane 换开不同 item（新 `messageId` / `toolCallId`）—— 先补发旧者的 `END`；
2. 该 lane 内到达 message / tool-call / step / state / custom / reasoning 族事件 —— 先关该
   lane 的打开项。**stand-aside 例外、不关任何项：`RAW` / `ACTIVITY_SNAPSHOT` /
   `ACTIVITY_DELTA` / `REASONING_ENCRYPTED_VALUE`；** `SUBAGENT_STARTED` 开新 lane、不动
   本 lane；
3. run 级事件 —— `RUN_STARTED` / `RUN_FINISHED` / `RUN_ERROR` / **`MESSAGES_SNAPSHOT`**
   —— 关**所有** lane；
4. 归属 subagent 终止（`SUBAGENT_FINISHED` / `SUBAGENT_ERROR`）关其 lane；
5. 流结束（`finish()`）。

**metadata-only** 的续接 chunk（无 delta、只报 usage / finish reason）合法且**不关闭**
任何东西。`SseClient` 每个 run 持**一个** `Decoder` 实例：展开状态跨 `poll()` 分批不丢；
流到 `done`/`error` 时调 `finish()`，挂起的隐式 `END` 随最后一批进 `Session`。

### 宽容默认（仓库兼容默认的同一族）

| 情形 | v1 行为 |
|---|---|
| 已打开消息再来 `START` | 先自动 `END` 再重开（warning） |
| 已关消息再来 `CONTENT` | 重开追加（warning） |
| `TOOL_CALL_CHUNK` 续接重复 `toolCallName` 且**值冲突** | 终止**该调用**展开 + warning，不中断整条流 |
| `THINKING_TEXT_MESSAGE_*` | 映射成 `REASONING_MESSAGE_*` + 映射 warning |
| 未知 `type` | 原样透传 + `'unknown' => true` |
| `RAW` / `CUSTOM` | 原样保留 + `'untrusted' => true` —— 不得由 `RAW` 推导协议行为，不得渲染 / 执行 |
| 帧不是合法 JSON | 记 warning 跳过，后续帧照常解 |

每一处降级都记进对应的 `warnings()` 数组（`Decoder::warnings()` /
`SseClient::warnings()` / `viewModel()['warnings']`）—— 测试里直接断言。
`QT_DEBUG_STRICT=1` 打印的是**渲染层**警告；AG-UI 层的警告走上面这些访问器读。

### 状态语义

- `STATE_SNAPSHOT` **整体替换**（绝不合并）。
- `STATE_DELTA` 是 RFC 6902 JSON-Patch 操作列表，由 `JsonPatch::apply()` 应用
  （`add` / `remove` / `replace` / `move` / `copy` / `test`）。格式合法但应用失败的 op
  记 warning 跳过、不中断流；漂移后请求一份新 snapshot 重新同步。
- `MESSAGES_SNAPSHOT` 是**全量历史替换、不是追加** —— 直接成为有序的 `messages`。

## 离线回放

协议层是纯 PHP，一条跑完的 run 可以从文件回放，不需要 Qt、不需要网络、不需要真实服务：

```php
use TypePHP\Qt\AGUI\Decoder;
use TypePHP\Qt\AGUI\Session;

$result = Decoder::decodeSse(file_get_contents('run-1.sse'));
// $result：['events' => 展开后的规范事件, 'warnings' => string[]]

$session = new Session();
foreach ($result['events'] as $event) {
    $session->consume($event);
}
$vm = $session->viewModel();
```

`Decoder::decodeSse()` 是一次性入口：把 SSE 文本分帧（空行分帧；`event:` / `id:` / `retry:`
行忽略 —— AG-UI 的事件类型在 JSON 的 `type` 字段里，不在 SSE 的 `event` 字段里），解码、
展开、finish。这是无头 / CI 友好的路径，也是测试入口（`tests/AGUI/` 用内联 fixture 覆盖
`JsonPatch` / `Decoder` / `Session`）。

### 离线回放示例与 `--bench`

`examples/agui-demo` 用内置 29 事件 fixture 演示整套支持 —— 零外部网络、无头可验收。
三种模式：

| 模式 | 作用 |
|---|---|
| 无参数 | GUI 主循环：窗口显示回放后的界面（消息列表 / 工具调用表 / 状态栏） |
| `--selftest` | 无头自检三段：① 纯 PHP 回放断言（fixture → 解码 → 展开 → 归约 → `viewModel`）② 控件树读回断言（`viewModel` → `WidgetTree` → `value()`）③ 桥接 smoke —— fixture 写临时文件、走**真实 `file://` SSE 流**（与网络 SSE 同一条 C++ 分帧路径），150 帧泵帧上限防死循环。全过 → `selftest passed` + rc=0；任一失败 → rc=1 |
| `--bench [N]` | 纯 PHP 回放 N 个事件（缺省 20000，fixture 循环复用；每批新建 `Decoder` + `Session` —— 测的是稳态吞吐而非一次性初始化），`hrtime` 计时。stdout 打三行后、在 **Qt 创建之前** exit(0)，计时不混入事件循环。供仓库吞吐基准脚本解析： |

```
METRIC agui_replay_events_per_s=<int>
METRIC agui_replay_total_ms=<float>
METRIC agui_bench_events=<int>
```

fixture 故意混入一条 legacy `THINKING_TEXT_MESSAGE_CONTENT` 与一条未知
`EXPERIMENTAL_EVENT` 测容错路径；`MESSAGES_SNAPSHOT`（全量替换）是「消息数」断言的
基准。

## 接进你的项目

- **配对规则**：`QtApp` 的 `sse*` 方法调用 `qt_sse_*` 原语，所以**凡是 `sources:` 里编了
  `src/QtApp.php` 的应用，就**必须**列 `cpp-src/qt_sse.cc`** —— 不只用到 `SseClient` 才要。
  （与 `qt_qml.cc` 静态表教训同族：那次是 C++ 表引用强制所有应用链接它；这次是 PHP 包装
  方法引用把同一要求带回了每个编译 `QtApp.php` 的应用。`examples/hello`、`qmlclock`、
  `taskboard` 在第一轮真机验收前都没列这一行，链接期报 undefined symbol。）`qtphp new`
  脚手架三个平台都已列 `qt_sse.cc` 并链 `Qt6Network`，新项目保留那一行即可。
- **`QTPHP_NO_NETWORK` 构建**（如 `examples/hello`）：`qt_sse.cc` 编成报错桩 —— 符号
  恒在、链接过；但 `open` → -1、`read` → `[]`、`status` → `unknown` + 诊断文本、
  `close` → 无操作。真要用 SSE 的应用去掉该定义并链上 `Qt6Network`。
- **测试**：`FakeBridge` 镜像四个函数；行为完全由预置驱动 ——
  `test_set_sse_stream(array $frames, int $status = 200, string $error = '')` 喂下一个
  open、`test_sse_requests()` 看 spec 轨迹、`test_sse_stream(int $id)` 查某条流。零真实
  网络、零 Qt。

## v1 范围外

- **WebSocket 与 HTTP+Protobuf 绑定** —— 只做 HTTP 上的 SSE。
- **live LLM 端点示例** —— 示例线以离线回放为主，真实模型端点接线未交付。
- **无窗口的 CLI 式用法** —— 见上面的泵限制。
- **`META_EVENT`** —— draft；按未知透传，不硬依赖。
- **新控件类型与可复用的 `AguiRenderer`** —— 渲染用你的本地控件树（消息列表 / 工具调用
  表 / 状态栏）。
- **示例的 CI 接线** —— `ci.yml` 显式枚举示例构建，新示例尚未进。
