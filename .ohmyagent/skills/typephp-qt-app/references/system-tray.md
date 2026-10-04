# System tray with Qt

`QSystemTrayIcon` is Qt's **cross-platform** tray abstraction — the same code
gives you the Windows notification area, the macOS menu-bar item, and the Linux
StatusNotifierItem. Writing it once and getting all three is the whole point;
do not reach for `Shell_NotifyIcon` or platform APIs directly.

**On the `typephp-qt` package route (SKILL.md route 0) you write none of this.**
`$app->setTray(['icon' => 'assets/icon.png', 'menu' => [...]])` gives you the icon,
the context menu and the events declaratively — the gesture lands in
`$event['value']` (`left` / `right` / `double` / `middle`) and menu items fire
`menu` events. Everything below is the hand-written bridge route.

> **Confidence:** the Windows path here was built and run end-to-end. The macOS
> and Linux notes are Qt's documented behaviour, not verified on this machine —
> treat them as "expected, confirm on target".

## Fastest path: scaffold it

```bash
scripts/scaffold.sh --tray <target-dir> <app_name> [qt_root]
```

This generates a complete, compiling tray app: `app/TrayController.php`,
`php-src/tray.stub.php`, `cpp-src/tray.cc`, `project.yml`, and `build.bat` /
`run.bat` (with `run.bat shot.png` for the headless render check). The tray
templates use fixed `tray_*` function names, so the stub and the bridge already
agree — you only edit the UI and the controller. Read on if you need to
understand or extend what it generates.

## Why a tray app is structurally different

Two things change, and getting either wrong produces the classic "the tray icon
flashes and vanishes" bug:

1. **`setQuitOnLastWindowClosed(false)`.** By default Qt quits when the last
   window closes. A tray app spends most of its life with no visible window, so
   without this line the process exits the instant the user closes the window —
   taking the icon with it.

2. **The event loop runs while the *icon* lives, not while a window is visible.**
   A normal TypePHP app loops `while (is_open($window))`. A tray app must loop
   `while (is_alive($app))`, where "alive" is a flag PHP clears on quit. If you
   keep the window-based condition, the loop exits the first time the window is
   hidden.

There is a third, subtler one: **keep the `QSystemTrayIcon` alive as a member of
the Box.** If it is a local, it is destroyed when the constructor returns and the
icon disappears.

## Minimal bridge

The `Box` holds the window, the tray icon and the menu. The window subclass
intercepts close so the X hides instead of quitting:

```cpp
class TrayWindow final : public QMainWindow {
  public:
    std::function<void()> onHide;
  protected:
    void closeEvent(QCloseEvent *event) override {
        event->ignore();      // do NOT destroy the window, just hide it
        hide();
        if (onHide) onHide(); // tell PHP
    }
};
```

The tray setup, inside the Box constructor:

```cpp
// 1. the icon — a QIcon; generating one programmatically avoids shipping a file
tray_ = new QSystemTrayIcon(iconPath.isEmpty() ? generatedIcon() : QIcon(iconPath));
tray_->setToolTip(title);

// 2. the context menu (right-click)
menu_ = new QMenu();
addAction(menu_, QObject::tr("显示主窗口"), "show");
addAction(menu_, QObject::tr("隐藏主窗口"), "hide");
menu_->addSeparator();
addAction(menu_, QObject::tr("退出"), "quit");
tray_->setContextMenu(menu_);

// 3. left-click / double-click
QObject::connect(tray_, &QSystemTrayIcon::activated,
                 [this](QSystemTrayIcon::ActivationReason reason) {
                     enqueue("tray_activated", {}, reasonName(reason));  // Trigger / DoubleClick / Context / MiddleClick
                 });

// 4. show it, and say so if there is no tray host
if (QSystemTrayIcon::isSystemTrayAvailable()) {
    tray_->show();
} else {
    fprintf(stderr, "tray: system tray is NOT available on this session\n");
}
```

And the one line that keeps the process alive, in `create`:

```cpp
if (!qt_application) {
    qt_application = new QApplication(qt_argc, qt_argv);
    qt_application->setQuitOnLastWindowClosed(false);   // <-- critical
    // … style, font
}
```

## Bridge API and event convention

