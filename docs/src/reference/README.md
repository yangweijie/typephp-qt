# Reference

| Page | Contents |
|---|---|
| [CLI](/reference/cli.md) | The seven `qtphp` subcommands |
| [API](/reference/api.md) | Every `QtApp` / `WidgetTree` method, the event table, the bridge contract |
| [Packaging](/reference/packaging.md) | Artifact layout on three platforms, the self-check, runtime dependencies |
| [Platforms](/reference/platforms.md) | The per-platform capability matrix and tested environments |

## Quick index

**Most-used API**

```php
$app->create($options);                    // initialize QApplication
$app->createWindow($title, $options);      // create a window
$app->view($builder);                      // register the view builder
$app->on($id, $type, $handler);            // register an event handler
$app->run();                               // enter the main loop
```

**Reading widget values**

```php
$app->text($id);      // string
$app->value($id);     // mixed
$app->checked($id);   // bool
```

**Imperative (not in the widget tree)**

```php
$app->setTitle($t);  $app->setMenu($items);  $app->setStatus($segs);
$app->setTray($spec);  $app->setTimer($id, $ms);  $app->resize($w, $h);
```

**Dialogs**

```php
$app->alert($text);  $app->confirm($text);  $app->openFile();  $app->saveFile();
```

**Verification**

```php
$app->headless(true);  $app->snapshot($path);  $app->lastError();
```
