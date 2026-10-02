# 属性

属性是控件树节点上的关联数组，第三或第二个参数传进去。

```php
WidgetTree::label('你好', ['id' => 'greeting', 'style' => 'color:red', 'align' => 'center']);
```

## 通用属性（所有节点）

| 属性 | 类型 | 说明 |
|---|---|---|
| `id` | string | **稳定标识**，diff 与事件都靠它。你要读写的控件必须给 |
| `visible` | bool | 显隐 |
| `enabled` | bool | 是否可交互 |
| `tooltip` | string | 悬停提示 |
| `style` | string | Qt 样式表片段，如 `color:#1d4ed8;font-weight:bold;` |
| `size` | `[w, h]` | 固定尺寸 |
| `min_size` / `max_size` | `[w, h]` | 尺寸约束 |
| `align` | string | `left` `right` `center` |
| `grow` | int | 在布局中的伸展权重（0 = 不伸展） |

## 容器属性

| 属性 | 适用 | 说明 |
|---|---|---|
| `title` | group, tabs, tab | 标题文本 |
| `margin` | 布局类 | 外边距 |
| `spacing` | 布局类 | 子项间距 |
| `row` / `col` | grid, form | 网格位置 |
| `row_span` / `col_span` | grid | 跨行/跨列 |
| `orientation` | split | `h` / `v` |
| `sizes` | split | 初始分割比例，如 `[200, 400]` |
| `tab_position` | tabs | `top` `bottom` `left` `right` |

## 输入类属性

| 属性 | 适用 | 说明 |
|---|---|---|
| `text` | 文本类 | 文本内容 |
| `placeholder` | lineedit, textedit | 占位提示 |
| `readonly` | lineedit, textedit | 只读 |
| `password` | lineedit | 密码模式（显示为圆点） |
| `clear_button` | lineedit | 显示清除按钮 |
| `max_length` | lineedit | 最大字符数 |
| `wrap` | textedit | 自动换行 |

## 数值类属性

| 属性 | 适用 | 说明 |
|---|---|---|
| `value` | 全部数值类 | 当前值 |
| `min` / `max` | 全部数值类 | 范围 |
| `step` | spin, doublespin, slider | 步长 |
| `decimals` | doublespin | 小数位 |
| `prefix` / `suffix` | spin, doublespin | 前后缀，如 `¥` / `%` |
| `ticks` | slider | 刻度数 |

## 列表类属性

| 属性 | 适用 | 说明 |
|---|---|---|
| `items` | combo, list | 条目文本列表 |
| `current` | combo, list, table, tree, tabs, stack | **声明式选中**，口径见下 |
| `columns` | table | 表头文本列表 |
| `rows` | table | 行数据（每行是单元格列表） |
| `row_ids` | table | 行 id 列表 |
| `nodes` | tree | 树节点 |
| `headers` | tree | 列标题 |
| `headers_visible` | table, tree | 是否显示表头 |
| `multi` | list, table, tree | 允许多选 |
| `select_mode` | table, tree | 选择模式 |
| `stretch_last` | table | 最后一列自动伸展 |
| `checkable` | list, table, tree | 条目带复选框 |
| `editable` | combo | 可编辑 |

### `current` 的口径按控件不同

这是最容易踩的地方 —— **同名的 `current` 在不同控件上收不同类型**：

| 控件 | `current` 收什么 |
|---|---|
| `table` | **行 id**（不给 `row_ids` 时行 id 就是索引字符串 `'0'`、`'1'`…） |
| `tree` | **节点 id**（不给 `id` 时退化成节点文本） |
| `list` | 行索引 |
| `combo` / `tabs` / `stack` | 索引或文本 |

```php
// 表格：按行 id 选中
WidgetTree::table(['名称', '数量'], $rows, ['id' => 'tbl', 'row_ids' => ['r1', 'r2'], 'current' => 'r2']);

// 下拉：按文本选中
WidgetTree::combo(['浅色', '深色'], '深色', ['id' => 'theme']);

// 列表：按索引选中
WidgetTree::list(['A', 'B', 'C'], 1, ['id' => 'lst']);
```

### 不传 `current` 时会自动找回选中

重渲染时框架按**行 id / 节点 id** 找回上一次的选中，所以插行、换数据都不会选中错位。这是 diff 引擎的一部分，见 [diff 引擎](/zh/advanced/diff-engine.md)。

## 展示类属性

| 属性 | 适用 | 说明 |
|---|---|---|
| `href` | link | 链接地址（点击事件里作为 `value` 上报） |
| `path` | image | 图片路径 |
| `scaled_size` | image | 缩放尺寸 |
| `bold` | label | 粗体 |
| `font_size` | label, button | 字号 |
| `checkable` | button | 按钮可切换 |
| `flat` | button | 无边框按钮 |
| `checked` | button, checkbox, radio | 勾选状态 |
| `default` | button | 默认按钮（回车触发） |

## 未知属性会被忽略

写了不存在的属性不会报错，只是没效果 —— 和未知 `id`、未知 `patch` method 的处理一致。这让降级和拼写容错容易，但**打错字也不会告诉你**，所以要对着这张表核。

## 用 `style` 做视觉

`style` 是 Qt 样式表（QSS）片段，写在节点上：

```php
WidgetTree::label('标题', [
    'id' => 'title',
    'style' => 'font-size:22px;font-weight:bold;color:#1d4ed8;',
])
```

也可以全局设一次（在 `create()` 时）：

```php
$app->create(['name' => 'MyApp', 'stylesheet' => 'QPushButton { padding: 6px 14px; }']);
```

## 尺寸与布局的关系

- `size` 是**固定**尺寸，会覆盖布局的自动计算。
- `grow` 是**伸展权重** —— 布局有多余空间时按权重分配。`grow => 1` 的项吃掉所有多余空间。
- `min_size` / `max_size` 是约束，和 `grow` 配合用。

```php
WidgetTree::hbox([
    WidgetTree::label('侧栏', ['size' => [180, 0]]),      // 固定宽 180
    WidgetTree::textEdit('', ['id' => 'main', 'grow' => 1]), // 占满剩余
]);
```
