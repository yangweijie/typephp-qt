# Data Controls

Lists, tables and trees. These three are the controls whose "selection semantics" need the most care — their `current` and their event `value` all differ.

## `list` — list

```php
WidgetTree::list(array $items, string $value = '', array $props = [])
```

| Property | Meaning |
|---|---|
| `items` | Item text list |
| `current` | Current selection (**a row index**) |
| `multi` | Allow multi-select |
| `checkable` | Items carry checkboxes |

```php
WidgetTree::list($state->names(), $state->selectedIndex, ['id' => 'tasks', 'grow' => 1]);
```

**Events**: `select` (single click), `activate` (double click); `value` is the row id (or the index string when there is no id).

## `table` — table

```php
WidgetTree::table(array $columns, array $rows, array $props = [])
```

| Property | Meaning |
|---|---|
| `columns` | Header text list |
| `rows` | Row data; each row is a list of cells |
| `row_ids` | Row id list (**strongly recommended**) |
| `row_colors` | Per-row background color (`''` = default, no fill); e.g. `['#f8d7da', '', '#d4edda']` |
| `current` | Current selection (**a row id**) |
| `headers_visible` | Show the header row |
| `multi` | Allow multi-select |
| `stretch_last` | Let the last column stretch |
| `checkable` | Rows carry checkboxes |

```php
WidgetTree::table(
    ['Name', 'Qty', 'Note'],
    [['Apple', '1', ''], ['Banana', '2', ''], ['Orange', '3', '']],
    [
        'id' => 'tbl',
        'row_ids' => ['r1', 'r2', 'r3'],
        'current' => $state->selectedId,      // select by row id
        'stretch_last' => true,
    ]
);
```

### Why you should always pass `row_ids`

Without `row_ids`, a row id degrades to the **index string** (`'0'`, `'1'`, …). Insert a row then, and the previously selected `'1'` points at **different data** — the selection is misplaced.

With `row_ids`, the diff engine recovers the selection by id, so inserting a row or swapping the data never misplaces it:

```php
// insert at the top — the selection still follows r2, it does not jump
$rows = array_merge([['New row', '0', '']], $rows);
$ids  = array_merge(['r0'], $ids);
```

**Events**: `select` (single click), `activate` (double click); `value` is the row id.

```php
$app->on('tbl', 'select', function (array $event) use ($state) {
    $state->selectedId = (string) $event['value'];
});
```

With `select_mode` set to `multi` (or `extended`), the `select` payload also carries the **whole
selection**, so you can act on more than the row under the cursor:

| Field | Meaning |
|---|---|
| `rows` | Selected **row indexes**, comma-joined (e.g. `"0,2,3"`) |
| `row_ids` | The corresponding **row ids**, comma-joined |
| `count` | Number of selected rows |

Comma-joined strings rather than arrays: the bridge `Array` only reliably carries scalars, so the
PHP side does `explode(',', $event['row_ids'])`. In single-select mode `rows` is always one entry,
so this is backwards compatible.

## `tree` — tree

```php
WidgetTree::tree(array $nodes, array $props = [])
```

Node structure:

```php
$nodes = [
    [
        'id' => 'src',                    // node id (falls back to the node text if omitted)
        'text' => 'src',
        'children' => [
            ['id' => 'main', 'text' => 'main.php'],
            ['id' => 'util', 'text' => 'Util.php'],
        ],
    ],
    ['id' => 'tests', 'text' => 'tests'],
];
```

| Property | Meaning |
|---|---|
| `nodes` | Tree nodes (see above) |
| `headers` | Column headers (multi-column tree) |
| `current` | Current selection (**a node id**) |
| `headers_visible` | Show the header row |

```php
WidgetTree::tree($state->nodes(), ['id' => 'file_tree', 'current' => $state->currentNode]);
```

**Events**: `select`, `activate`; `value` is the node id.

## The three side by side

This is the easiest thing to mix up:

| Control | `current` takes | Event `value` is |
|---|---|---|
| `list` | an **index** (int) | the row id (index string when there is no id) |
| `table` | a **row id** | the row id |
| `tree` | a **node id** | the node id |

```php
// list: select by index
WidgetTree::list(['A', 'B', 'C'], 1, ['id' => 'lst']);

// table: select by row id
WidgetTree::table(['Name'], $rows, ['id' => 'tbl', 'row_ids' => ['r1', 'r2'], 'current' => 'r2']);

// tree: select by node id
WidgetTree::tree($nodes, ['id' => 'tr', 'current' => 'main']);
```

::: tip Selection is recovered when `current` is omitted
On re-render the framework recovers the previous selection by row id / node id. So **only pass `current` when you need to change the selection programmatically** — after the user clicks a row you do not have to write it back into state, the diff preserves it.
:::

## Full example: a filterable table

```php
$state = new class {
    public string $query = '';
    public string $selectedId = '';
    public array $all = [
        ['id' => 'r1', 'name' => 'Apple',  'qty' => '12', 'status' => 'In stock'],
        ['id' => 'r2', 'name' => 'Banana', 'qty' => '3',  'status' => 'Out'],
        ['id' => 'r3', 'name' => 'Orange', 'qty' => '7',  'status' => 'In stock'],
    ];

    public function filtered(): array
    {
        if ($this->query === '') return $this->all;
        return array_values(array_filter(
            $this->all,
            fn(array $r) => str_contains($r['name'], $this->query)
        ));
    }
};

$app->view(function () use ($state): array {
    $rows = $state->filtered();
    return WidgetTree::vbox([
        WidgetTree::lineEdit($state->query, ['id' => 'q', 'placeholder' => 'Filter by name']),
        WidgetTree::table(
            ['Name', 'Qty', 'Status'],
            array_map(fn(array $r) => [$r['name'], $r['qty'], $r['status']], $rows),
            [
                'id' => 'tbl',
                'row_ids' => array_column($rows, 'id'),   // <- the key part
                'current' => $state->selectedId,
                'stretch_last' => true,
                'grow' => 1,
            ]
        ),
        WidgetTree::label(count($rows) . ' items', ['id' => 'count']),
    ]);
});

$app->on('q', 'change', function (array $event) use ($state) {
    $state->query = (string) $event['value'];
});

$app->on('tbl', 'select', function (array $event) use ($state) {
    $state->selectedId = (string) $event['value'];
});
```

After filtering, `row_ids` changes with the data, but the selection still follows the **row id** — that is the value of `row_ids`.
