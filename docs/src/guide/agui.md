# AG-UI

AG-UI is the Agent-User Interaction Protocol: an agent producer accepts one `RunAgentInput`
via HTTP POST and answers with a `text/event-stream` response whose frames are **typed JSON
events** — messages, tool calls, reasoning, state updates — that a consumer renders as they
arrive. TypePHP\Qt ships AG-UI support in two layers, and the rendering layer is your
existing widget tree:

| Layer | Where | What it does |
|---|---|---|
| **Protocol layer** (pure PHP) | `src/AGUI/`: `JsonPatch`, `Decoder`, `Session`, `SseClient` | Decode SSE frames, expand compact chunks, reduce events into a view model. No Qt, no network — unit-testable with `qtphp test` |
| **SSE primitives** (C++ bridge) | `cpp-src/qt_sse.cc` + the four `qt_sse_*` stubs + `QtApp::sse*` | An asynchronous stream: open once, poll frames from a per-stream buffer, read status, close |
| **Rendering** | your `view()` widget tree | Messages / tool calls / state bar built from `viewModel()` with ordinary controls — **v1 adds no new control type** |

The data path: `qt_sse_read()` pulls completed frames → each frame's `data` is one
`json_decode`-d event → `Decoder` expands compact chunks into the canonical events (synthesising
the implicit `END`s) → `Session` reduces them → `viewModel()` is the only entry the render layer
reads.

## Live streaming with `SseClient`

`SseClient` is the thin seam over the primitives: one run = one stream. The same class is used
against the real bridge and against `FakeBridge` in tests — there is no injection seam.

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

    // The only pump that delivers frames is the window event loop, so a timer pulls a
    // round every frame; poll() is what drives both expansion and reduction.
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

Notes on that shape:

- `run()` POSTs `RunAgentInput` (JSON) with `Content-Type: application/json`. `threadId` /
  `runId` / `messages` are required — `SseClient::buildRunInput()` throws
  `InvalidArgumentException` up front when one is missing, so the error surfaces before any
  stream is opened.
- A `-1` from the bridge is **not** an exception: it is recorded and readable through
  `status()['error']`, so the app degrades instead of crashing.
- `viewModel()` returns six keys: `run` (lifecycle, incl. `subagents` bookkeeping),
  `messages` (ordered, text deltas joined by message id), `state`, `steps`, `activity`,
  `warnings`. Tool calls are a separate table — `session()->toolCalls()`
  (`toolCallId → [id, name, args, result]`, `args` already joined).

::: warning The pump limit
SSE frames only arrive while Qt's event pump is running, and the only pump entry is the
window's `processEvents` (the 16 ms nested loop). With at least one window alive, frames flow;
**after the last window is destroyed, surviving streams never receive data again** —
window-less CLI-style use is out of scope for v1. Streams are still *process-level*
resources: closing a window does not lose unread frames, it just stops the pump.
:::

## The four SSE primitives

Four bridge functions (`php-src/qt.stub.php`, section 「SSE 流式」) and four `QtApp` methods that
forward them. Streams are **not** attached to a window handle, so the methods do not take one.

