# 对话框与系统集成

## 消息框

```php
$app->alert('操作完成');                      // 提示
$app->error('保存失败：磁盘已满');             // 错误
$app->confirm('确定要删除吗？');               // 确认，返回 bool
$app->message(['type' => 'info', 'default' => 'ok']);   // 底层接口，返回按下的按钮
```

```php
$app->on('del_btn', 'click', function () use ($app, $state) {
    if ($app->confirm('确定删除「' . $state->currentTitle . '」？')) {
        $state->deleteCurrent();
    }
});
```

::: warning 模态对话框在无头环境会永久阻塞
`confirm()` / `alert()` 底层是 `QMessageBox::exec()`，它会**自旋一个嵌套事件循环**直到有人点。CI、`--selftest` 这类没有人的场景下会永久挂住。

用 `headless(true)` 绕开 —— 消息框直接返回 `default`，不碰模态 API：

```php
$app->headless(true);
$app->confirm('确定吗？');   // 立即返回 false（或 spec 里的 default），不弹窗
```

`--selftest` 会自己打开无头模式。详见 [无头验收](/zh/advanced/headless.md)。
:::

## 文件对话框

```php
// 打开文件：返回 ['path' => '...', 'name' => '...']，取消则返回空数组
$picked = $app->openFile('选择图片', '图片 (*.png *.jpg)');
if ($picked !== []) {
    $state->imagePath = $picked['path'];
}

// 保存文件
$target = $app->saveFile('另存为', '文本 (*.txt)', 'untitled.txt');

// 选目录
$dir = $app->pickDirectory('选择输出目录');
```

过滤器语法是 Qt 的：`'描述 (*.ext *.ext2)'`，多个过滤器用 `;;` 分隔。

```php
$picked = $app->openFile('打开', '文本 (*.txt);;所有文件 (*)');
```

无头模式下这三个都返回空（`[]` 或 `''`），不会阻塞。

## 剪贴板

```php
$app->clipboardWrite('要复制的内容');
$text = $app->clipboardRead();
```

```php
$app->on('copy_btn', 'click', function () use ($app, $state) {
    $app->clipboardWrite($state->currentBody);
    $app->setStatus(['已复制到剪贴板']);
});
```

## 系统通知

```php
$app->notify('构建完成', '耗时 12.3 秒');
```

无托盘可用时 Qt 会把 `showMessage` 回退成**模态消息框** —— 所以 `notify()` 也受 `headless` 保护，无头模式下直接返回。

## 窗口标题与尺寸

```php
$app->setTitle('项目 — 已修改');
$app->resize(1024, 768);
```

这两个是命令式的，**不在控件树里**，所以不参与 diff —— 想改就调，不用等下一帧。

## 状态栏

状态栏是窗口底部的分段文本：

```php
$app->setStatus(['就绪', '共 3 项']);
```

也可以后面更新（同样不参与 diff）：

```php
$app->on('save_btn', 'click', function () use ($app, $state) {
    $state->save();
    $app->setStatus(['已保存', '共 ' . count($state->tasks) . ' 项']);
});
```

## 菜单栏

```php
$app->setMenu([
    ['type' => 'menu', 'text' => '文件', 'children' => [
        ['type' => 'item', 'id' => 'menu.new',  'text' => '新建', 'shortcut' => 'Ctrl+N'],
        ['type' => 'item', 'id' => 'menu.save', 'text' => '保存', 'shortcut' => 'Ctrl+S'],
        ['type' => 'separator'],
        ['type' => 'item', 'id' => 'menu.quit', 'text' => '退出', 'shortcut' => 'Ctrl+Q'],
    ]],
    ['type' => 'menu', 'text' => '视图', 'children' => [
        ['type' => 'item', 'id' => 'menu.wrap', 'text' => '自动换行', 'checked' => $state->wrap],
    ]],
]);
```

菜单项点击发 `menu` 事件；带 `checked` 的项在 `payload.checked` 里上报新状态：

```php
$app->on('menu.wrap', 'menu', function (array $event) use ($state) {
    $state->wrap = (bool) ($event['payload']['checked'] ?? false);
});
```

菜单**不在控件树里**，所以每次状态变了要重新 `setMenu()` —— 或者只在初始化时设一次（如果菜单是静态的）。

## 托盘

```php
$app->setTray([
    'tooltip' => 'MyApp · 左键点一下',
    'visible' => true,
    'icon' => 'assets/icon.png',      // 可省略，但强烈建议给（见下方警告）
    'menu' => [                        // 可省略：右键菜单
        ['type' => 'item', 'id' => 'tray.show', 'text' => '显示主窗口'],
        ['type' => 'item', 'id' => 'tray.quit', 'text' => '退出'],
    ],
]);
```

- 托盘激活发**不带 id** 的 `['type' => 'tray']`，只能用 `onAny('tray', …)` 接。
  `value` 里是手势：`left` / `right` / `double` / `middle`。见[事件](/zh/guide/events.md#托盘)。
- `menu` 可省略。绑了之后右击弹出菜单，菜单项发 `menu` 事件（按惯例用 `tray.` 前缀区分）。
- `icon` 可以不传 —— 桥接会兜底用窗口图标，窗口也没图标时用系统标准图标。
  **macOS / Linux 上无图标的托盘项根本不显示**，所以兜底不是美化，是可用性。
- 相对路径的 `icon` 按**可执行文件所在目录**解析（macOS 的 `.app` 里再兜一层
  `Contents/Resources`，最后才试工作目录），所以 `'assets/icon.png'` 在项目目录、
  `build/`、`dist/` 和打包好的 `.app` 里都成立。

::: warning 「我调了 setTray，但看不到图标」
Windows 的通知区域**默认隐藏新出现的图标** —— 新应用的图标会落进 `^` 箭头后面的溢出面板，
而不是直接显示在任务栏上。这是 Windows 自身的行为，不是框架的问题。

想确认托盘项真的建出来了，查 Windows 为它写的注册表条目：

```powershell
Get-ChildItem 'HKCU:\Control Panel\NotifyIconSettings' | ForEach-Object {
  $p = Get-ItemProperty $_.PSPath
  if ($p.ExecutablePath -like '*<你的应用>*') {
    "$($p.ExecutablePath)  IsPromoted=[$($p.IsPromoted)]"
  }
}
```

`IsPromoted` 为空 = 在溢出区。点一下 `^` 箭头，或把图标拖到任务栏上即可常驻。

另外两个同样**静默**的原因也值得排除：

- **图标没加载上。** 路径写错会得到空的 `QIcon`，而无图标的托盘项根本不显示。
  桥接现在会逐级兜底（显式路径 → 窗口图标 → 系统图标），并把
  `tray icon could not be loaded: <路径>` 打到 stderr。
- **`assets/` 没进 `build/`。** `qtphp build` 会自动拷；如果是构建之后才放的文件，重新构建。
:::
- 托盘不可用时（如无桌面会话的 CI）`setTray` 是空操作，不报错。

## 定时器

```php
$app->setTimer('clock', 1000);    // 注册，间隔毫秒
$app->setTimer('clock', 0);       // 停（间隔 <= 0 即停）
$app->setTimer('clock', 500);     // 改间隔

$app->on('clock', 'timer', function () use ($state) {
    $state->ticks++;
});
```

定时器到点发 `['type' => 'timer', 'id' => 'clock']`。

::: tip 注册时机
在 `run()` 之前注册所有处理器和定时器。定时器在 `run()` 里才真的开始跳。
:::
