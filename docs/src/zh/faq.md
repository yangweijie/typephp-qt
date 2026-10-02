# 常见问题

## 点某个按钮就崩：`expects exactly 0 arguments, 1 given`

这是 AOT 的闭包实参个数校验 —— **最阴险的一个坑**，因为它只在点击那个控件时才炸，单元测试和不点东西的冒烟测试全都通过。

```php
$app->on('btn', 'click', function () { ... });   // 0 参处理器
```

框架已经处理了（注册时用反射探测参数个数），所以 `on()` / `onAny()` 注册的处理器两种写法都能用。如果你**自己**写分发逻辑，就要按处理器声明的个数调用。

完整说明：[AOT 注意事项](/zh/advanced/aot-notes.md#闭包实参个数必须精确匹配)

## 编译报 `All execution code must be within a function, found stray code`

用了 `require` / `require_once`。它在全局作用域**和**函数体内都算 stray code。

改用 `project.yml` 的 `sources:` 列表建立跨文件可见性：

```yaml
sources:
  - src/main.php
  - ../../src/QtApp.php
  - ../../src/WidgetTree.php
```

## 构建报 `The PHPX runtime library was not found at: ...\phpx\build\phpx.dll`

机器上有两个 tpc，选到了**没有运行时**的那个（composer 驱动需要 `vendor/swoole/phpx` 源码树，而它不含编译产物）。

```bash
qtphp doctor   # 看 "tpc" 和 "PHP 运行时库" 两行指向哪里
```

要强制指定：

```bash
TPC=/path/to/tpc.exe qtphp build .
```

完整说明：[两条 tpc 供给路线](/zh/advanced/aot-notes.md#两条-tpc-供给路线)

## `--selftest` 卡住不退出

模态对话框在无头环境永久阻塞。确认应用在自检前调了 `headless(true)`：

```php
$app->headless(true);   // message() 返回 default，文件对话框返回空
```

也检查有没有漏掉守卫的间接路径 —— 一个真实案例：无托盘时 Qt 把 `notify()` 回退成**模态**消息框。

## 界面不更新，或者 `use (&$state)` 忘了加 `&`

数组状态必须用引用捕获：

```php
$app->view(function () use ($state) { ... });    // ✗ 改的是副本
$app->view(function () use (&$state) { ... });   // ✓
```

对象状态是引用传递，不用 `&`。

## 表格插入一行后选中跳到了别的行

没给 `row_ids`。不给时行 id 退化成索引（`'0'`、`'1'`…），插一行就会错位：

```php
WidgetTree::table(['名称'], $rows, ['id' => 'tbl', 'row_ids' => ['r1', 'r2']]);   // ✓
```

见[为什么一定要给 row_ids](/zh/widgets/data.md#为什么一定要给-row-ids)。

## 第二次渲染崩溃（`0xC0000005`）

早期版本的自动 id 用自增计数器生成 —— 每帧 id 都变，diff 认为整棵树都是新的，于是每帧重建。重建时旧控件被删但布局里留着悬垂指针。

现在自动 id 从**结构路径**推导（`_p0.1.2`），稳定。如果你在扩展桥接时改 id 生成逻辑，务必保持"从结构推导"这个性质。

## 中文显示成乱码

桥接边界必须走 UTF-8。`QString::fromUtf8` / `toUtf8`。检查你的 `project.yml` 有没有 `/utf-8`（MSVC）或等价开关。

## 运行时报 `could not find or load the Qt platform plugin windows`

没跑 `windeployqt`，或者打包时漏了插件目录。

```bash
qtphp package .   # 会做完整自检
```

## 打包产物在别的机器上跑不起来

用最小环境手工验证 —— 这是唯一能抓到缺失依赖的检查：

```bash
# Windows
set "PATH=C:\Windows\System32;C:\Windows"
dist\<app>.exe

# macOS / Linux
env -i QT_QPA_PLATFORM=offscreen ./myapp --selftest
```

注意自检还要**扫 stdout**：PHP 把缺扩展报成 warning 写进 stdout，干净退出码说明不了问题。

## `qtphp test` 全绿，但编译产物崩

这是**预期行为**，不是 bug。

`FakeBridge` 跑在宽容的 PHP 解释器上，**结构上无法**复现 AOT 的严格性（闭包参数个数、`require`、`global $argv`…）。

**单元测试覆盖逻辑，`--selftest` 覆盖编译行为 —— 两个都要。**

## `qtphp lint` 报契约不一致

stub 声明的函数和 C++ 实现不匹配。要么补实现（`cpp-src/*.cc` 里加 `php_<name>`），要么删声明。

注意：stub 里声明的每个函数都**必须被调用**，否则 `qtphp build` 会中止。

## 想用包没有的 Qt 控件

扩展桥接。完整流程见[手写桥接](/zh/advanced/bridge.md#加一个新控件的完整流程) —— 大致是：

1. `cpp-src/qt_widgets.cc` 加控件工厂分支
2. `src/WidgetTree.php` 加工厂方法
3. **`src/FakeBridge.php` 也登记**（否则单测跑不了）
4. `qtphp lint` + `qtphp build` 验证
5. 示例的 `--selftest` 里加一条覆盖它

## 首次构建特别慢 / 离线构建失败

macOS/Linux 上第一次构建要让 tpc 从 php-src 现编私有 embed 运行时（缓存到 `~/.typephp`，之后复用）。

而且 **tpc 每次构建都要访问 php.net 核对源码 SHA-256** —— 纯离线机器第一次会失败。

## 多个窗口只有一个响应

`run()` 只泵**它自己**那个窗口。多窗口要自己按帧轮流泵：

```php
while ($app->isOpen()) {
    $app->runFrames(1);
    if ($state['log'] instanceof QtApp && $state['log']->isOpen()) {
        $state['log']->runFrames(1);
    }
}
```

见[多窗口](/zh/guide/menus-tray-timers.md)。

## 怎么让应用常驻托盘、关窗口不退出

包当前把托盘定位为**窗口应用的附加能力**。纯托盘应用（无主窗口、常驻后台）需要手写桥接并改两处：

- `QApplication::setQuitOnLastWindowClosed(false)`
- 主循环条件从 `isOpen()` 改成存活标志

见[多窗口/托盘/定时器](/zh/guide/menus-tray-timers.md#托盘应用两个假设要反过来)。
