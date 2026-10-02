# 状态与视图

声明式模式写顺手的关键，是把「状态」和「界面」彻底分开。这一页讲怎么分。

## 状态放什么

**放**：能被界面显示或影响界面的一切。

- 输入框的当前文本、勾选状态、下拉选中项
- 列表/表格的数据和选中行
- 计数器、进度值、加载中标志
- 派生的显示文本（可以在 `view()` 里现算，也可以缓存进状态）

**不放**：控件的实例、控件的直接引用。你永远不该持有「那个按钮对象」。

## 两种写法

### 状态是数组（小应用推荐）

```php
$state = ['name' => 'World', 'clicks' => 0];

$app->view(function () use (&$state): array {
    return WidgetTree::vbox([
        WidgetTree::lineEdit($state['name'], ['id' => 'name_input']),
        WidgetTree::label("点了 {$state['clicks']} 次", ['id' => 'count']),
    ]);
});
```

`use (&$state)` 的引用很重要 —— 处理器改的必须是**同一个**数组。忘了 `&` 会改到副本上，界面看起来"没反应"。

### 状态是对象（大应用推荐）

状态一多，数组会变成一堆字符串键。用你自己的类：

```php
final class AppState
{
    public string $name = 'World';
    public int $clicks = 0;
    /** @var list<Task> */
    public array $tasks = [];
}

$state = new AppState();

$app->view(function () use ($state): array {
    return WidgetTree::vbox([
        WidgetTree::label("你好，{$state->name}", ['id' => 'greeting']),
        WidgetTree::label("任务数：" . count($state->tasks), ['id' => 'count']),
    ]);
});
```

对象是引用传递，`use ($state)` 不用加 `&`。而且能挂方法：

```php
$app->on('add_btn', 'click', function () use ($app, $state) {
    $title = $app->text('new_task');
    if ($title !== '') {
        $state->tasks[] = new Task($title);   // 领域逻辑在类里
    }
});
```

## 派生值：现算还是缓存

能在 `view()` 里一行算出来的，就别进状态 —— 少一份要同步的东西：

```php
// 好：从状态派生
WidgetTree::label('剩余 ' . count(array_filter($state->tasks, fn($t) => !$t->done)) . ' 项')
```

只有当计算很贵（大表格排序、磁盘读取）时才缓存进状态，并在数据变化时失效。

## 读控件值

处理器里要拿用户输入，用 `text()` / `value()` / `checked()`：

```php
$app->on('save_btn', 'click', function () use ($app, $state) {
    $state->name = $app->text('name_input');       // 字符串
    $state->level = $app->value('level_slider');   // mixed（数值类返回数值）
    $state->agree = $app->checked('agree_box');    // bool
});
```

**更推荐的做法是从事件里拿**，避免"读控件"这个动作：

```php
$app->on('name_input', 'change', function (array $event) use ($state) {
    $state->name = (string) $event['value'];   // 事件自带新值
});
```

两种都行。事件里的 `value` 是控件上报的即时值；`text()` 是从控件回读 —— 在控件已被重建的边界情况下两者可能不同，事件值更可靠。

## 常见坑

### 忘了 `&`，界面不更新

```php
$app->view(function () use ($state) { ... });   // 数组：改的是副本！
$app->view(function () use (&$state) { ... });  // 正确
```

### 在处理器里改控件

```php
// 不要这样
$app->on('btn', 'click', function () use ($app) {
    $app->setTitle('新标题');     // 命令式，和声明式视图打架
});

// 这样
$app->on('btn', 'click', function () use (&$state) {
    $state['title'] = '新标题';   // 下一帧 view() 会反映出来
});
```

少数确实是命令式的东西（窗口标题、菜单、状态栏、托盘、定时器）走 `setTitle()` / `setMenu()` 这类 API —— 它们**不在控件树里**，所以不参与 diff。

### 视图里做副作用

`view()` 每帧都会执行（约 60 次/秒）。里面不要写文件、不要发网络请求、不要改状态：

```php
// 不要
$app->view(function () use ($state) {
    file_put_contents('log.txt', 'render');   // 每秒写 60 次
    return WidgetTree::label('...');
});
```

## 用 `patch()` 处理热路径

日志流、进度刷新这类每秒改很多次的地方，让整个视图每帧重建太浪费。`patch()` 是命令式旁路：

```php
$app->patch([
    ['op' => 'call', 'id' => 'log', 'method' => 'appendRows', 'args' => [[['10:32', '启动']]]],
]);
```

**但它只是旁路** —— 下一次 `render()` 一律以树为准重新同步，所以追加的行只在这次渲染前有效。要长期存在就得写回状态。详见 [增量补丁](/zh/guide/patching.md)。