```php
function tray_create(string $title, string $iconPath): mixed {}   // '' = generated icon
function tray_is_alive(mixed $app): bool {}
function tray_process_events(mixed $app): void {}
function tray_poll_event(mixed $app): array {}
function tray_set_status(mixed $app, string $text): void {}       // tooltip
function tray_show_message(mixed $app, string $title, string $body): void {}
function tray_show_window(mixed $app, bool $show): void {}
function tray_is_window_visible(mixed $app): bool {}
function tray_quit(mixed $app): void {}                           // clears the alive flag
function tray_snapshot(mixed $app, string $path): bool {}
function tray_destroy(mixed $app): void {}
```

Events follow the same flat convention as the rest of the bridge:

| type | value | meaning |
|---|---|---|
| `tray_activated` | `Trigger` / `DoubleClick` / `Context` / `MiddleClick` | the icon was clicked |
| `menu` | `show` / `hide` / `quit` / … | a tray menu item was chosen |
| `window_closed` | — | the user hit the window's X (it hid, we did not exit) |

PHP decides everything — what a left-click toggles, what the tooltip says, and
whether a menu item ends the process:

```php
while (tray_is_alive($this->app)) {
    tray_process_events($this->app);
    while (true) {
        $event = tray_poll_event($this->app);
        if ($event === []) break;
        $this->handle($event);
    }
}
```

## Custom icon

The generated icon is a placeholder. Pass a path as the second argument to
`tray_create()` to use your own image:

```php
$this->app = tray_create($title, 'icon.png');   // '' keeps the generated icon
```

**Relative paths resolve against the executable's folder, not the process CWD.**
That is deliberate: `windeployqt` does not copy your image, so the file has to
ship next to the exe — and resolving against `applicationDirPath()` means
`'icon.png'` keeps working no matter which directory the app is launched from.
An absolute path is used as-is.

Formats: **PNG, ICO and SVG** all load (`QIcon` handles the raster formats, SVG
via the `qsvg` image plugin that `windeployqt` deploys). A single 64×64 PNG is
fine everywhere; on Windows a **multi-size `.ico`** (16/32/48/256) stays crisp
across DPI settings and is worth the extra step.

The same icon is also set as the window icon, so the taskbar entry matches.

### When the file cannot be loaded

A missing or unreadable icon is the nastiest tray bug, because `QIcon` fails
*silently* — you get a blank spot in the notification area and nothing looks
broken. So the bridge reports it and falls back:

```
tray: tray icon loaded from 'D:/app/icon.png'
tray: could not load icon 'D:/app/does-not-exist.png', using the generated icon
```

Check stderr if the tray icon looks generic. If you want to override the icon
without rebuilding, the scaffolded controller reads `TRAY_ICON` — set it to a
path and it wins over the compiled-in default.

## Cross-platform notes

| Platform | Where the icon lands | Watch out for |
|---|---|---|
| **Windows** | Notification area (bottom-right) | Verified here. Users can hide it in "hidden icons" — that is normal, not a bug. |
| **macOS** | Menu bar (right side) | The context menu becomes a menu-bar menu. `showMessage` (balloon) is documented as unsupported on macOS — use a real notification API if you need one. |
| **Linux** | StatusNotifierItem (SNI) / AppIndicator | Requires a tray host. KDE has one built in; **GNOME needs an extension**. On a bare session `isSystemTrayAvailable()` returns false — which is exactly why you log it instead of failing silently. |

Practical consequences:

- **Never assume the icon is visible.** Check `isSystemTrayAvailable()` and give
  the user a fallback (keep the window reachable, or log clearly).
- **The tray menu is a real `QMenu`**, so it can hold separators, checkable
  items and submenus — no per-platform code needed.
- **Icon sizing differs.** A single 64×64 pixmap works everywhere; if you ship a
  file, prefer a multi-resolution `.ico` on Windows and a template image on macOS.

## Verifying a tray app

The tray icon lives outside any window, so `window_->grab()` cannot capture it.
Verify in three steps instead:

1. **It builds and the window renders** — the usual `snapshot()` path, with the
   window forced visible first.
2. **The tray host is present** — log `QSystemTrayIcon::isSystemTrayAvailable()`.
3. **The event wiring works** — enqueue on activation/menu and assert PHP
   receives the events. Drive it manually once; the signals are easy to get
   wrong and silent when they are.

A tray app also has no natural exit in a screenshot run, so give it an explicit
quit path (`tray_quit`) and use that in the headless mode — do not rely on the
window closing.
