# 数据控件

列表、表格、树。这三个是「选中语义」最需要留意的控件 —— 它们的 `current` 和事件 `value` 口径各不相同。

## `list` —— 列表

```php
WidgetTree::list(array $items, string $value = '', array $props = [])
```

| 属性 | 说明 |
|---|---|
| `items` | 条目文本列表 |
| `current` | 当前选中（**行索引**） |
| `multi` | 允许多选 |
| `checkable` | 条目带复选框 |

```php
WidgetTree::list($state->names(), $state->selectedIndex, ['id' => 'tasks', 'grow' => 1]);
```

**事件**：`select`（单击）、`activate`（双击），`value` 是行 id（不给 id 时是索引字符串）。

## `table` —— 表格

```php
WidgetTree::table(array $columns, array $rows, array $props = [])
```

| 属性 | 说明 |
|---|---|
| `columns` | 表头文本列表 |
| `rows` | 行数据，每行是单元格列表 |
| `row_ids` | 行 id 列表（**强烈建议给**） |
| `row_colors` | 每行背景色（`''` = 默认不著色），如 `['#f8d7da', '', '#d4edda']` |
| `current` | 当前选中（**行 id**） |
| `headers_visible` | 是否显示表头 |
| `multi` | 允许多选 |
| `stretch_last` | 最后一列自动伸展 |
| `checkable` | 行带复选框 |

```php
WidgetTree::table(
    ['名称', '数量', '备注'],
    [['苹果', '1', ''], ['香蕉', '2', ''], ['橙子', '3', '']],
    [
        'id' => 'tbl',
        'row_ids' => ['r1', 'r2', 'r3'],
        'current' => $state->selectedId,      // 按行 id 选中
        'stretch_last' => true,
    ]
);
```

### 为什么一定要给 `row_ids`

不给 `row_ids` 时，行 id 退化成**索引字符串**（`'0'`、`'1'`…）。这时如果插了一行，原本选中的 `'1'` 会指向**另一条数据** —— 选中错位。

给了 `row_ids`，diff 引擎按 id 找回选中，插行、换数据都不会错位：

```php
// 头部插一行 —— 选中仍跟着 r2 走，不会跳到别处
$rows = array_merge([['新行', '0', '']], $rows);
$ids  = array_merge(['r0'], $ids);
```

**事件**：`select`（单击）、`activate`（双击），`value` 是行 id。

```php
$app->on('tbl', 'select', function (array $event) use ($state) {
    $state->selectedId = (string) $event['value'];
});
```

当 `select_mode` 设为 `multi`（或 `extended`）时，`select` 的 payload 还会带上**整个选区**，
这样就能对「光标所在行之外」的多行做操作：

| 字段 | 含义 |
|---|---|
| `rows` | 选中的**行号**，逗号串（如 `"0,2,3"`） |
| `row_ids` | 对应的**行 id**，逗号串 |
| `count` | 选中行数 |

用逗号串而不是数组：桥接的 `Array` 只稳定承载标量 —— PHP 侧 `explode(',', $event['row_ids'])` 还原。
单选模式下 `rows` 恒为一行，所以是向后兼容的。

## `tree` —— 树

```php
WidgetTree::tree(array $nodes, array $props = [])
```

节点结构：

```php
$nodes = [
    [
        'id' => 'src',                    // 节点 id（不给则退化成节点文本）
        'text' => 'src',
        'children' => [
            ['id' => 'main', 'text' => 'main.php'],
            ['id' => 'util', 'text' => 'Util.php'],
        ],
    ],
    ['id' => 'tests', 'text' => 'tests'],
];
```

| 属性 | 说明 |
|---|---|
| `nodes` | 树节点（见上） |
| `headers` | 列标题（多列树） |
| `current` | 当前选中（**节点 id**） |
| `headers_visible` | 是否显示表头 |

```php
WidgetTree::tree($state->nodes(), ['id' => 'file_tree', 'current' => $state->currentNode]);
```

**事件**：`select`、`activate`，`value` 是节点 id。

## 三个控件的口径对照

这是最容易搞混的地方：

| 控件 | `current` 收什么 | 事件 `value` 是什么 |
|---|---|---|
| `list` | **索引**（int） | 行 id（无 id 时是索引字符串） |
| `table` | **行 id** | 行 id |
| `tree` | **节点 id** | 节点 id |

```php
// list：按索引选中
WidgetTree::list(['A', 'B', 'C'], 1, ['id' => 'lst']);

// table：按行 id 选中
WidgetTree::table(['名称'], $rows, ['id' => 'tbl', 'row_ids' => ['r1', 'r2'], 'current' => 'r2']);

// tree：按节点 id 选中
WidgetTree::tree($nodes, ['id' => 'tr', 'current' => 'main']);
```

::: tip 不给 `current` 时会自动找回
重渲染时框架按行 id / 节点 id 找回上一次的选中。所以**只在需要程序化改选中时**才传 `current` —— 用户点选后你不用把它写回状态，diff 自己会保留。
:::

## 完整例子：可筛选表格

```php
$state = new class {
    public string $query = '';
    public string $selectedId = '';
    public array $all = [
        ['id' => 'r1', 'name' => '苹果', 'qty' => '12', 'status' => '在库'],
        ['id' => 'r2', 'name' => '香蕉', 'qty' => '3',  'status' => '缺货'],
        ['id' => 'r3', 'name' => '橙子', 'qty' => '7',  'status' => '在库'],
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
        WidgetTree::lineEdit($state->query, ['id' => 'q', 'placeholder' => '按名称筛选']),
        WidgetTree::table(
            ['名称', '数量', '状态'],
            array_map(fn(array $r) => [$r['name'], $r['qty'], $r['status']], $rows),
            [
                'id' => 'tbl',
                'row_ids' => array_column($rows, 'id'),   // ← 关键
                'current' => $state->selectedId,
                'stretch_last' => true,
                'grow' => 1,
            ]
        ),
        WidgetTree::label("共 " . count($rows) . " 项", ['id' => 'count']),
    ]);
});

$app->on('q', 'change', function (array $event) use ($state) {
    $state->query = (string) $event['value'];
});

$app->on('tbl', 'select', function (array $event) use ($state) {
    $state->selectedId = (string) $event['value'];
});
```

筛选后 `row_ids` 跟着变，但选中仍然跟着**行 id** 走 —— 这就是 `row_ids` 的价值。
