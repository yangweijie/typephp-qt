# 容器

容器是布局节点，决定子项怎么排。

## `vbox` / `hbox` —— 线性布局

```php
WidgetTree::vbox(array $children, array $props = [])
WidgetTree::hbox(array $children, array $props = [])
```

| 属性 | 说明 |
|---|---|
| `spacing` | 子项间距（px） |
| `margin` | 外边距（px） |
| `grow` | 在父布局中的伸展权重 |

```php
WidgetTree::vbox([
    WidgetTree::label('标题'),
    WidgetTree::label('正文'),
], ['spacing' => 8, 'margin' => 12]);
```

## `grid` —— 网格

```php
WidgetTree::grid(array $children, array $props = [])
```

子项用 `row` / `col` 定位，可跨行跨列：

```php
WidgetTree::grid([
    WidgetTree::label('姓名', ['row' => 0, 'col' => 0]),
    WidgetTree::lineEdit('', ['id' => 'name', 'row' => 0, 'col' => 1]),
    WidgetTree::label('备注', ['row' => 1, 'col' => 0]),
    WidgetTree::textEdit('', ['id' => 'note', 'row' => 1, 'col' => 1, 'row_span' => 2]),
]);
```

| 属性 | 说明 |
|---|---|
| `row` / `col` | 网格位置（0 起） |
| `row_span` / `col_span` | 跨行 / 跨列数 |

## `form` —— 两列表单

标签在左、字段在右，自动对齐：

```php
WidgetTree::form([
    WidgetTree::label('用户名'),
    WidgetTree::lineEdit('', ['id' => 'user']),
    WidgetTree::label('密码'),
    WidgetTree::lineEdit('', ['id' => 'pass', 'password' => true]),
]);
```

子项**成对出现**：奇数位是标签，偶数位是字段。

## `group` —— 分组框

```php
WidgetTree::group(string $title, array $children, array $props = [])
```

带标题边框，把相关控件归到一起：

```php
WidgetTree::group('网络设置', [
    WidgetTree::checkbox('启用代理', $state->proxy, ['id' => 'proxy']),
    WidgetTree::hbox([
        WidgetTree::label('地址：'),
        WidgetTree::lineEdit($state->host, ['id' => 'host', 'grow' => 1]),
    ]),
]);
```

## `frame` —— 无标题容器

主要用于给一组控件套统一的 `style` 或 `visible`：

```php
WidgetTree::frame([
    WidgetTree::label('A'),
    WidgetTree::label('B'),
], ['visible' => $state->showDetails, 'style' => 'background:#f5f5f5;']);
```

## `scroll` —— 滚动区

```php
WidgetTree::scroll(array $children, array $props = [])
```

内容可能超出可视区时套一层。只放**一个**子节点（通常是个 `vbox`）：

```php
WidgetTree::scroll([
    WidgetTree::vbox($manyRows, ['spacing' => 4]),
]);
```

## `tabs` / `tab` —— 标签页

```php
WidgetTree::tabs(array $pages, array $props = [])
WidgetTree::tab(string $title, array $children, array $props = [])
```

```php
WidgetTree::tabs([
    WidgetTree::tab('常规', [
        WidgetTree::checkbox('开机启动', $state->autostart, ['id' => 'auto']),
    ]),
    WidgetTree::tab('高级', [
        WidgetTree::slider($state->threads, ['id' => 'threads', 'min' => 1, 'max' => 16]),
    ]),
], ['id' => 'settings', 'current' => $state->tab]);
```

| 属性 | 说明 |
|---|---|
| `tab_position` | `top` `bottom` `left` `right` |
| `current` | 当前页索引或标题 |

切换发 `tab` 事件，`payload.index` 是新索引：

```php
$app->on('settings', 'tab', function (array $event) use ($state) {
    $state->tab = (int) $event['payload']['index'];
});
```

## `stack` / `page` —— 无标签堆叠

和 `tabs` 一样是同一位置切换，但**没有标签栏** —— 靠 `current` 用代码切。适合向导、分步表单：

```php
WidgetTree::stack([
    WidgetTree::page([ WidgetTree::label('第一步') ]),
    WidgetTree::page([ WidgetTree::label('第二步') ]),
    WidgetTree::page([ WidgetTree::label('完成') ]),
], ['id' => 'wizard', 'current' => $state->step]);
```

同样发 `tab` 事件。

## `split` —— 可拖动分割

```php
WidgetTree::split(array $children, array $props = [])
```

用户能拖动调整两栏比例：

```php
WidgetTree::split([
    WidgetTree::list($names, 0, ['id' => 'tasks']),
    WidgetTree::textEdit('', ['id' => 'detail']),
], ['orientation' => 'h', 'sizes' => [220, 500]]);
```

| 属性 | 说明 |
|---|---|
| `orientation` | `h`（左右）或 `v`（上下） |
| `sizes` | 初始比例，如 `[220, 500]` |

## `spacer` —— 弹性占位

```php
WidgetTree::spacer(int $size = 0, array $props = [])
```

不落任何控件，只占位。**把相邻项推开**：

```php
WidgetTree::hbox([
    WidgetTree::button('左', ['id' => 'l']),
    WidgetTree::spacer(1),                    // 弹性，吃掉多余空间
    WidgetTree::button('右', ['id' => 'r']),  // 被推到最右
]);
```

`spacer(1)` 弹性；`spacer(20)` 是固定 20px 空隙。

## `separator` —— 分隔线

```php
WidgetTree::vbox([
    WidgetTree::label('上面'),
    WidgetTree::separator(),
    WidgetTree::label('下面'),
]);
```
