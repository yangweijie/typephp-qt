# 事件与处理器

## 注册

```php
// 按 id + 类型注册
$app->on('greet_btn', 'click', function () use (&$state) { ... });

// 不带 id 的事件（如托盘点击）只能按类型注册
$app->onAny('tray', function () use (&$state) { ... });
```

## 处理器签名

处理器可以**只声明它需要的参数**：

```php
$app->on('btn', 'click', function () { ... });              // 不要事件
$app->on('input', 'change', function (array $event) { ... }); // 要事件
```

框架在**注册时**用反射探测必需参数个数，分发时按实际个数调用。

::: warning 这不是可选的优化
AOT 编译后的闭包对实参个数做**精确校验**，多传一个就抛 `ArgumentCountError`；普通 PHP 解释器会静默忽略。所以 `function () {}` 形式的处理器在解释器下全对、在编译产物里**一点那个按钮就崩**。

框架已经替你处理了（注册时探测 arity），但你**自己写回调**时要留意这一点。详见 [AOT 注意事项](/zh/advanced/aot-notes.md#闭包实参个数必须精确匹配)。
:::

## 事件类型

| 事件 | 触发控件 | `value` | `payload` |
|---|---|---|---|
| `click` | button, link | link 的 href | — |
| `change` | lineedit, textedit, spin, doublespin, slider, combo | 新值 | combo 带 `index` |
| `submit` | lineedit | 文本 | — |
| `toggle` | checkbox, radio | `'0'` / `'1'` | — |
| `select` | list, table, tree | 行 / 项 id | `index` |
| `activate` | list, table, tree | 行 / 项 id | — |
| `tab` | tabs, stack | 索引 | `index` |
| `menu` | 菜单项 | — | `checked` |
| `timer` | 定时器 | — | — |
| `tray` | 系统托盘 | — | — |

事件对象是一个关联数组：

```php
[
    'type'    => 'click',        // 事件类型
    'id'      => 'greet_btn',    // 控件 id
    'value'   => '...',          // 新值 / 行 id（视类型而定）
    'payload' => ['index' => 2], // 额外结构（可选）
]
```

## 各类型的完整例子

### 按钮

```php
$app->on('save_btn', 'click', function () use ($app, $state) {
    $state->save();
});
```

### 输入框 —— 实时 vs 回车

```php
// 每次输入都触发
$app->on('search_input', 'change', function (array $event) use ($state) {
    $state->query = (string) $event['value'];
});

// 只在回车时触发
$app->on('search_input', 'submit', function (array $event) use ($state) {
    $state->query = (string) $event['value'];
    $state->search();
});
```

### 勾选 / 单选

```php
$app->on('dark_toggle', 'toggle', function (array $event) use ($state) {
    $state->dark = $event['value'] === '1';   // 注意是字符串
});
```

### 表格 / 列表选中

```php
$app->on('task_table', 'select', function (array $event) use ($state) {
    $state->selectedId = (string) $event['value'];   // 行 id
});

$app->on('task_table', 'activate', function (array $event) use ($state) {
    $state->open((string) $event['value']);          // 双击
});
```

### 下拉框

```php
$app->on('theme_combo', 'change', function (array $event) use ($state) {
    $state->theme = (string) $event['value'];            // 文本
    $state->themeIndex = (int) $event['payload']['index']; // 索引
});
```

### 标签页

```php
$app->on('main_tabs', 'tab', function (array $event) use ($state) {
    $state->activeTab = (int) $event['payload']['index'];
});
```

### 菜单

```php
$app->on('menu.about', 'menu', function () use ($app) {
    $app->alert('TypePHP\\Qt 示例');
});

$app->on('menu.wrap', 'menu', function (array $event) use ($state) {
    $state->wrap = (bool) ($event['payload']['checked'] ?? false);
});
```

### 定时器

```php
$app->setTimer('clock', 1000);        // 每 1000ms 一次；间隔 <= 0 即停

$app->on('clock', 'timer', function () use ($state) {
    $state->ticks++;
});
```

### 托盘

托盘左键点击发的是**不带 id** 的事件，只能用 `onAny`：

```php
$app->onAny('tray', function () use ($state) {
    $state->trayClicks++;
});
```

## 通配：`onAny`

```php
$app->onAny('click', function (array $event) use ($state) {
    $state->lastClicked = $event['id'];   // 所有按钮的点击都汇到这里
});
```

`on($id, $type, …)` 和 `onAny($type, …)` 可以同时注册，**两个都会触发**。

## 处理器里能做什么

只改状态。不要直接操作控件 —— 那是声明式视图的事。

少数命令式 API（不在控件树里，所以不参与 diff）例外：

```php
$app->setTitle('新标题');
$app->setStatus(['就绪', '共 3 项']);
$app->setMenu([...]);
$app->setTray([...]);
$app->setTimer('id', 500);
$app->resize(800, 600);
```

## 错误处理

处理器抛异常不会崩进程。框架捕获它、弹错误框、记进 `lastError()`，循环继续：

```php
$app->on('risky', 'click', function () {
    throw new RuntimeException('文件不存在');
});

// 之后
$app->lastError();   // '文件不存在 @ /path/main.php:42'
```

在无头模式（`headless(true)`）下不弹框，直接记录 —— 这样 `--selftest` 能跑完并报告哪个用例失败。
