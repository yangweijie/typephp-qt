# A2UI

[A2UI](https://github.com/a2ui-project/a2ui) (Agent-to-User Interface, v1.0) is the protocol
where an agent sends **declarative JSON** describing UI intent and the client renders it with
its own native component catalog. TypePHP\Qt implements the renderer side as a **pure PHP
layer + a mapping to the existing widget tree** — zero new bridge functions (the transport
reuses the existing `qt_sse_*` primitives; AG-UI is one of A2UI's standard transport bindings,
so the two stack).

| Layer | Where | What it does |
|---|---|---|
| **DataModel** | `src/A2UI/DataModel.php` | JSON Pointer (RFC 6901) get / upsert / delete; `updateDataModel`'s three semantics (exists → update, missing → create, `value: null` → delete; missing or `/` path = whole-model replace) |
| **Scope + Evaluator** | `src/A2UI/Scope.php`, `Evaluator.php` | Path-resolution scope chain (template items open a Collection Scope; relative paths resolve against the current item); `@path` / `@call` / literal bindings, the DynamicBoolean coercion table, `formatString` `${...}` interpolation, the v1 function subset (`required` / `email` / `regex` / `length` / `numeric` / `and` / `or` / `not` + `@index`) |
| **Catalog** | `src/A2UI/Catalog.php` | The 18 Basic Catalog components mapped to widget-tree types (13 native, 5 placeholder labels); UAX #31 id validation |
| **Renderer + Outbound** | `src/A2UI/Renderer.php`, `Outbound.php` | The six Agent→Renderer envelopes in, the widget tree out; `buildAction()` produces the Renderer→Agent `action` envelope; rendererFunctionResponse / error receipts |

The mapping keeps everything in your existing widget tree:

| A2UI component | WidgetTree | Note |
|---|---|---|
| Text / Row / Column | `label` / `hbox` / `vbox` | |
| List | `scroll(vbox)` | template `children: {path, componentId}` iterates a model array |
| Card / Tabs / Divider | `group` / `tabs+tab` / `separator` | |
| Button | `button` | label from the child Text; `action.event` → event binding |
| CheckBox / TextField / Slider / ChoicePicker | `checkbox` / `lineEdit` / `slider` / `combo` | two-way binding writes back to the local model |
| Image | `image` | |
| Icon / Video / AudioPlayer / Modal / DateTimeInput | placeholder `label` | never a silent blank — the renderer has no native control, and the spec's progressive rendering allows a stand-in |

## The message flow

```php
use TypePHP\Qt\A2UI\Renderer;

$renderer = new Renderer();
$renderer->consume($envelope);          // one Agent→Renderer envelope per call
$renderer->consume($anotherEnvelope);   // createSurface / updateComponents / updateDataModel /
                                        // deleteSurface / callRendererFunction / agentFunctionResponse

$out = $renderer->toWidgetTree('team'); // ['tree' => WidgetTree node, 'events' => bindings]
$app->view(fn (): array => $out['tree']);
```

- `createSurface` twice with the same `surfaceId` is an **INTEGRITY_ERROR** — the renderer
  queues an `error` envelope in its outbox (`takeOutbound()`).
- A component `id` must match UAX #31 (`user_card` / `userCard` legal; `user-card` / `1st_item`
  rejected). Missing references are skipped (progressive rendering); components buffer until a
  `root` component is defined, and until then `toWidgetTree()` returns a placeholder label.
- Template instances get the widget id suffix `__<index>` (`card__0`, `who__1`) — stable across
  frames, so the diff engine reuses the widgets and input state survives re-renders. Nested
  templates stack the suffix.

## Two-way binding and actions

A bound input writes back to the **local** data model and never sends anything on its own:

```php
// user typed into the TextField bound to /email
$renderer->applyInput('team', 'field', 'jane@acme.dev');

// user clicked the Button whose action.event name is submit_roster
$action = $renderer->buildAction('team', 'go');
// ['version' => '1.0', 'action' => ['name', 'surfaceId', 'sourceComponentId',
//  'timestamp' (ISO 8601 UTC), 'context' => resolved bindings, 'userMessage'?]]
```

`context` and `userMessage` are resolved **at dispatch time**, not at render time — the
binding's scope snapshot (which template item the button belongs to) is rebuilt first, so a
relative `@path` lands on the right item, and the freshly written-back input value is what the
agent receives. That is the spec's bind → interact → action → send form pattern.

`callRendererFunction` is always answered: v1 registers no renderer functions, so every call
gets a `rendererFunctionResponse` with `INVALID_FUNCTION_CALL` (the spec requires the receipt
even for a `void` function).

## Offline replay example

`examples/a2ui-demo` runs the whole stack on a built-in envelope fixture — zero external
network, headless-verifiable. Three modes:

| Mode | What it does |
|---|---|
| no flags | GUI main loop: click "提交名单" and the generated `action` envelope is shown in the window |
| `--selftest` | Headless self-check, four stages: ① envelope → `Renderer` → widget-tree assertions (template iteration, relative paths, `@index`, upserts); ② widget read-back (`value()`); ③ `applyInput` → `buildAction` dispatch assertions; ④ bridge smoke — the fixture is replayed through a **real `file://` SSE stream** (A2UI-over-SSE form) and consumed frame by frame, 150-frame cap. All pass → `selftest passed` + rc 0; any failure → rc 1 |
| `--bench [N]` | Pure-PHP replay of N envelopes (default 20 000), timed with `hrtime`, exits **before Qt is created**: |

```
METRIC a2ui_replay_messages_per_s=<int>
METRIC a2ui_replay_total_ms=<float>
METRIC a2ui_bench_messages=<int>
```

## Tolerance defaults

| Situation | v1 behaviour |
|---|---|
| unknown envelope key (outside the six) | warning + the whole message is ignored |
| missing `value` on `updateDataModel` | rejected — a malformed message must not be executed as a delete |
| unregistered catalog function (e.g. `formatDate`) | resolves to `null` + warning |
| `@index` in Root Scope | `null` + warning (spec: must be treated as an error) |
| unsupported / unknown component | placeholder label, not a blank |
| unrecognised single-`@` key | `null` + warning (`@@key` is the literal-`@` escape) |

Every degradation is collected through `Renderer::takeWarnings()` / `Evaluator::takeWarnings()`
— assert them in tests.

## Out of scope for v1

- The `@checks` validation engine and the `formatNumber` / `formatCurrency` / `formatDate` /
  `pluralize` / `openUrl` catalog functions.
- `callAgentFunction` RPC in the renderer→agent direction (the renderer never initiates).
- JSON Schema online validation, inline catalogs, extensions deep-consumption.
- A2A / MCP transport bindings — bring your own transport; `qt_sse_*` + AG-UI covers the SSE
  binding.
