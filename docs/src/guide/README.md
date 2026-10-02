# Guide

TypePHP compiles PHP to native machine code, but it **cannot draw a window**. Qt (C++) draws the window. So the shape of every app is the same: a thin C++ bridge exposes Qt to PHP, and every decision lives in PHP.

`yangweijie/typephp-qt` packages that bridge, a declarative UI layer, a test double and a CLI into one Composer package. **You write only PHP.**

## Three ideas

The whole framework is these three ideas:

1. **State is plain PHP** — an array (or your own objects) holds everything the UI displays.
2. **`view()` describes the UI from state** — it runs once per frame and returns a widget tree. You never mutate widgets.
3. **Handlers only change state** — the next frame re-describes the UI; the C++ side diffs by `id` and keeps widget state (cursor, selection, scroll) intact.

```php
use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

function main(int $argc, array $argv): void
{
    $state = ['name' => 'World', 'greeting' => 'Hello, World!'];

    $app = new QtApp();
    $app->create(['name' => 'MyApp']);
    $app->createWindow('My App', ['width' => 720, 'height' => 480, 'centered' => true]);

    $app->view(function () use (&$state): array {
        return WidgetTree::vbox([
            WidgetTree::label($state['greeting'], ['id' => 'greeting']),
            WidgetTree::hbox([
                WidgetTree::lineEdit($state['name'], ['id' => 'name_input']),
                WidgetTree::button('Say hello', ['id' => 'greet_btn']),
            ]),
        ]);
    });

    $app->on('greet_btn', 'click', function () use ($app, &$state) {
        $state['name'] = $app->text('name_input');
        $state['greeting'] = 'Hello, ' . $state['name'] . '!';
    });

    $app->run();
}
```

## Next

- [Installation](/guide/installation.md) — install the package and check your toolchain
- [Quick Start](/guide/quickstart.md) — a window running in five minutes
- [Architecture](/guide/architecture.md) — how the declarative diff works
- [Widget Catalog](/widgets/) — what you can build with

## Why not hand-write the C++ bridge

The package *is* the hand-written bridge, productized. That route still exists ([Advanced: Bridge](/advanced/bridge.md)), but you only need it for a Qt widget the package does not expose. The package route wins on three counts:

- **No C++ to maintain**, so the bridge can never drift from your Qt/PHPX version.
- **The domain layer unit-tests without Qt** — the package ships `FakeBridge`, a pure-PHP stand-in for the `qt_*` functions, so `qtphp test` needs neither Qt nor a compiler.
- **One CLI covers all three platforms** — `qtphp` picks the right entry yml, deploys runtime DLLs, and packages with a self-check.
