# Display Controls

## `label` — text label

```php
WidgetTree::label(string $text, array $props = [])
```

| Property | Meaning |
|---|---|
| `text` | The text |
| `bold` | Bold |
| `font_size` | Font size (px) |
| `align` | `left` `right` `center` |
| `style` | QSS fragment (more flexible) |

```php
WidgetTree::label('Title', ['id' => 'title', 'bold' => true, 'font_size' => 22]);
WidgetTree::label('Subtitle', ['id' => 'sub', 'style' => 'color:#666;font-style:italic;']);
```

**Events**: none (a label is not interactive)

::: tip Dynamic text
`view()` runs every frame, so just interpolate state into the string — there is no "update the label" operation:

```php
WidgetTree::label('Clicks: ' . $state['clicks'], ['id' => 'count'])
```
:::

## `button` — push button

```php
WidgetTree::button(string $text, array $props = [])
```

| Property | Meaning |
|---|---|
| `text` | Button text |
| `checkable` | Toggleable (pressed / released) |
| `checked` | Toggle state |
| `flat` | Borderless |
| `default` | Default button (triggered by Enter) |
| `enabled` | Clickable or not |

```php
WidgetTree::button('Save', ['id' => 'save', 'default' => true]);
WidgetTree::button('Delete', ['id' => 'del', 'enabled' => $state->hasSelection()]);
```

**Events**: `click`

```php
$app->on('save', 'click', function () use ($state) {
    $state->save();
});
```

A checkable button emits `toggle`:

```php
WidgetTree::button('Bold', ['id' => 'bold', 'checkable' => true, 'checked' => $state->bold]);

$app->on('bold', 'toggle', function (array $event) use ($state) {
    $state->bold = $event['value'] === '1';
});
```

## `progress` — progress bar

```php
WidgetTree::progress(int $value = 0, array $props = [])
```

| Property | Meaning |
|---|---|
| `value` | Current value |
| `min` / `max` | Range (defaults to 0–100) |

```php
WidgetTree::progress($state->progress, ['id' => 'bar', 'max' => 100]);
```

**Events**: none (display only)

On a hot path, updating via `patch()` is cheaper:

```php
$app->patch([['op' => 'set', 'id' => 'bar', 'props' => ['value' => 65]]]);
```

## `image` — image

```php
WidgetTree::image(string $path, array $props = [])
```

| Property | Meaning |
|---|---|
| `path` | Image path |
| `scaled_size` | Scale size `[w, h]` |

```php
WidgetTree::image('assets/logo.png', ['id' => 'logo', 'scaled_size' => [120, 120]]);
```

::: warning Paths are relative to the executable
A relative path resolves against the **artifact's directory**. So `'assets/logo.png'` works both from the project directory and from `dist/` — provided `assets/` is copied along when packaging (`qtphp package` does that).
:::

## `link` — hyperlink

```php
WidgetTree::link(string $text, string $href, array $props = [])
```

```php
WidgetTree::link('Homepage', 'https://github.com/yangweijie/typephp-qt', ['id' => 'home']);
```

**Events**: `click`, whose `value` is the href

```php
$app->on('home', 'click', function (array $event) {
    // $event['value'] is the href — opening a browser needs extra bridge support
    error_log('clicked ' . $event['value']);
});
```

## A whole screen of display controls

```php
WidgetTree::vbox([
    WidgetTree::label('Project status', ['id' => 'title', 'bold' => true, 'font_size' => 20]),

    WidgetTree::hbox([
        WidgetTree::image('assets/logo.png', ['id' => 'logo', 'scaled_size' => [48, 48]]),
        WidgetTree::vbox([
            WidgetTree::label($state->projectName, ['id' => 'name']),
            WidgetTree::link('View source', $state->repoUrl, ['id' => 'repo']),
        ], ['grow' => 1]),
    ]),

    WidgetTree::separator(),

    WidgetTree::label('Build progress: ' . $state->progress . '%', ['id' => 'progress_label']),
    WidgetTree::progress($state->progress, ['id' => 'bar', 'max' => 100]),

    WidgetTree::hbox([
        WidgetTree::button('Start build', ['id' => 'start', 'default' => true]),
        WidgetTree::button('Cancel', ['id' => 'cancel', 'enabled' => $state->running]),
        WidgetTree::spacer(1),
        WidgetTree::button('Details', ['id' => 'log', 'flat' => true]),
    ]),
]);
```
