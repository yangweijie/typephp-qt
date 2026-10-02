# 控件目录

所有控件都由 `TypePHP\Qt\WidgetTree` 的静态方法构造，返回一个数组节点。节点形如：

```php
['type' => 'button', 'props' => ['id' => 'ok', 'text' => '确定'], 'children' => []]
```

但你不用手写这个结构 —— 用工厂方法：

```php
WidgetTree::button('确定', ['id' => 'ok']);
```

## 按类别查

| 类别 | 包含 |
|---|---|
| [容器](/zh/widgets/containers.md) | `vbox` `hbox` `grid` `form` `group` `frame` `scroll` `tabs` `tab` `stack` `page` `split` `spacer` `separator` |
| [输入](/zh/widgets/inputs.md) | `lineedit` `textedit` `spin` `doublespin` `slider` `checkbox` `radio` `combo` |
| [数据](/zh/widgets/data.md) | `list` `table` `tree` |
| [展示](/zh/widgets/display.md) | `label` `button` `progress` `image` `link` |

## 速查表

| 方法 | 说明 | 主要属性 |
|---|---|---|
| `vbox($children)` | 垂直布局 | `spacing` `margin` |
| `hbox($children)` | 水平布局 | `spacing` `margin` |
| `grid($children)` | 网格布局 | `row` `col` `row_span` `col_span` |
| `form($children)` | 两列表单 | — |
| `group($title, $children)` | 带标题分组框 | `title` |
| `frame($children)` | 无标题容器 | `style` |
| `scroll($children)` | 滚动区 | — |
| `tabs($pages)` | 标签页 | `tab_position` `current` |
| `tab($title, $children)` | 单个标签页 | `title` |
| `stack($pages)` | 无标签堆叠 | `current` |
| `page($children)` | 堆叠中的一页 | — |
| `split($children)` | 可拖动分割 | `orientation` `sizes` |
| `spacer($size)` | 弹性占位 | `size` |
| `separator()` | 分隔线 | — |
| `label($text)` | 文本标签 | `text` `bold` `font_size` `align` |
| `button($text)` | 按钮 | `text` `checkable` `checked` `flat` `default` |
| `lineEdit($text)` | 单行输入 | `text` `placeholder` `password` `clear_button` `max_length` |
| `textEdit($text)` | 多行输入 | `text` `wrap` `readonly` |
| `spin($value)` | 整数框 | `value` `min` `max` `step` `prefix` `suffix` |
| `doubleSpin($value)` | 浮点框 | 同上 + `decimals` |
| `slider($value)` | 滑块 | `value` `min` `max` `orientation` `ticks` |
| `progress($value)` | 进度条 | `value` `min` `max` |
| `checkbox($text, $checked)` | 复选框 | `checked` |
| `radio($text, $checked)` | 单选框 | `checked` |
| `combo($items, $current)` | 下拉框 | `items` `current` `editable` |
| `list($items, $current)` | 列表 | `items` `current` `multi` |
| `table($columns, $rows)` | 表格 | `columns` `rows` `row_ids` `current` |
| `tree($nodes)` | 树 | `nodes` `headers` `current` |
| `image($path)` | 图片 | `path` `scaled_size` |
| `link($text, $href)` | 链接 | `text` `href` |

## 所有节点通用

`id` `visible` `enabled` `tooltip` `style` `size` `min_size` `max_size` `align` `grow`

完整属性表见[属性](/zh/guide/properties.md)。

## 组合示例

```php
WidgetTree::vbox([
    WidgetTree::group('搜索', [
        WidgetTree::hbox([
            WidgetTree::lineEdit($state->query, ['id' => 'q', 'placeholder' => '关键词', 'grow' => 1]),
            WidgetTree::button('搜索', ['id' => 'go']),
        ]),
    ]),

    WidgetTree::table(
        ['名称', '数量', '状态'],
        $state->rows(),
        ['id' => 'tbl', 'row_ids' => $state->rowIds(), 'stretch_last' => true, 'grow' => 1]
    ),

    WidgetTree::hbox([
        WidgetTree::checkbox('只看未完成', $state->onlyPending, ['id' => 'filter']),
        WidgetTree::spacer(1),
        WidgetTree::progress($state->progress, ['id' => 'bar', 'max' => 100]),
    ]),
]);
```
