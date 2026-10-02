# Widget Catalog

Every control is built by a static method on `TypePHP\Qt\WidgetTree`, returning an array node. A node looks like:

```php
['type' => 'button', 'props' => ['id' => 'ok', 'text' => 'OK'], 'children' => []]
```

You never write that structure by hand — use the factory:

```php
WidgetTree::button('OK', ['id' => 'ok']);
```

## Browse by category

| Category | Contains |
|---|---|
| [Containers](/widgets/containers.md) | `vbox` `hbox` `grid` `form` `group` `frame` `scroll` `tabs` `tab` `stack` `page` `split` `spacer` `separator` |
| [Inputs](/widgets/inputs.md) | `lineedit` `textedit` `spin` `doublespin` `slider` `checkbox` `radio` `combo` |
| [Data](/widgets/data.md) | `list` `table` `tree` |
| [Display](/widgets/display.md) | `label` `button` `progress` `image` `link` |

## Cheat sheet

| Method | What it is | Main properties |
|---|---|---|
| `vbox($children)` | vertical layout | `spacing` `margin` |
| `hbox($children)` | horizontal layout | `spacing` `margin` |
| `grid($children)` | grid layout | `row` `col` `row_span` `col_span` |
| `form($children)` | two-column form | — |
| `group($title, $children)` | titled group box | `title` |
| `frame($children)` | untitled container | `style` |
| `scroll($children)` | scroll area | — |
| `tabs($pages)` | tab widget | `tab_position` `current` |
| `tab($title, $children)` | a single tab | `title` |
| `stack($pages)` | untitled stack | `current` |
| `page($children)` | one page of a stack | — |
| `split($children)` | draggable splitter | `orientation` `sizes` |
| `spacer($size)` | flexible gap | `size` |
| `separator()` | divider line | — |
| `label($text)` | text label | `text` `bold` `font_size` `align` |
| `button($text)` | push button | `text` `checkable` `checked` `flat` `default` |
| `lineEdit($text)` | single-line input | `text` `placeholder` `password` `clear_button` `max_length` |
| `textEdit($text)` | multi-line input | `text` `wrap` `readonly` |
| `spin($value)` | integer spinner | `value` `min` `max` `step` `prefix` `suffix` |
| `doubleSpin($value)` | float spinner | as above + `decimals` |
| `slider($value)` | slider | `value` `min` `max` `orientation` `ticks` |
| `progress($value)` | progress bar | `value` `min` `max` |
| `checkbox($text, $checked)` | checkbox | `checked` |
| `radio($text, $checked)` | radio button | `checked` |
| `combo($items, $current)` | combo box | `items` `current` `editable` |
| `list($items, $current)` | list widget | `items` `current` `multi` |
| `table($columns, $rows)` | table | `columns` `rows` `row_ids` `current` |
| `tree($nodes)` | tree | `nodes` `headers` `current` |
| `image($path)` | image | `path` `scaled_size` |
| `link($text, $href)` | hyperlink | `text` `href` |

## Common to every node

`id` `visible` `enabled` `tooltip` `style` `size` `min_size` `max_size` `align` `grow`

Full property tables: [Properties](/guide/properties.md).

## A composed example

```php
WidgetTree::vbox([
    WidgetTree::group('Search', [
        WidgetTree::hbox([
            WidgetTree::lineEdit($state->query, ['id' => 'q', 'placeholder' => 'Keyword', 'grow' => 1]),
            WidgetTree::button('Search', ['id' => 'go']),
        ]),
    ]),

    WidgetTree::table(
        ['Name', 'Qty', 'Status'],
        $state->rows(),
        ['id' => 'tbl', 'row_ids' => $state->rowIds(), 'stretch_last' => true, 'grow' => 1]
    ),

    WidgetTree::hbox([
        WidgetTree::checkbox('Pending only', $state->onlyPending, ['id' => 'filter']),
        WidgetTree::spacer(1),
        WidgetTree::progress($state->progress, ['id' => 'bar', 'max' => 100]),
    ]),
]);
```
