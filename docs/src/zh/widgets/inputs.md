# 输入控件

## `lineEdit` —— 单行输入

```php
WidgetTree::lineEdit(string $text = '', array $props = [])
```

| 属性 | 说明 |
|---|---|
| `text` | 当前文本 |
| `placeholder` | 占位提示 |
| `password` | 密码模式（显示为圆点） |
| `clear_button` | 显示清除按钮 |
| `max_length` | 最大字符数 |
| `readonly` | 只读 |

```php
WidgetTree::lineEdit($state->name, [
    'id' => 'name_input',
    'placeholder' => '输入名字',
    'clear_button' => true,
]);
```

**事件**：`change`（每次输入）、`submit`（回车）

```php
$app->on('name_input', 'change', function (array $event) use ($state) {
    $state->name = (string) $event['value'];
});

$app->on('name_input', 'submit', function (array $event) use ($state) {
    $state->search();     // 只在回车时搜
});
```

## `textEdit` —— 多行输入

```php
WidgetTree::textEdit(string $text = '', array $props = [])
```

| 属性 | 说明 |
|---|---|
| `text` | 内容 |
| `wrap` | 自动换行 |
| `readonly` | 只读 |

```php
WidgetTree::textEdit($state->body, ['id' => 'body', 'wrap' => true, 'grow' => 1]);
```

**事件**：`change`

::: tip 多行输入没有 `submit`
回车在 `textEdit` 里是换行，不是提交。要"提交"请配一个按钮。
:::

## `spin` / `doubleSpin` —— 数字框

```php
WidgetTree::spin(int $value = 0, array $props = [])
WidgetTree::doubleSpin(float $value = 0.0, array $props = [])
```

| 属性 | 说明 |
|---|---|
| `value` | 当前值 |
| `min` / `max` | 范围 |
| `step` | 步长 |
| `decimals` | 小数位（仅 `doubleSpin`） |
| `prefix` / `suffix` | 前后缀 |

```php
WidgetTree::spin($state->count, ['id' => 'count', 'min' => 0, 'max' => 999, 'suffix' => ' 项']);
WidgetTree::doubleSpin($state->price, ['id' => 'price', 'decimals' => 2, 'prefix' => '¥', 'step' => 0.5]);
```

**事件**：`change`

## `slider` —— 滑块

```php
WidgetTree::slider(int $value = 0, array $props = [])
```

| 属性 | 说明 |
|---|---|
| `value` `min` `max` `step` | 同上 |
| `orientation` | `h` / `v` |
| `ticks` | 刻度数 |

```php
WidgetTree::slider($state->volume, ['id' => 'vol', 'min' => 0, 'max' => 100, 'ticks' => 11]);
```

**事件**：`change`

## `checkbox` —— 复选框

```php
WidgetTree::checkbox(string $text = '', bool $checked = false, array $props = [])
```

```php
WidgetTree::checkbox('深色文字', $state->dark, ['id' => 'dark_toggle']);
```

**事件**：`toggle`，`value` 是 `'0'` / `'1'` 字符串：

```php
$app->on('dark_toggle', 'toggle', function (array $event) use ($state) {
    $state->dark = $event['value'] === '1';    // 注意是字符串
});
```

也可以用 `$app->checked('dark_toggle')` 回读。

## `radio` —— 单选框

```php
WidgetTree::radio(string $text = '', bool $checked = false, array $props = [])
```

同一容器里的多个 `radio` 自动互斥：

```php
WidgetTree::group('导出格式', [
    WidgetTree::radio('CSV', $state->format === 'csv', ['id' => 'fmt_csv']),
    WidgetTree::radio('JSON', $state->format === 'json', ['id' => 'fmt_json']),
    WidgetTree::radio('XML', $state->format === 'xml', ['id' => 'fmt_xml']),
]);
```

**事件**：`toggle`

## `combo` —— 下拉框

```php
WidgetTree::combo(array $items, string $value = '', array $props = [])
```

| 属性 | 说明 |
|---|---|
| `items` | 条目文本列表 |
| `current` | 当前选中（**文本**，不是索引） |
| `editable` | 允许手动输入 |

```php
WidgetTree::combo(['浅色', '深色', '跟随系统'], $state->theme, ['id' => 'theme_combo']);
```

**事件**：`change`，`value` 是选中文本，`payload.index` 是索引：

```php
$app->on('theme_combo', 'change', function (array $event) use ($state) {
    $state->theme = (string) $event['value'];
    $state->themeIndex = (int) $event['payload']['index'];
});
```

::: warning `current` 收文本，不是索引
这和其他控件不同 —— `list` 收索引，`combo` 收文本。传错会静默选不中。
:::

## 一屏示例

```php
WidgetTree::form([
    WidgetTree::label('名称'),
    WidgetTree::lineEdit($state->name, ['id' => 'name', 'placeholder' => '必填']),

    WidgetTree::label('数量'),
    WidgetTree::spin($state->qty, ['id' => 'qty', 'min' => 1, 'max' => 100]),

    WidgetTree::label('单价'),
    WidgetTree::doubleSpin($state->price, ['id' => 'price', 'decimals' => 2, 'prefix' => '¥']),

    WidgetTree::label('分类'),
    WidgetTree::combo(['图书', '电子', '服饰'], $state->category, ['id' => 'cat']),

    WidgetTree::label('备注'),
    WidgetTree::textEdit($state->note, ['id' => 'note', 'wrap' => true]),

    WidgetTree::label(''),
    WidgetTree::checkbox('加急处理', $state->urgent, ['id' => 'urgent']),
]);
```
