# Dialogs and System Integration

## Message boxes

```php
$app->alert('Done');                            // information
$app->error('Save failed: disk full');          // error
$app->confirm('Delete this item?');             // returns bool
$app->message(['type' => 'info', 'default' => 'ok']);   // low-level; returns the pressed button
```

```php
$app->on('del_btn', 'click', function () use ($app, $state) {
    if ($app->confirm('Delete "' . $state->currentTitle . '"?')) {
        $state->deleteCurrent();
    }
});
```

::: warning A modal dialog blocks forever with no user
`confirm()` / `alert()` sit on top of `QMessageBox::exec()`, which **spins a nested event loop** until someone clicks. In CI or `--selftest` — where there is no one — that hangs the process forever.

Use `headless(true)` to bypass it: message boxes return their `default` without touching the modal API:

```php
$app->headless(true);
$app->confirm('Sure?');   // returns false (or the spec's default) immediately, no dialog
```

`--selftest` turns on headless mode itself. See [Headless Verification](/advanced/headless.md).
:::

## File dialogs

```php
// open: returns ['path' => '...', 'name' => '...'], or [] when cancelled
$picked = $app->openFile('Pick an image', 'Images (*.png *.jpg)');
if ($picked !== []) {
    $state->imagePath = $picked['path'];
}

// save
$target = $app->saveFile('Save as', 'Text (*.txt)', 'untitled.txt');

// pick a directory
$dir = $app->pickDirectory('Choose an output folder');
```

The filter syntax is Qt's: `'Description (*.ext *.ext2)'`, with multiple filters separated by `;;`.

```php
$picked = $app->openFile('Open', 'Text (*.txt);;All files (*)');
```

Headless, all three return empty (`[]` or `''`) instead of blocking.

## Clipboard

```php
$app->clipboardWrite('text to copy');
$text = $app->clipboardRead();
```

```php
$app->on('copy_btn', 'click', function () use ($app, $state) {
    $app->clipboardWrite($state->currentBody);
    $app->setStatus(['Copied to clipboard']);
});
```

## System notifications

```php
$app->notify('Build finished', 'Took 12.3s');
```

When no tray is available, Qt falls back to showing `showMessage` as a **modal message box** — so `notify()` is also guarded by `headless`, and returns immediately in headless mode.

## Window title and size

```php
$app->setTitle('Project — modified');
$app->resize(1024, 768);
```

These are imperative and **not in the widget tree**, so they skip the diff — call them whenever, no need to wait for the next frame.

## Status bar

The status bar is segmented text at the bottom of the window:

```php
$app->setStatus(['Ready', '3 items']);
```

It can be updated later too (also outside the diff):

```php
$app->on('save_btn', 'click', function () use ($app, $state) {
    $state->save();
    $app->setStatus(['Saved', count($state->tasks) . ' items']);
});
```

## Menu bar

```php
$app->setMenu([
    ['type' => 'menu', 'text' => 'File', 'children' => [
        ['type' => 'item', 'id' => 'menu.new',  'text' => 'New', 'shortcut' => 'Ctrl+N'],
        ['type' => 'item', 'id' => 'menu.save', 'text' => 'Save', 'shortcut' => 'Ctrl+S'],
        ['type' => 'separator'],
        ['type' => 'item', 'id' => 'menu.quit', 'text' => 'Quit', 'shortcut' => 'Ctrl+Q'],
    ]],
    ['type' => 'menu', 'text' => 'View', 'children' => [
        ['type' => 'item', 'id' => 'menu.wrap', 'text' => 'Word wrap', 'checked' => $state->wrap],
    ]],
]);
```

A menu item click emits a `menu` event; an item with `checked` reports its new state in `payload.checked`:

```php
$app->on('menu.wrap', 'menu', function (array $event) use ($state) {
    $state->wrap = (bool) ($event['payload']['checked'] ?? false);
});
```

The menu is **not in the widget tree**, so call `setMenu()` again whenever the state it reflects changes — or set it once if the menu is static.

## Tray

```php
$app->setTray([
    'tooltip' => 'MyApp · click me',
    'visible' => true,
    // 'icon' => 'assets/icon.png',   // optional
]);
```

- A left click emits `['type' => 'tray']` with **no id**, so it can only be caught with `onAny('tray', …)`.
- `icon` is optional — the bridge falls back to the window icon, and to a standard system icon when the window has none either.
  **On macOS / Linux a tray item with no icon does not show up at all**, so that fallback is a usability requirement, not decoration.
- When the tray is unavailable (a CI box with no desktop session) `setTray` is a no-op and does not error.

## Timers

```php
$app->setTimer('clock', 1000);    // register, interval in ms
$app->setTimer('clock', 0);       // stop (interval <= 0 stops it)
$app->setTimer('clock', 500);     // change the interval

$app->on('clock', 'timer', function () use ($state) {
    $state->ticks++;
});
```

A timer tick emits `['type' => 'timer', 'id' => 'clock']`.

::: tip When to register
Register all handlers and timers before `run()`. Timers only actually start ticking inside `run()`.
:::
