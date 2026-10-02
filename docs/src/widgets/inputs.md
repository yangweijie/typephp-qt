# Input Controls

## `lineEdit` — single-line input

```php
WidgetTree::lineEdit(string $text = '', array $props = [])
```

| Property | Meaning |
|---|---|
| `text` | Current text |
| `placeholder` | Placeholder hint |
| `password` | Password mode (dots) |
| `clear_button` | Show a clear button |
| `max_length` | Maximum characters |
| `readonly` | Read-only |

```php
WidgetTree::lineEdit($state->name, [
    'id' => 'name_input',
    'placeholder' => 'Enter a name',
    'clear_button' => true,
]);
```

**Events**: `change` (every keystroke), `submit` (Enter)

```php
$app->on('name_input', 'change', function (array $event) use ($state) {
    $state->name = (string) $event['value'];
});

$app->on('name_input', 'submit', function (array $event) use ($state) {
    $state->search();     // search only on Enter
});
```

## `textEdit` — multi-line input

```php
WidgetTree::textEdit(string $text = '', array $props = [])
```

| Property | Meaning |
|---|---|
| `text` | Content |
| `wrap` | Word wrap |
| `readonly` | Read-only |

```php
WidgetTree::textEdit($state->body, ['id' => 'body', 'wrap' => true, 'grow' => 1]);
```

**Events**: `change`

::: tip A multi-line input has no `submit`
Enter inserts a newline in a `textEdit`, it does not submit. If you want a submit action, pair it with a button.
:::

## `spin` / `doubleSpin` — number spinners

```php
WidgetTree::spin(int $value = 0, array $props = [])
WidgetTree::doubleSpin(float $value = 0.0, array $props = [])
```

| Property | Meaning |
|---|---|
| `value` | Current value |
| `min` / `max` | Range |
| `step` | Step |
| `decimals` | Decimal places (`doubleSpin` only) |
| `prefix` / `suffix` | Affixes |

```php
WidgetTree::spin($state->count, ['id' => 'count', 'min' => 0, 'max' => 999, 'suffix' => ' items']);
WidgetTree::doubleSpin($state->price, ['id' => 'price', 'decimals' => 2, 'prefix' => '$', 'step' => 0.5]);
```

**Events**: `change`

## `slider` — slider

```php
WidgetTree::slider(int $value = 0, array $props = [])
```

| Property | Meaning |
|---|---|
| `value` `min` `max` `step` | As above |
| `orientation` | `h` / `v` |
| `ticks` | Tick marks |

```php
WidgetTree::slider($state->volume, ['id' => 'vol', 'min' => 0, 'max' => 100, 'ticks' => 11]);
```

**Events**: `change`

## `checkbox` — checkbox

```php
WidgetTree::checkbox(string $text = '', bool $checked = false, array $props = [])
```

```php
WidgetTree::checkbox('Dark text', $state->dark, ['id' => 'dark_toggle']);
```

**Events**: `toggle`, whose `value` is the string `'0'` / `'1'`:

```php
$app->on('dark_toggle', 'toggle', function (array $event) use ($state) {
    $state->dark = $event['value'] === '1';    // note: a string
});
```

You can also read it back with `$app->checked('dark_toggle')`.

## `radio` — radio button

```php
WidgetTree::radio(string $text = '', bool $checked = false, array $props = [])
```

Multiple `radio`s inside the same container are mutually exclusive automatically:

```php
WidgetTree::group('Export format', [
    WidgetTree::radio('CSV', $state->format === 'csv', ['id' => 'fmt_csv']),
    WidgetTree::radio('JSON', $state->format === 'json', ['id' => 'fmt_json']),
    WidgetTree::radio('XML', $state->format === 'xml', ['id' => 'fmt_xml']),
]);
```

**Events**: `toggle`

## `combo` — combo box

```php
WidgetTree::combo(array $items, string $value = '', array $props = [])
```

| Property | Meaning |
|---|---|
| `items` | Item text list |
| `current` | Current selection (**the text**, not the index) |
| `editable` | Allow free typing |

```php
WidgetTree::combo(['Light', 'Dark', 'System'], $state->theme, ['id' => 'theme_combo']);
```

**Events**: `change`, whose `value` is the selected text and `payload.index` is the index:

```php
$app->on('theme_combo', 'change', function (array $event) use ($state) {
    $state->theme = (string) $event['value'];
    $state->themeIndex = (int) $event['payload']['index'];
});
```

::: warning `current` takes text, not an index
This differs from the other controls — `list` takes an index, `combo` takes text. Passing the wrong thing silently selects nothing.
:::

## A whole screen of inputs

```php
WidgetTree::form([
    WidgetTree::label('Name'),
    WidgetTree::lineEdit($state->name, ['id' => 'name', 'placeholder' => 'required']),

    WidgetTree::label('Quantity'),
    WidgetTree::spin($state->qty, ['id' => 'qty', 'min' => 1, 'max' => 100]),

    WidgetTree::label('Unit price'),
    WidgetTree::doubleSpin($state->price, ['id' => 'price', 'decimals' => 2, 'prefix' => '$']),

    WidgetTree::label('Category'),
    WidgetTree::combo(['Books', 'Electronics', 'Clothing'], $state->category, ['id' => 'cat']),

    WidgetTree::label('Note'),
    WidgetTree::textEdit($state->note, ['id' => 'note', 'wrap' => true]),

    WidgetTree::label(''),
    WidgetTree::checkbox('Rush order', $state->urgent, ['id' => 'urgent']),
]);
```
