# Multiple Windows, Tray and Timers

## One QtApp = one window

A secondary window is just another `new QtApp()` plus `createWindow()`:

```php
$log = new QtApp();
$log->createWindow('Run log', ['width' => 560, 'height' => 300]);
$log->render(WidgetTree::vbox([
    WidgetTree::table(['Time', 'Event'], [], ['id' => 'log_tbl', 'stretch_last' => true]),
]));
```

`qt_app_create` is **idempotent** — there is only ever one `QApplication`, so calling it repeatedly is safe.

## But `run()` only pumps its own window

This is the easiest thing to trip over: `$app->run()` only handles events for `$app`'s own window. Multiple windows must be **pumped in turn, frame by frame**:

```php
while ($app->isOpen()) {
    $app->runFrames(1);                       // main window: pump + dispatch + re-render

    $log = $state['log'];
    if ($log instanceof QtApp) {
        if ($log->isOpen()) {
            $log->runFrames(1);               // secondary window
        } else {
            $log->destroy();                  // it closed — drop it, don't hoard zombie windows
            $state['log'] = null;
        }
    }
}
```

Points to note:

- Use `runFrames(1)`, not `run()` — the latter blocks until the window closes.
- **Destroy a closed window and remove it from state**, or you pump a dead window every frame.
- The loop ends when the main window closes (`isOpen()` becomes false).

## Full example: a main window plus a log window

```php
$state = ['log' => null, 'logLines' => []];

// a button in the main window opens the log window
$app->on('open_log_btn', 'click', function () use (&$state) {
    if ($state['log'] instanceof QtApp) {
        return;                                   // already open
    }

    $log = new QtApp();
    $log->createWindow('Run log', ['width' => 560, 'height' => 300]);
    $log->render(WidgetTree::vbox([
        WidgetTree::table(['Time', 'Event'], [], ['id' => 'log_tbl', 'stretch_last' => true]),
        WidgetTree::hbox([
            WidgetTree::button('Close log', ['id' => 'close_log_btn']),
            WidgetTree::spacer(1),
        ]),
    ]));
    $log->on('close_log_btn', 'click', function () use (&$state) {
        if ($state['log'] instanceof QtApp) {
            $state['log']->close();               // request close; isOpen() goes false next frame
        }
    });

    $state['log'] = $log;
    $log->runFrames(1);                           // pump one frame so the widgets exist
});
```

::: tip Pump one frame first
`patch()` targets widgets that **already exist**. Without pumping one frame after creating a window, the first `patch` is dropped because the widget has not been built yet.
:::

## Appending rows to the log window

Use `patch()` for the imperative bypass (a log stream is a hot path; rebuilding the whole tree is wasteful):

```php
function log_append(QtApp $log, string $line): void
{
    $log->patch([
        ['op' => 'call', 'id' => 'log_tbl', 'method' => 'appendRows',
         'args' => [[[date('H:i:s'), $line]]]],
    ]);
}
```

**Careful**: rows appended by `patch` only survive until the next `render()` — a re-render rebuilds the table from the widget tree. So for append-only data like a log, either write it back into state (and generate the full row list in `view()`), or accept that it gets cleared on re-render.

The example app keeps the log lines in state and builds the table in `view()` — that way a re-render does not lose them:

```php
$app->view(function () use (&$state): array {
    return WidgetTree::table(
        ['Time', 'Event'],
        $state['logLines'],                       // data comes from state
        ['id' => 'log_tbl', 'stretch_last' => true]
    );
});
```

## Timers

```php
$app->setTimer('clock', 1000);      // every 1000ms
$app->setTimer('clock', 0);         // stop

$app->on('clock', 'timer', function () use (&$state) {
    $state['ticks']++;
});
```

Register timers **after** all handlers (before `run()`); they only start ticking inside `run()`.

## Tray apps: two assumptions invert

A tray app differs structurally from a normal window app in two ways:

**(1) Closing the window must not quit the process.**

```cpp
// needs to be set in the bridge: QApplication::setQuitOnLastWindowClosed(false)
```

Otherwise the process dies the moment the window hides, and the tray icon vanishes with it.

**(2) The loop condition is "still alive", not "window open".**

A tray app has no visible window most of the time, so `while ($app->isOpen())` exits the first time the window hides. It needs a liveness flag instead.

::: warning Current state of the package
The package treats the tray as an **add-on to a window app** (`setTray` plus `onAny('tray', …)`), and the main loop is still `while (isOpen())`.

To write a **pure tray app** (no main window, lives in the background), you need to hand-write the bridge and change those two things — see [Hand-written Bridge](/advanced/bridge.md) and the `references/system-tray.md` in the skill.
:::

## Headless verification with multiple windows

A multi-window app can still run `--selftest`:

```php
// remember to destroy the secondary window too, at the end of the check
if ($state['log'] instanceof QtApp) {
    $state['log']->destroy();
}
$app->destroy();
```
