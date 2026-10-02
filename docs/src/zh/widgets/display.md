# 展示控件

## `label` —— 文本标签

```php
WidgetTree::label(string $text, array $props = [])
```

| 属性 | 说明 |
|---|---|
| `text` | 文本 |
| `bold` | 粗体 |
| `font_size` | 字号（px） |
| `align` | `left` `right` `center` |
| `style` | QSS 片段（更灵活） |

```php
WidgetTree::label('标题', ['id' => 'title', 'bold' => true, 'font_size' => 22]);
WidgetTree::label('副标题', ['id' => 'sub', 'style' => 'color:#666;font-style:italic;']);
```

**事件**：无（标签不交互）

::: tip 动态文本
`view()` 每帧都会重新执行，所以直接把状态拼进字符串即可 —— 不需要"更新标签"这种操作：

```php
WidgetTree::label('点击次数：' . $state['clicks'], ['id' => 'count'])
```
:::

## `button` —— 按钮

```php
WidgetTree::button(string $text, array $props = [])
```

| 属性 | 说明 |
|---|---|
| `text` | 按钮文字 |
| `checkable` | 可切换（按下/弹起） |
| `checked` | 切换状态 |
| `flat` | 无边框 |
| `default` | 默认按钮（回车触发） |
| `enabled` | 是否可点 |

```php
WidgetTree::button('保存', ['id' => 'save', 'default' => true]);
WidgetTree::button('删除', ['id' => 'del', 'enabled' => $state->hasSelection()]);
```

**事件**：`click`

```php
$app->on('save', 'click', function () use ($state) {
    $state->save();
});
```

可切换按钮发 `toggle`：

```php
WidgetTree::button('加粗', ['id' => 'bold', 'checkable' => true, 'checked' => $state->bold]);

$app->on('bold', 'toggle', function (array $event) use ($state) {
    $state->bold = $event['value'] === '1';
});
```

## `progress` —— 进度条

```php
WidgetTree::progress(int $value = 0, array $props = [])
```

| 属性 | 说明 |
|---|---|
| `value` | 当前值 |
| `min` / `max` | 范围（默认 0–100） |

```php
WidgetTree::progress($state->progress, ['id' => 'bar', 'max' => 100]);
```

**事件**：无（只显示）

热路径上更新用 `patch()` 更省：

```php
$app->patch([['op' => 'set', 'id' => 'bar', 'props' => ['value' => 65]]]);
```

## `image` —— 图片

```php
WidgetTree::image(string $path, array $props = [])
```

| 属性 | 说明 |
|---|---|
| `path` | 图片路径 |
| `scaled_size` | 缩放尺寸 `[w, h]` |

```php
WidgetTree::image('assets/logo.png', ['id' => 'logo', 'scaled_size' => [120, 120]]);
```

::: warning 路径相对于可执行文件
相对路径按**产物所在目录**解析。所以 `'assets/logo.png'` 在开发目录和 `dist/` 里都成立 —— 前提是打包时把 `assets/` 一起拷过去（`qtphp package` 会做）。
:::

## `link` —— 链接

```php
WidgetTree::link(string $text, string $href, array $props = [])
```

```php
WidgetTree::link('项目主页', 'https://github.com/yangweijie/typephp-qt', ['id' => 'home']);
```

**事件**：`click`，`value` 是 href

```php
$app->on('home', 'click', function (array $event) {
    // $event['value'] 是 href —— 打开浏览器需要桥接额外支持
    error_log('点击了 ' . $event['value']);
});
```

## 一屏示例

```php
WidgetTree::vbox([
    WidgetTree::label('项目状态', ['id' => 'title', 'bold' => true, 'font_size' => 20]),

    WidgetTree::hbox([
        WidgetTree::image('assets/logo.png', ['id' => 'logo', 'scaled_size' => [48, 48]]),
        WidgetTree::vbox([
            WidgetTree::label($state->projectName, ['id' => 'name']),
            WidgetTree::link('查看源码', $state->repoUrl, ['id' => 'repo']),
        ], ['grow' => 1]),
    ]),

    WidgetTree::separator(),

    WidgetTree::label('构建进度：' . $state->progress . '%', ['id' => 'progress_label']),
    WidgetTree::progress($state->progress, ['id' => 'bar', 'max' => 100]),

    WidgetTree::hbox([
        WidgetTree::button('开始构建', ['id' => 'start', 'default' => true]),
        WidgetTree::button('取消', ['id' => 'cancel', 'enabled' => $state->running]),
        WidgetTree::spacer(1),
        WidgetTree::button('详细日志', ['id' => 'log', 'flat' => true]),
    ]),
]);
```
