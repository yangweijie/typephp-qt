# Quick Start

## 1. Create a project

```bash
qtphp new myapp
cd myapp
```

The scaffold **compiles and runs as-is** — you only replace the UI and the data layer.

```
myapp/
├── src/main.php            # entry: state + view + handlers
├── project.yml             # Windows build entry
├── project.macos.yml       # macOS entry (includes the common part, overrides Qt)
├── project.linux.yml       # Linux entry (Debian multiarch paths)
├── Info.macos.plist        # for packaging the .app
├── build.bat / run.bat / package.bat   # Windows convenience scripts
└── assets/                 # files read at runtime (icons, …)
```

## 2. Build / run / package

```bash
qtphp build .       # compile (auto-deploys runtime DLLs to build/ on Windows)
qtphp run .         # run; arguments after the path pass straight through
qtphp package .     # assemble a self-contained bundle
```

Because `run` forwards its arguments, you can verify like this:

```bash
qtphp run . --shot out.png
qtphp run . --selftest
```

## 3. Write your UI

Edit `src/main.php`. The scaffold's structure is the recommended shape — state, view, handlers:

```php
<?php

declare(strict_types=1);

use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

function main(int $argc, array $argv): void
{
    // (1) State: handlers change it, the view reads it
    $state = ['name' => 'World', 'greeting' => 'Hello, World!', 'clicks' => 0];

    $app = new QtApp();
    $app->create(['name' => 'MyApp']);
    $app->createWindow('My App', ['width' => 720, 'height' => 480, 'centered' => true]);

    // (2) View: re-describe the UI from $state every frame
    $app->view(function () use (&$state): array {
        return WidgetTree::vbox([
            WidgetTree::label($state['greeting'], ['id' => 'greeting']),
            WidgetTree::hbox([
                WidgetTree::lineEdit($state['name'], ['id' => 'name_input']),
                WidgetTree::button('Say hello', ['id' => 'greet_btn']),
            ]),
            WidgetTree::label('Clicks: ' . $state['clicks'], ['id' => 'clicks']),
        ]);
    });

    // (3) Handlers: only change state
    $app->on('greet_btn', 'click', function () use ($app, &$state) {
        $state['name'] = $app->text('name_input');
        $state['greeting'] = 'Hello, ' . ($state['name'] === '' ? 'World' : $state['name']) . '!';
        $state['clicks']++;
    });

    $app->run();
}
```

## 4. Verify

```bash
qtphp run . --shot out.png     # render a few frames, save a PNG, exit (visual check)
qtphp run . --selftest         # fire every event, confirm each handler is callable
```

Look at the PNG `--shot` produces. `--selftest` catches the class of bug that **only appears under AOT** — such as a closure arity mismatch — in a headless environment. See [AOT Notes](/advanced/aot-notes.md#closure-arity-is-validated-exactly).

## Key conventions

- **Give every control you read or write an `id`.** A node without one gets a structural-path id (`_p0.1.2`) — stable, but unreadable; `$app->text('name_input')` needs a real id.
- **`main()` must be a global function** with the signature `main(int $argc, array $argv): void`. Command-line arguments come from here.
- **Never use `require`.** Cross-file visibility comes from `project.yml`'s `sources:` list.
- **Never use `global $argv`** — it crashes under AOT.

## Next

- [Widget Catalog](/widgets/) — what controls exist and what properties they take
- [Events](/guide/events.md) — event types and payloads
- [State and View](/guide/state-and-view.md) — writing the declarative pattern well
