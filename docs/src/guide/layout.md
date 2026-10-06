# Layout

## Four containers

| Container | Arrangement | Use for |
|---|---|---|
| `vbox` | stacked vertically | The most common: forms, page bodies |
| `hbox` | side by side | A row of buttons, label + input |
| `grid` | row/column grid | Aligned tabular layouts |
| `form` | label + field, two columns | Settings forms |

```php
WidgetTree::vbox([
    WidgetTree::label('Title', ['id' => 'title']),
    WidgetTree::hbox([
        WidgetTree::lineEdit('', ['id' => 'input', 'grow' => 1]),
        WidgetTree::button('OK', ['id' => 'ok']),
    ]),
    WidgetTree::grid([
        WidgetTree::label('Name', ['row' => 0, 'col' => 0]),
        WidgetTree::lineEdit('', ['id' => 'name', 'row' => 0, 'col' => 1]),
        WidgetTree::label('Email', ['row' => 1, 'col' => 0]),
        WidgetTree::lineEdit('', ['id' => 'mail', 'row' => 1, 'col' => 1]),
    ]),
]);
```

## Nesting

Containers nest arbitrarily — this is how you build anything complex:

```php
WidgetTree::hbox([
    // left: a fixed-width sidebar
    WidgetTree::vbox([
        WidgetTree::label('Tasks'),
        WidgetTree::list($names, 0, ['id' => 'tasks', 'grow' => 1]),
    ], ['size' => [220, 0]]),

    // right: takes the rest, split again internally
    WidgetTree::vbox([
        WidgetTree::label($state->currentTitle, ['id' => 'detail_title']),
        WidgetTree::textEdit($state->currentBody, ['id' => 'detail_body', 'grow' => 1]),
        WidgetTree::hbox([
            WidgetTree::button('Save', ['id' => 'save']),
            WidgetTree::button('Delete', ['id' => 'del']),
            WidgetTree::spacer(1),              // pushes what follows to the right
            WidgetTree::label('Ready', ['id' => 'hint']),
        ]),
    ], ['grow' => 1]),
]);
```

## `spacer` — push things apart

`spacer` places no widget at all; it only takes up space. Its job is to **push its neighbours apart**:

```php
WidgetTree::hbox([
    WidgetTree::button('Left', ['id' => 'l']),
    WidgetTree::spacer(1),                    // flexible: eats the spare space
    WidgetTree::button('Right', ['id' => 'r']),  // pushed to the far right
]);
```

`spacer(1)` is flexible (weight 1); `spacer(20)` is a fixed 20px gap.

## `separator` — a divider line

```php
WidgetTree::vbox([
    WidgetTree::label('Above'),
    WidgetTree::separator(),
    WidgetTree::label('Below'),
]);
```

## Size policy

Three properties work together:

| Property | Meaning |
|---|---|
| `size => [w, h]` | **Fixed** size, overriding automatic computation |
| `grow => n` | **Stretch weight**; spare space is shared by weight |
| `min_size` / `max_size` | Bounds |

```php
// sidebar fixed at 180, main area takes the rest
WidgetTree::hbox([
    WidgetTree::vbox([...], ['size' => [180, 0]]),
    WidgetTree::vbox([...], ['grow' => 1]),
]);
```

A `0` in `size` means "not fixed in that direction":

```php
['size' => [180, 0]]   // width fixed at 180, height decided by the layout
```

## Scrolling

When content may overflow, wrap it in `scroll`:

```php
WidgetTree::scroll([
    WidgetTree::vbox($manyRows),
]);
```

`scroll` takes **one** child (usually a `vbox`).

## Tabs and stacks

`tabs` shows a visible tab bar; `stack` switches in place **without** a tab bar (you switch it in code):

```php
// with a tab bar — tabs() takes a **title => children map**, not pre-built tab() nodes.
// It wraps each entry with tab() itself, so passing tab(...) nodes throws
// "Argument #1 ($title) must be of type string, int given".
WidgetTree::tabs([
    'General'  => [ /* … */ ],
    'Advanced' => [ /* … */ ],
], ['id' => 'settings_tabs']);

// no tab bar, switched via current — stack() takes a list of child lists
WidgetTree::stack([
    [ /* page 1 */ ],
    [ /* page 2 */ ],
], ['id' => 'wizard', 'current' => $state->step]);
```

::: tip `current` on tabs / stack
Pass an **int**, not a string — the bridge does `value.toInt()`, so `'1'` silently becomes `0`
and the switch looks like it did nothing. The index is applied after the pages are built, so a
`current` that appears only on the first frame still takes effect.
:::

Switching emits a `tab` event (with `payload.index`).

## Splitters

`split` lets the user drag the divider between two panes:

```php
WidgetTree::split([
    WidgetTree::list($names, 0, ['id' => 'tasks']),
    WidgetTree::textEdit('', ['id' => 'detail']),
], ['orientation' => 'h', 'sizes' => [220, 500]]);
```

## Group boxes

`group` draws a titled frame, good for clustering related controls:

```php
WidgetTree::group('Settings', [
    WidgetTree::checkbox('Dark text', $state->dark, ['id' => 'dark']),
    WidgetTree::checkbox('Auto save', $state->autoSave, ['id' => 'auto']),
]);
```

`frame` is the untitled variant, mainly for giving a cluster a shared `style` or `visible`.

## Full example: a master-detail layout

List on the left, detail on the right, buttons at the bottom — the most common desktop layout:

```php
$app->view(function () use ($state): array {
    return WidgetTree::vbox([
        // top toolbar
        WidgetTree::hbox([
            WidgetTree::lineEdit($state->query, ['id' => 'search', 'placeholder' => 'Search…', 'grow' => 1]),
            WidgetTree::button('New', ['id' => 'new']),
        ]),

        WidgetTree::separator(),

        // body: list left, detail right
        WidgetTree::split([
            WidgetTree::list($state->names(), $state->selected, ['id' => 'tasks']),
            WidgetTree::vbox([
                WidgetTree::label($state->currentTitle, ['id' => 'title', 'style' => 'font-size:18px;font-weight:bold;']),
                WidgetTree::textEdit($state->currentBody, ['id' => 'body', 'grow' => 1]),
            ]),
        ], ['orientation' => 'h', 'sizes' => [240, 520], 'grow' => 1]),

        // bottom status row
        WidgetTree::hbox([
            WidgetTree::label("{$state->count()} items", ['id' => 'count']),
            WidgetTree::spacer(1),
            WidgetTree::button('Delete', ['id' => 'del', 'enabled' => $state->hasSelection()]),
        ]),
    ]);
});
```
