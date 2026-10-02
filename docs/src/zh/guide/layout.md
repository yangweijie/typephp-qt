# 布局

## 四种容器

| 容器 | 排列方式 | 用途 |
|---|---|---|
| `vbox` | 垂直堆叠 | 最常见：表单、页面主体 |
| `hbox` | 水平排列 | 一行按钮、标签+输入框 |
| `grid` | 行列网格 | 对齐的表格状布局 |
| `form` | 标签+字段两列 | 设置表单 |

```php
WidgetTree::vbox([
    WidgetTree::label('标题', ['id' => 'title']),
    WidgetTree::hbox([
        WidgetTree::lineEdit('', ['id' => 'input', 'grow' => 1]),
        WidgetTree::button('确定', ['id' => 'ok']),
    ]),
    WidgetTree::grid([
        WidgetTree::label('姓名', ['row' => 0, 'col' => 0]),
        WidgetTree::lineEdit('', ['id' => 'name', 'row' => 0, 'col' => 1]),
        WidgetTree::label('邮箱', ['row' => 1, 'col' => 0]),
        WidgetTree::lineEdit('', ['id' => 'mail', 'row' => 1, 'col' => 1]),
    ]),
]);
```

## 嵌套

容器可以任意嵌套 —— 这是构建复杂界面的主要方式：

```php
WidgetTree::hbox([
    // 左：固定宽度的侧栏
    WidgetTree::vbox([
        WidgetTree::label('任务列表'),
        WidgetTree::list($names, 0, ['id' => 'tasks', 'grow' => 1]),
    ], ['size' => [220, 0]]),

    // 右：占满剩余，内部再分上下
    WidgetTree::vbox([
        WidgetTree::label($state->currentTitle, ['id' => 'detail_title']),
        WidgetTree::textEdit($state->currentBody, ['id' => 'detail_body', 'grow' => 1]),
        WidgetTree::hbox([
            WidgetTree::button('保存', ['id' => 'save']),
            WidgetTree::button('删除', ['id' => 'del']),
            WidgetTree::spacer(1),              // 把后面的推到右边
            WidgetTree::label('就绪', ['id' => 'hint']),
        ]),
    ], ['grow' => 1]),
]);
```

## `spacer` —— 撑开空间

`spacer` 不落任何控件，只占位。它的作用是**把相邻项推开**：

```php
WidgetTree::hbox([
    WidgetTree::button('左', ['id' => 'l']),
    WidgetTree::spacer(1),                    // 弹性，吃掉所有多余空间
    WidgetTree::button('右', ['id' => 'r']),  // 被推到最右
]);
```

`spacer(1)` 是弹性的（权重 1）；`spacer(20)` 是固定 20px 的空隙。

## `separator` —— 分隔线

```php
WidgetTree::vbox([
    WidgetTree::label('上面'),
    WidgetTree::separator(),
    WidgetTree::label('下面'),
]);
```

## 尺寸策略

三个属性配合：

| 属性 | 含义 |
|---|---|
| `size => [w, h]` | **固定**尺寸，覆盖自动计算 |
| `grow => n` | **伸展权重**，多余空间按权重分 |
| `min_size` / `max_size` | 约束边界 |

```php
// 侧栏固定 180，主区吃掉剩余
WidgetTree::hbox([
    WidgetTree::vbox([...], ['size' => [180, 0]]),
    WidgetTree::vbox([...], ['grow' => 1]),
]);
```

`size` 里写 `0` 表示该方向不固定：

```php
['size' => [180, 0]]   // 宽固定 180，高度由布局决定
```

## 滚动区

内容可能超出可视区域时，套一层 `scroll`：

```php
WidgetTree::scroll([
    WidgetTree::vbox($manyRows),
]);
```

`scroll` 只放**一个**子节点（通常是个 `vbox`）。

## 标签页与堆叠

`tabs` 是可见的标签栏；`stack` 是同一位置切换但**没有标签栏**（靠代码切）：

```php
// 有标签栏
WidgetTree::tabs([
    WidgetTree::tab('常规', [ /* … */ ]),
    WidgetTree::tab('高级', [ /* … */ ]),
], ['id' => 'settings_tabs']);

// 无标签栏，用 current 切换
WidgetTree::stack([
    WidgetTree::page([ /* 页 1 */ ]),
    WidgetTree::page([ /* 页 2 */ ]),
], ['id' => 'wizard', 'current' => $state->step]);
```

切页会发 `tab` 事件（带 `payload.index`）。

## 分割器

`split` 让用户拖动调整两栏比例：

```php
WidgetTree::split([
    WidgetTree::list($names, 0, ['id' => 'tasks']),
    WidgetTree::textEdit('', ['id' => 'detail']),
], ['orientation' => 'h', 'sizes' => [220, 500]]);
```

## 分组框

`group` 带标题边框，用于把相关控件归到一起：

```php
WidgetTree::group('设置', [
    WidgetTree::checkbox('深色文字', $state->dark, ['id' => 'dark']),
    WidgetTree::checkbox('自动保存', $state->autoSave, ['id' => 'auto']),
]);
```

`frame` 是不带标题的容器，主要用于给一组控件套统一的 `style` 或 `visible`。

## 完整例子：主从布局

左边列表、右边详情、底部按钮 —— 最常见的桌面布局：

```php
$app->view(function () use ($state): array {
    return WidgetTree::vbox([
        // 顶部工具条
        WidgetTree::hbox([
            WidgetTree::lineEdit($state->query, ['id' => 'search', 'placeholder' => '搜索…', 'grow' => 1]),
            WidgetTree::button('新建', ['id' => 'new']),
        ]),

        WidgetTree::separator(),

        // 主体：左列表右详情
        WidgetTree::split([
            WidgetTree::list($state->names(), $state->selected, ['id' => 'tasks']),
            WidgetTree::vbox([
                WidgetTree::label($state->currentTitle, ['id' => 'title', 'style' => 'font-size:18px;font-weight:bold;']),
                WidgetTree::textEdit($state->currentBody, ['id' => 'body', 'grow' => 1]),
            ]),
        ], ['orientation' => 'h', 'sizes' => [240, 520], 'grow' => 1]),

        // 底部状态行
        WidgetTree::hbox([
            WidgetTree::label("共 {$state->count()} 项", ['id' => 'count']),
            WidgetTree::spacer(1),
            WidgetTree::button('删除', ['id' => 'del', 'enabled' => $state->hasSelection()]),
        ]),
    ]);
});
```
