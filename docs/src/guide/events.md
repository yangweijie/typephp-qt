# Events and Handlers

## Registering

```php
// by id + type
$app->on('greet_btn', 'click', function () use (&$state) { ... });

// for events with no id (a tray click, for example) — type only
$app->onAny('tray', function () use (&$state) { ... });
```

## Handler signatures

A handler may declare **only the parameters it needs**:

```php
$app->on('btn', 'click', function () { ... });                 // no event
$app->on('input', 'change', function (array $event) { ... });  // wants the event
```

The framework probes the required parameter count **at registration time** and calls the handler with exactly that many arguments.

::: warning This is not an optional nicety
AOT-compiled closures validate argument count **exactly** and throw `ArgumentCountError` on an extra argument; the plain PHP interpreter silently ignores extras. So a `function () {}` handler is perfectly fine under the interpreter and **crashes the moment you click that button** in the compiled binary.

The framework already handles this for you (it probes arity at registration), but keep it in mind for **your own** callbacks. Details in [AOT Notes](/advanced/aot-notes.md#closure-arity-is-validated-exactly).
:::

## Event types

| Event | Emitted by | `value` | `payload` |
|---|---|---|---|
| `click` | button, link | the link's href | — |
| `change` | lineedit, textedit, spin, doublespin, slider, combo | the new value | `index` for combo |
| `submit` | lineedit | the text | — |
| `toggle` | checkbox, radio | `'0'` / `'1'` | — |
| `select` | list, table, tree | row / item id | `index` |
| `activate` | list, table, tree | row / item id | — |
| `tab` | tabs, stack | the index | `index` |
| `menu` | menu item | — | `checked` |
| `timer` | timer | — | — |
| `tray` | system tray | — | — |

An event is an associative array:

```php
[
    'type'    => 'click',        // event type
    'id'      => 'greet_btn',    // widget id
    'value'   => '...',          // new value / row id (depends on the type)
    'payload' => ['index' => 2], // extra structure (optional)
]
```

## Worked examples

### Buttons

```php
$app->on('save_btn', 'click', function () use ($app, $state) {
    $state->save();
});
```

### Text input — live vs. Enter

```php
// fires on every keystroke
$app->on('search_input', 'change', function (array $event) use ($state) {
    $state->query = (string) $event['value'];
});

// fires only on Enter
$app->on('search_input', 'submit', function (array $event) use ($state) {
    $state->query = (string) $event['value'];
    $state->search();
});
```

### Checkboxes / radios

```php
$app->on('dark_toggle', 'toggle', function (array $event) use ($state) {
    $state->dark = $event['value'] === '1';   // note: a string
});
```

### Table / list selection

```php
$app->on('task_table', 'select', function (array $event) use ($state) {
    $state->selectedId = (string) $event['value'];   // the row id
});

$app->on('task_table', 'activate', function (array $event) use ($state) {
    $state->open((string) $event['value']);          // double click
});
```

### Combo boxes

```php
$app->on('theme_combo', 'change', function (array $event) use ($state) {
    $state->theme = (string) $event['value'];               // the text
    $state->themeIndex = (int) $event['payload']['index'];  // the index
});
```

### Tabs

```php
$app->on('main_tabs', 'tab', function (array $event) use ($state) {
    $state->activeTab = (int) $event['payload']['index'];
});
```

### Menus

```php
$app->on('menu.about', 'menu', function () use ($app) {
    $app->alert('TypePHP\Qt example');
});

$app->on('menu.wrap', 'menu', function (array $event) use ($state) {
    $state->wrap = (bool) ($event['payload']['checked'] ?? false);
});
```

### Timers

```php
$app->setTimer('clock', 1000);        // every 1000ms; interval <= 0 stops it

$app->on('clock', 'timer', function () use ($state) {
    $state->ticks++;
});
```

### Tray

A tray left-click emits an event with **no id**, so it can only be caught with `onAny`:

```php
$app->onAny('tray', function () use ($state) {
    $state->trayClicks++;
});
```

## Wildcards: `onAny`

```php
$app->onAny('click', function (array $event) use ($state) {
    $state->lastClicked = $event['id'];   // every button's click lands here
});
```

`on($id, $type, …)` and `onAny($type, …)` may both be registered, and **both will fire**.

## What a handler may do

Only change state. Do not manipulate widgets directly — that is the declarative view's job.

A few imperative APIs are the exception (they are not in the widget tree, so they skip the diff):

```php
$app->setTitle('New title');
$app->setStatus(['Ready', '3 items']);
$app->setMenu([...]);
$app->setTray([...]);
$app->setTimer('id', 500);
$app->resize(800, 600);
```

## Error handling

A handler that throws does not kill the process. The framework catches it, shows an error box, records it in `lastError()`, and the loop continues:

```php
$app->on('risky', 'click', function () {
    throw new RuntimeException('file not found');
});

// afterwards
$app->lastError();   // 'file not found @ /path/main.php:42'
```

In headless mode (`headless(true)`) no box is shown — it is only recorded, so `--selftest` can run to completion and report which case failed.
