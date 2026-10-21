# Performance

This page is about the three things that actually decide whether a TypePHP\Qt app feels instant or feels sticky. Everything below is measured against the real engine (`cpp-src/qt_render.cc`), not guessed.

**If you are building a small form, you do not need this page.** The defaults are fine up to a few hundred widgets. Read it when a list grows long, a page starts flickering, or the frame budget is visibly being eaten.

## What happens in one frame

The loop is driven from PHP, so a frame is:

```
pollEvents()  →  dispatch handlers  →  your view() builder runs  →  qt_window_render(tree)
                                                                        │
                          C++: walk tree → per-node signature → diff against last frame
                               → apply only changed properties → Qt lays out / repaints
```

PHP owns steps 1–3. Qt owns the last one. Your lever is how much work steps 2–3 do and how much of the tree actually *changed* — not how fast Qt draws.

Frame pacing is a fixed 16 ms tick (`QTimer::singleShot(16ms)`), i.e. the builder is called ~60 times per second, forever. Anything you put in the builder is a per-frame cost, not a one-time cost.

## Rule 1 — every dynamic child needs an explicit `id`

This is not a style preference; it decides whether a node is updated or rebuilt.

A node without `id` gets the **structural path** (`_p0.1.2`) as its identity, and that path is only stable *within a frame*. Insert one row near the top of a list and every following node shifts: the previous `_p0.1.3` is now at `_p0.1.4`. The engine cannot know that the widget merely moved, so it sees "delete the old one, create a new one".

The symptoms, in the order they usually show up:

- input cursor jumps to the start (or out of the field) while typing;
- table selection resets to nothing after a refresh;
- scroll position returns to the top;
- on long pages, **blank gaps and flicker** — the widgets that were not re-created in the same layout pass fall out of place.

So: children of a `table`, `tree`, `list`, `tabs`/`stack` page, or any conditional branch must carry an explicit `id`.

```php
// correct: identity travels with the task, not with its position
foreach ($state['tasks'] as $task) {
    $rows[] = [
        'id'     => 'task_' . $task['id'],
        'cells'  => [$task['id'], $task['title'], $task['status']],
    ];
}
```

## Rule 2 — keep the builder free of expensive work

The builder runs 60 times per second. A 5 ms `usort()` over 2 000 rows inside it is 300 ms of CPU per second spent re-deciding the same thing.

The shape that works:

- **Handlers and timers change state.** Filtering, sorting, parsing, file reads, HTTP — all of it. Once.
- **The builder formats state.** It maps state to widgets and does nothing else.
- Need live data? Refresh it on a timer, not per frame:

```php
$app->setTimer('refresh', 1000);            // 1 Hz, not 60 Hz
$app->on('refresh', 'timer', function () use (&$state) {
    $state['tasks'] = loadTasks();          // the expensive part, once per second
});
```

`setTimer($id, $intervalMs)` re-arms on every call and stops when the interval is ≤ 0, so toggling a refresh rate is just another state change.

## Rule 3 — the signature is computed per node, per frame

Before touching Qt, the bridge computes a hash signature for every node and every property, and skips whatever has not changed (`qtSignature()` — a hash, not a concatenated string, precisely so this stays cheap).

That is why updating one line in a 5 000-row table only repaints one row. But it also means the `rows` / `nodes` payload is **walked and hashed every frame even when nothing changed**. Thousands of rows is a per-frame cost in both PHP (building the array) and C++ (hashing it).

For hot, append-only data — logs, progress, streaming rows — use `patch()` instead of describing the whole table each frame:

```php
$app->patch([
    ['op' => 'call', 'id' => 'log', 'method' => 'appendRows',
        'args' => [[['10:32', '启动'], ['10:33', '就绪']], ['l1', 'l2']]],
]);
```

`call` is an imperative side channel: it mutates the widget without changing your tree, and the bridge invalidates the affected signatures so the next `render()` re-syncs from the tree. Practical consequence: rows appended this way live only until the next frame **unless you also push them into state**. Use it when the state change is the same one that produced the rows — then the two agree.

The available `call` methods are listed in the README's "incremental patch" table, and `set` in the
same call applies ordinary declarative properties.

## Measuring instead of guessing

Set `QT_DEBUG_TIMING=1` and each frame prints its render cost to stderr:

```bash
QT_DEBUG_TIMING=1 ./build/hello          # Windows: $env:QT_DEBUG_TIMING="1"
```

Read it as: *the time the C++ diff + apply took*. If that number is small but the app still feels slow, your cost is in the PHP builder, not the bridge — instrument the builder with `hrtime(true)` around the expensive part.

Set `QT_DEBUG_STRICT=1` while developing. Unknown widget types, unknown properties and unknown `patch` methods are silently ignored by design (that is what keeps old apps building against new bridge versions), so a typo in a property name just... does nothing. Strict mode turns each of those into a warning on stderr:

```bash
QT_DEBUG_STRICT=1 ./build/hello
```

## Startup, binary size and packaging

Three separate budgets, three separate levers:

| Budget | Lever | Notes |
|---|---|---|
| Startup | nothing to configure | The bridge is compiled into your app; there is no runtime to initialise beyond QApplication |
| Binary size | `qtphp build --nano` | 4.3 MB vs 25.6 MB on the reference example; `php-nano` + PHPX sources are compiled in, no PHP runtime is linked |
| Packaging size | `qtphp package` (default) | `--no-opengl-sw` drops the 20 MB software-rendering fallback from `dist/` |

Do **not** skip the `tls` / `networkinformation` Qt plugins when trimming a package: `qt_http_request()` goes through `QNetworkAccessManager`, and without `tls` every HTTPS request fails.

## A checklist

- [ ] Every child inside a `table` / `tree` / `list` / conditional branch has an explicit `id`.
- [ ] The `view()` builder does no I/O, no network, no sorting of large collections.
- [ ] Anything that grows without bound uses `patch(['op' => 'call', …])` or a paged state, not a full re-description per frame.
- [ ] Timers are registered at the rate the data actually changes, not at frame rate.
- [ ] `QT_DEBUG_TIMING=1` was run at least once on the slow screen.
- [ ] `QT_DEBUG_STRICT=1` produces no warnings during a normal session.
