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
    'icon' => 'assets/icon.png',      // optional but recommended (see the warning below)
    'menu' => [                        // optional: right-click menu
        ['type' => 'item', 'id' => 'tray.show', 'text' => 'Show window'],
        ['type' => 'item', 'id' => 'tray.quit', 'text' => 'Quit'],
    ],
]);
```

- An activation emits `['type' => 'tray']` with **no id**, so it can only be caught with `onAny('tray', …)`.
  `value` carries the gesture: `left` / `right` / `double` / `middle`. See [Events](/guide/events.md#tray).
- `menu` is optional. With it, right-click pops the menu and its items fire `menu` events (id-prefixed by convention, e.g. `tray.`).
- `icon` is optional — the bridge falls back to the window icon, and to a standard system icon when the window has none either.
  **On macOS / Linux a tray item with no icon does not show up at all**, so that fallback is a usability requirement, not decoration.
- A relative `icon` path resolves against the **executable's directory** — then, inside a macOS `.app`, `Contents/Resources` — and only then the working directory, so `'assets/icon.png'` works from `build/`, `dist/` and the packaged `.app` alike.

::: warning "I called setTray but I cannot see the icon"
On Windows the notification area **hides newly appearing icons by default** — a brand-new app's icon lands in the overflow panel behind the `^` chevron, not on the visible taskbar. That is Windows' own behaviour, not the framework's.

Confirm the tray really exists by checking the registry entry Windows creates for it:

```powershell
Get-ChildItem 'HKCU:\Control Panel\NotifyIconSettings' | ForEach-Object {
  $p = Get-ItemProperty $_.PSPath
  if ($p.ExecutablePath -like '*<yourapp>*') {
    "$($p.ExecutablePath)  IsPromoted=[$($p.IsPromoted)]"
  }
}
```

An empty `IsPromoted` means "in the overflow area". Click the `^` chevron, or drag the icon onto the taskbar to promote it.

Two other causes worth ruling out, both silent:

- **The icon failed to load.** A wrong path yields a null `QIcon`, and an icon-less tray item is not shown at all. The bridge now falls back step by step (explicit path → window icon → system icon) and logs `tray icon could not be loaded: <path>` to stderr.
- **`assets/` never reached `build/`.** `qtphp build` copies it for you; if you placed the file after building, rebuild.
:::
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
