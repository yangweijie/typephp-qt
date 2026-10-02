# Properties

Properties are an associative array on a widget-tree node, passed as the third or second argument.

```php
WidgetTree::label('Hello', ['id' => 'greeting', 'style' => 'color:red', 'align' => 'center']);
```

## Common to every node

| Property | Type | Meaning |
|---|---|---|
| `id` | string | **The stable identity** — the diff and the events both key on it. Any control you read or write needs one |
| `visible` | bool | Show / hide |
| `enabled` | bool | Interactive or not |
| `tooltip` | string | Hover text |
| `style` | string | A Qt stylesheet fragment, e.g. `color:#1d4ed8;font-weight:bold;` |
| `size` | `[w, h]` | Fixed size |
| `min_size` / `max_size` | `[w, h]` | Size constraints |
| `align` | string | `left` `right` `center` |
| `grow` | int | Stretch weight within its layout (0 = no stretch) |

## Container properties

| Property | Applies to | Meaning |
|---|---|---|
| `title` | group, tabs, tab | Title text |
| `margin` | layout containers | Outer margin: an int, or `[top, right, bottom, left]` |
| `spacing` | layout containers | Gap between children |
| `row` / `col` | grid, form | Grid position |
| `row_span` / `col_span` | grid | Span across rows / columns |
| `orientation` | split | `h` / `v` |
| `sizes` | split | Initial split ratio, e.g. `[200, 400]` |
| `tab_position` | tabs | `top` `bottom` `left` `right` |

## Input properties

| Property | Applies to | Meaning |
|---|---|---|
| `text` | text controls | The text content |
| `placeholder` | lineedit, textedit | Placeholder hint |
| `readonly` | lineedit, textedit | Read-only |
| `password` | lineedit | Password mode (dots) |
| `clear_button` | lineedit | Show a clear button |
| `max_length` | lineedit | Maximum characters |
| `wrap` | textedit | Word wrap |

## Numeric properties

| Property | Applies to | Meaning |
|---|---|---|
| `value` | all numeric controls | Current value |
| `min` / `max` | all numeric controls | Range |
| `step` | spin, doublespin, slider | Step |
| `decimals` | doublespin | Decimal places |
| `prefix` / `suffix` | spin, doublespin | Affixes, e.g. `$` / `%` |
| `ticks` | slider | Tick marks |

## List properties

| Property | Applies to | Meaning |
|---|---|---|
| `items` | combo, list | Item text list |
| `current` | combo, list, table, tree, tabs, stack | **Declarative selection** — the meaning varies, see below |
| `columns` | table | Header text list |
| `rows` | table | Row data (each row is a list of cells) |
| `row_ids` | table | Row id list |
| `nodes` | tree | Tree nodes |
| `headers` | tree | Column headers |
| `headers_visible` | table, tree | Show the header row |
| `multi` | list, table, tree | Allow multi-select |
| `select_mode` | table, tree | Selection mode |
| `stretch_last` | table | Let the last column stretch |
| `checkable` | list, table, tree | Items carry checkboxes |
| `editable` | combo, table | Editable (on a table this enables in-place cell editing and the `cell` event) |

### `current` means different things per control

This is the easiest thing to get wrong — **the same `current` property takes a different type on different controls**:

| Control | What `current` takes |
|---|---|
| `table` | a **row id** (without `row_ids`, the row id is the index string `'0'`, `'1'`, …) |
| `tree` | a **node id** (without one, it falls back to the node's text) |
| `list` | a row index |
| `combo` / `tabs` / `stack` | an index or the text |

```php
// table: select by row id
WidgetTree::table(['Name', 'Qty'], $rows, ['id' => 'tbl', 'row_ids' => ['r1', 'r2'], 'current' => 'r2']);

// combo: select by text
WidgetTree::combo(['Light', 'Dark'], 'Dark', ['id' => 'theme']);

// list: select by index
WidgetTree::list(['A', 'B', 'C'], 1, ['id' => 'lst']);
```

### Selection is recovered when `current` is omitted

On re-render the framework recovers the previous selection by **row id / node id**, so inserting a row or swapping the data never misplaces the selection. This is part of the diff engine — see [Diff Engine](/advanced/diff-engine.md).

## Display properties

| Property | Applies to | Meaning |
|---|---|---|
| `href` | link | Link target (reported as `value` on click) |
| `path` | image | Image path |
| `scaled_size` | image | Scale size |
| `bold` | label | Bold |
| `font_size` | label, button | Font size |
| `checkable` | button | Toggle button |
| `flat` | button | Borderless |
| `checked` | button, checkbox, radio | Checked state |
| `default` | button | Default button (triggered by Enter) |

## Unknown properties are ignored

Writing a property that does not exist is not an error — it simply has no effect, consistent with unknown `id`s and unknown `patch` methods. That makes graceful degradation and typo tolerance easy, but **a typo will not tell you** — so check against this table.

## Styling with `style`

`style` is a Qt stylesheet (QSS) fragment, written on the node:

```php
WidgetTree::label('Title', [
    'id' => 'title',
    'style' => 'font-size:22px;font-weight:bold;color:#1d4ed8;',
])
```

You can also set one globally, at `create()` time:

```php
$app->create(['name' => 'MyApp', 'stylesheet' => 'QPushButton { padding: 6px 14px; }']);
```

## Size and layout

- `size` is a **fixed** size and overrides the layout's automatic computation.
- `grow` is a **stretch weight** — when the layout has spare space it is distributed by weight. An item with `grow => 1` takes all of it.
- `min_size` / `max_size` are constraints, best used together with `grow`.

```php
WidgetTree::hbox([
    WidgetTree::label('Sidebar', ['size' => [180, 0]]),         // fixed width 180
    WidgetTree::textEdit('', ['id' => 'main', 'grow' => 1]),    // takes the rest
]);
```
