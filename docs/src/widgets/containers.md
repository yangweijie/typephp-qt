# Containers

Containers are layout nodes: they decide how their children are arranged.

## `vbox` / `hbox` — linear layout

```php
WidgetTree::vbox(array $children, array $props = [])
WidgetTree::hbox(array $children, array $props = [])
```

| Property | Meaning |
|---|---|
| `spacing` | Gap between children (px) |
| `margin` | Outer margin (px) |
| `grow` | Stretch weight within the parent layout |

```php
WidgetTree::vbox([
    WidgetTree::label('Title'),
    WidgetTree::label('Body'),
], ['spacing' => 8, 'margin' => 12]);
```

## `grid` — grid layout

```php
WidgetTree::grid(array $children, array $props = [])
```

Children are positioned with `row` / `col`, and can span:

```php
WidgetTree::grid([
    WidgetTree::label('Name', ['row' => 0, 'col' => 0]),
    WidgetTree::lineEdit('', ['id' => 'name', 'row' => 0, 'col' => 1]),
    WidgetTree::label('Note', ['row' => 1, 'col' => 0]),
    WidgetTree::textEdit('', ['id' => 'note', 'row' => 1, 'col' => 1, 'row_span' => 2]),
]);
```

| Property | Meaning |
|---|---|
| `row` / `col` | Grid position (0-based) |
| `row_span` / `col_span` | How many rows / columns to span |

## `form` — two-column form

Labels on the left, fields on the right, aligned automatically:

```php
WidgetTree::form([
    WidgetTree::label('Username'),
    WidgetTree::lineEdit('', ['id' => 'user']),
    WidgetTree::label('Password'),
    WidgetTree::lineEdit('', ['id' => 'pass', 'password' => true]),
]);
```

Children come in **pairs**: odd positions are labels, even positions are fields.

## `group` — group box

```php
WidgetTree::group(string $title, array $children, array $props = [])
```

Draws a titled frame, clustering related controls:

```php
WidgetTree::group('Network', [
    WidgetTree::checkbox('Use a proxy', $state->proxy, ['id' => 'proxy']),
    WidgetTree::hbox([
        WidgetTree::label('Host:'),
        WidgetTree::lineEdit($state->host, ['id' => 'host', 'grow' => 1]),
    ]),
]);
```

## `frame` — untitled container

Mainly for giving a cluster a shared `style` or `visible`:

```php
WidgetTree::frame([
    WidgetTree::label('A'),
    WidgetTree::label('B'),
], ['visible' => $state->showDetails, 'style' => 'background:#f5f5f5;']);
```

## `scroll` — scroll area

```php
WidgetTree::scroll(array $children, array $props = [])
```

Wrap content that may overflow. It takes **one** child (usually a `vbox`):

```php
WidgetTree::scroll([
    WidgetTree::vbox($manyRows, ['spacing' => 4]),
]);
```

## `tabs` / `tab` — tabbed pages

```php
WidgetTree::tabs(array $pages, array $props = [])
WidgetTree::tab(string $title, array $children, array $props = [])
```

```php
WidgetTree::tabs([
    WidgetTree::tab('General', [
        WidgetTree::checkbox('Start on boot', $state->autostart, ['id' => 'auto']),
    ]),
    WidgetTree::tab('Advanced', [
        WidgetTree::slider($state->threads, ['id' => 'threads', 'min' => 1, 'max' => 16]),
    ]),
], ['id' => 'settings', 'current' => $state->tab]);
```

| Property | Meaning |
|---|---|
| `tab_position` | `top` `bottom` `left` `right` |
| `current` | The current page index or title |

Switching emits a `tab` event whose `payload.index` is the new index:

```php
$app->on('settings', 'tab', function (array $event) use ($state) {
    $state->tab = (int) $event['payload']['index'];
});
```

## `stack` / `page` — untitled stack

Like `tabs`, it switches in place, but **without a tab bar** — you switch it in code via `current`. Good for wizards and multi-step forms:

```php
WidgetTree::stack([
    WidgetTree::page([ WidgetTree::label('Step one') ]),
    WidgetTree::page([ WidgetTree::label('Step two') ]),
    WidgetTree::page([ WidgetTree::label('Done') ]),
], ['id' => 'wizard', 'current' => $state->step]);
```

It emits the same `tab` event.

## `split` — draggable splitter

```php
WidgetTree::split(array $children, array $props = [])
```

The user can drag to adjust the ratio between panes:

```php
WidgetTree::split([
    WidgetTree::list($names, 0, ['id' => 'tasks']),
    WidgetTree::textEdit('', ['id' => 'detail']),
], ['orientation' => 'h', 'sizes' => [220, 500]]);
```

| Property | Meaning |
|---|---|
| `orientation` | `h` (side by side) or `v` (stacked) |
| `sizes` | Initial ratio, e.g. `[220, 500]` |

## `spacer` — flexible gap

```php
WidgetTree::spacer(int $size = 0, array $props = [])
```

Places no widget at all; it only takes up space. **It pushes its neighbours apart**:

```php
WidgetTree::hbox([
    WidgetTree::button('Left', ['id' => 'l']),
    WidgetTree::spacer(1),                    // flexible: eats the spare space
    WidgetTree::button('Right', ['id' => 'r']),  // pushed to the far right
]);
```

`spacer(1)` is flexible; `spacer(20)` is a fixed 20px gap.

## `separator` — divider line

```php
WidgetTree::vbox([
    WidgetTree::label('Above'),
    WidgetTree::separator(),
    WidgetTree::label('Below'),
]);
```