| Bridge function | `QtApp` method | What it does |
|---|---|---|
| `qt_sse_open(array $spec): int` | `sseOpen(array $spec): int` | Open a stream asynchronously, returns a stream id starting at 1. `$spec`: `url` (required), `method` (default GET; an AG-UI run POSTs), `headers`, `body`, `timeout_ms` (default 0 = no transport timeout — streams may be long-lived) |
| `qt_sse_read(int $stream, int $max = 64): array` | `sseRead(int $stream, int $max = 64): array` | Take up to `$max` **complete** frames. Each frame: `['data' => string, 'event' => ?string, 'id' => ?string]` (`data` = the frame's `data:` lines joined by `\n`; `event`/`id` keys only present when the frame carries them). Empty array = no new frames this round, not an error |
| `qt_sse_status(int $stream): array` | `sseStatus(int $stream): array` | `['state' => 'open' \| 'done' \| 'error' \| 'unknown', 'status' => int, 'error' => string]` |
| `qt_sse_close(int $stream): void` | `sseClose(int $stream): void` | Abort and release. Idempotent: repeat calls and unknown ids are no-ops |

State words, one table:

| `state` | Meaning |
|---|---|
| `open` | the request is in flight (the producer has not closed the response body) |
| `done` | the producer closed the response body normally |
| `error` | the stream **exists** but the request failed / was aborted (`status` 0 = connection-level failure) |
| `unknown` | no such stream — **including `qt_sse_status(-1)`** after a failed open. `error` is reserved for "stream exists, request failed" |

`qt_sse_open` returns **-1** under three conditions on the real bridge: ① empty `url`;
② `QApplication` not yet created (the lazy singleton — an app that only called
`qt_app_create()` but has not created a window yet has no `QNetworkAccessManager`);
③ a `QTPHP_NO_NETWORK` build. `FakeBridge` has no Qt/network concepts: **-1 only when `url`
is empty**; with nothing preset it synthesises an empty `done` stream, so the test controls
the behaviour.

## Event types

`Decoder` knows the 31 event types of AG-UI 1.0 in 8 families (the constants at
`src/AGUI/Decoder.php`). `META_EVENT` is still a draft in the spec, so it is not in the
constant table — it arrives as an unknown type and passes through with `'unknown' => true`.

| Family | Types |
|---|---|
| Lifecycle | `RUN_STARTED` `RUN_FINISHED` `RUN_ERROR` `STEP_STARTED` `STEP_FINISHED` |
| Text | `TEXT_MESSAGE_START` `TEXT_MESSAGE_CONTENT` `TEXT_MESSAGE_END` `TEXT_MESSAGE_CHUNK` |
| Tool | `TOOL_CALL_START` `TOOL_CALL_ARGS` `TOOL_CALL_END` `TOOL_CALL_RESULT` `TOOL_CALL_CHUNK` |
| Reasoning | `REASONING_START` `REASONING_END` `REASONING_MESSAGE_START` `REASONING_MESSAGE_CONTENT` `REASONING_MESSAGE_END` `REASONING_MESSAGE_CHUNK` `REASONING_ENCRYPTED_VALUE` |
| State | `STATE_SNAPSHOT` `STATE_DELTA` `MESSAGES_SNAPSHOT` |
| Activity | `ACTIVITY_SNAPSHOT` `ACTIVITY_DELTA` |
| Subagents | `SUBAGENT_STARTED` `SUBAGENT_FINISHED` `SUBAGENT_ERROR` |
| Passthrough | `RAW` `CUSTOM` |

Plus the retired 0.x names `THINKING_TEXT_MESSAGE_START` / `CONTENT` / `END`, which are mapped
into the `REASONING_MESSAGE_*` family with a mapping warning (the mapping table's names are
`THINKING_TEXT_MESSAGE_*` — there is no `THINKING_MESSAGE_*`).

### Compact chunks and implicit `END`

The three `*_CHUNK` types are a compact encoding: the consumer **expands them before
validating or applying** (`TEXT_MESSAGE_CHUNK` → `START`/`CONTENT`/`END`, same for tool calls
and reasoning messages). The `END` is **synthesised by the consumer** — producers never send
it. It is flushed on five triggers:

1. the same lane switches to a new item (new `messageId` / `toolCallId`) — the previous one's `END` is emitted first;
2. any message / tool-call / step / state / custom / reasoning-family event lands in the lane — the lane's open item closes first. **Stand-aside exceptions that close nothing: `RAW`, `ACTIVITY_SNAPSHOT`, `ACTIVITY_DELTA`, `REASONING_ENCRYPTED_VALUE`;** `SUBAGENT_STARTED` opens a new lane without touching this one;
3. a run-level event — `RUN_STARTED` / `RUN_FINISHED` / `RUN_ERROR` / **`MESSAGES_SNAPSHOT`** — closes *all* lanes;
4. the owning subagent terminates (`SUBAGENT_FINISHED` / `SUBAGENT_ERROR`) closes its lane;
5. the stream ends (`finish()`).

A **metadata-only** continuation chunk (no delta, carries usage / finish reason) is legal and
closes nothing. `SseClient` holds **one `Decoder` instance per run**, so state does not leak
across `poll()` batches, and when the stream reaches `done`/`error` the client calls
`finish()` and the pending implicit `END`s ride into the `Session` with the last batch.

### Tolerant by default (the repo's compatibility default)

| Situation | v1 behaviour |
|---|---|
| `START` on an already-open message | auto-`END` it, reopen (warning) |
| `CONTENT` on a closed message | reopen and append (warning) |
| `TOOL_CALL_CHUNK` continuation repeats `toolCallName` with a **conflicting** value | stop expanding *that call* + warning — the stream is not aborted |
| `THINKING_TEXT_MESSAGE_*` | mapped to `REASONING_MESSAGE_*` + mapping warning |
| unknown `type` | passed through with `'unknown' => true` |
| `RAW` / `CUSTOM` | kept as-is with `'untrusted' => true` — the consumer MUST NOT derive protocol behaviour from `RAW`, and must not render or execute it |
| a frame that is not valid JSON | skipped with a warning; later frames still decode |

Every degradation is recorded in the relevant `warnings()` array (`Decoder::warnings()`,
`SseClient::warnings()`, `viewModel()['warnings']`) — assert them in tests. `QT_DEBUG_STRICT=1`
prints the *rendering*-layer warnings; the AG-UI layer's warnings are read through the
accessors above.

### State semantics

- `STATE_SNAPSHOT` **replaces** the whole state (never merged).
- `STATE_DELTA` is a RFC 6902 JSON-Patch op list, applied by `JsonPatch::apply()`
  (`add` / `remove` / `replace` / `move` / `copy` / `test`). An op that is well-formed but
  fails is skipped with a warning and the stream continues; after drift, request a fresh
  snapshot to re-synchronise.
- `MESSAGES_SNAPSHOT` is a **full-history replacement, not an append** — it becomes the
  ordered message list.

## Offline replay

The whole protocol layer is pure PHP, so a finished run can be replayed from a file with no
Qt, no network, no real server:

```php
use TypePHP\Qt\AGUI\Decoder;
use TypePHP\Qt\AGUI\Session;

$result = Decoder::decodeSse(file_get_contents('run-1.sse'));
// $result: ['events' => expanded canonical events, 'warnings' => string[]]

$session = new Session();
foreach ($result['events'] as $event) {
    $session->consume($event);
}
$vm = $session->viewModel();
```

`Decoder::decodeSse()` is the one-shot entry: it splits the SSE text into frames (blank line
terminates a frame; `event:` / `id:` / `retry:` lines are ignored — AG-UI puts the event type
in the JSON `type` field, not in SSE's `event` field), decodes, expands, and finishes. This
is the headless/CI-friendly path and the test entry (`tests/AGUI/` covers `JsonPatch`,
`Decoder`, `Session` on inline fixtures).

### The offline replay example and `--bench`

`examples/agui-demo` exercises this stack on a built-in 29-event fixture — zero external
network, headless-verifiable. Three modes:

| Mode | What it does |
|---|---|
| no flags | GUI main loop: the window shows the replayed run (message list / tool-call table / status bar) |
| `--selftest` | Headless self-check, three stages: ① pure-PHP replay assertions (fixture → decode → expand → reduce → `viewModel`); ② widget read-back assertions (`viewModel` → `WidgetTree` → `value()`); ③ bridge smoke — the fixture is written to a temp file and replayed through a **real `file://` SSE stream** (same C++ frame-splitting path as network SSE), pumped with a 150-frame cap. All pass → `selftest passed` + rc 0; any failure → rc 1 |
| `--bench [N]` | Pure-PHP replay of N events (default 20 000, the fixture looped; a fresh `Decoder` + `Session` per batch so the steady state — not one-off setup — is measured), timed with `hrtime`. Prints three lines to stdout and exits **before Qt is created**, so no event loop enters the timing. Consumed by the repo's throughput benchmark harness: |

```
METRIC agui_replay_events_per_s=<int>
METRIC agui_replay_total_ms=<float>
METRIC agui_bench_events=<int>
```

The fixture deliberately mixes in a legacy `THINKING_TEXT_MESSAGE_CONTENT` and an unknown
`EXPERIMENTAL_EVENT` to exercise the tolerance paths, and `MESSAGES_SNAPSHOT` (full
replacement) is the basis for the message-count assertion.

## Wiring it into your project

- **Pairing rule:** `QtApp`'s `sse*` methods call the `qt_sse_*` primitives, so **any app
  whose `sources:` include `src/QtApp.php` must also list `cpp-src/qt_sse.cc`** — not only
  apps that adopt `SseClient`. (Same family of lesson as `qt_qml.cc`'s static table: that one
  forced every app to link the file, and the SSE wrapper made it true for the primitives too.
  `examples/hello`, `qmlclock`, `taskboard` shipped without the line until Phase 42's first
  acceptance build hit the undefined symbols.) The `qtphp new` scaffold already lists
  `qt_sse.cc` and links `Qt6Network` on all three platforms, so new projects just keep the
  line.
- **`QTPHP_NO_NETWORK` builds** (e.g. `examples/hello`): `qt_sse.cc` compiles to error
  stubs — the symbols exist so the link passes, but `open` → -1, `read` → `[]`,
  `status` → `unknown` with a diagnostic, `close` → no-op. Apps that genuinely need SSE must
  drop the define and link `Qt6Network`.
- **Testing:** `FakeBridge` mirrors the four functions; behaviour is fully preset-driven —
  `test_set_sse_stream(array $frames, int $status = 200, string $error = '')` feeds the next
  open, `test_sse_requests()` records the spec trail, `test_sse_stream(int $id)` inspects a
  stream. No real network, no Qt.

## Out of scope for v1

- **WebSocket and the HTTP+Protobuf binding** — only SSE over HTTP.
- **Live LLM endpoint examples** — the example line leads with offline replay; wiring a real
  model endpoint is not shipped.
- **Window-less CLI usage** — the pump limit above.
- **`META_EVENT`** — draft; passed through as unknown, not depended on.
- **New control types and a reusable `AguiRenderer`** — rendering is your local widget tree
  (message list / tool-call table / status bar).
- **CI wiring for the example** — `ci.yml` enumerates its example builds explicitly; the
  new one is not in yet.
