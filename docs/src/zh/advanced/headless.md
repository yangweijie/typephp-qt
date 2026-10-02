# 无头验收

GUI 应用需要在没有显示器、没有人的机器上证明它还能工作。**要建两个开关，不是一个。**

## `--shot <path>` —— 视觉验收

渲染几帧、存 PNG、退出：

```php
$shot = shot_path($argv);          // 从 argv 读 `--shot <path>`
if ($shot !== '') {
    $app->runFrames(3);
    $app->snapshot($shot);
    $app->destroy();
    return;
}
```

```bash
qtphp run . --shot out.png
```

把 PNG 读回来看一眼 —— 这是确认布局改动最省事的办法。

::: tip 用命令行参数，不要用环境变量
往 `.bat` 里从 shell 传环境变量要穿过好几层引号，很脆。参数是可靠的。示例的 `run.bat shot.png` 就是这么做的。
:::

## `--selftest` —— 行为验收

逐个触发所有已注册事件，每个用例报 `ok` / `FAIL`：

```php
$app->headless(true);      // 模态对话框不能阻塞 —— 见 AOT 注意事项
$app->run(1);

$app->dispatch(['type' => 'click', 'id' => 'greet_btn']);
$app->dispatch(['type' => 'submit', 'id' => 'name_input', 'value' => 'Ada']);
// … 每个控件一条 …

echo $app->lastError() === '' ? "selftest passed\n" : "selftest failed\n";
```

```bash
qtphp run . --selftest
```

```
ok   click greet_btn
ok   submit name_input
ok   toggle dark_toggle
ok   change theme_combo
…
selftest passed
```

::: warning 这个开关才是真正有价值的那个
[闭包参数个数陷阱](/zh/advanced/aot-notes.md#闭包实参个数必须精确匹配)对单元测试和"不点任何东西"的冒烟测试**都是隐形的** —— 它只在你真的触发那个控件时才炸。`--selftest` 无头地把它们全触发一遍，几秒钟跑完。
:::

让应用能程序化地报出**最后一次错误**（`lastError()`），这样检查能打印**为什么**失败而不只是失败了。

## `--difftest` —— diff 边界

表格/树的差异更新边界（选中、行 id、列数变化、补丁）只能跑在真 Qt 上 —— diff 引擎和 `patch()` 的 `call` 都在 C++ 里，PHPUnit 摸不到。

```bash
qtphp run . --difftest
```

示例应用的 20 条断言就在 `examples/hello/src/main.php` 的 `diff_test()` 里，照着写自己应用的边界断言即可。

## 三个开关的分工

| 开关 | 验证什么 | 能跑在 |
|---|---|---|
| `--shot` | 布局、渲染、中文字体 | 真 Qt（可 offscreen） |
| `--selftest` | 每个处理器可调用、AOT 陷阱 | 真 Qt（可 offscreen） |
| `--difftest` | diff 引擎与 `patch()` 的 C++ 行为 | 真 Qt（可 offscreen） |

三者都**不挑平台**。要在无 GUI 会话（CI）里跑，设 `QT_QPA_PLATFORM=offscreen`。

## 无头模式做了什么

`headless(true)` 让所有阻塞调用立即返回，不碰 Qt 的模态 API：

| 调用 | 无头时 |
|---|---|
| `message()` / `alert()` / `error()` | 返回 `spec['default']`（或 `'ok'`） |
| `confirm()` | 返回 `false` |
| `openFile()` / `saveFile()` | 返回 `[]` |
| `pickDirectory()` | 返回 `''` |
| `notify()` | 直接返回（无托盘时它本来会回退成模态框） |

不设它的话，任何一个对话框都会让 CI 永久挂住。

## 在 CI 里跑

```bash
# 编译
qtphp build .

# 行为验收（无显示器）
QT_QPA_PLATFORM=offscreen qtphp run . --selftest

# 视觉验收（把 PNG 当构建产物存档，人工看或做像素比对）
QT_QPA_PLATFORM=offscreen qtphp run . --shot out.png

# 单元测试（不需要 Qt，也不需要编译器）
qtphp test

# 契约校验
qtphp lint
```

## 验收打包产物

打包产物也要能无头验收 —— 这才能证明"打包没漏东西"。

```bash
# macOS：bundle 必须自带 offscreen 插件
env -i QT_QPA_PLATFORM=offscreen PATH=/usr/bin:/bin HOME="$HOME" \
    dist/MyApp.app/Contents/MacOS/myapp --selftest

# Linux：包内已有整个 platforms/ 目录
cd dist/myapp && env -i QT_QPA_PLATFORM=offscreen ./myapp --selftest
```

`env -i` 清空环境，不让构建机的任何东西漏进来 —— 和 Windows 上把 `PATH` 缩到 `C:\Windows\System32` 是同一个思路。

::: warning macOS 的一个坑
`macdeployqt` 只按目标平台拷插件 —— 只带 `libqcocoa.dylib` 的 bundle 在设了 `QT_QPA_PLATFORM=offscreen` 时会被 Qt 直接 abort（rc=134）。

`qtphp package` 会自动把 `libqoffscreen.dylib` 补拷进 `Contents/PlugIns/platforms/` 并改写 Qt 引用（约 +156 KB）。见[打包](/zh/reference/packaging.md)。
:::

## 单元测试与无头验收的分工

| | `qtphp test`（PHPUnit + FakeBridge） | `--selftest`（真实 AOT 二进制） |
|---|---|---|
| 需要 Qt | ❌ | ✅ |
| 需要编译器 | ❌ | ✅（已编译） |
| 速度 | 毫秒 | 秒 |
| 能抓 AOT 陷阱 | ❌ **结构上不能** | ✅ |
| 能抓逻辑 bug | ✅ | 一般 |

**两个都要。** 前者快、能覆盖领域逻辑；后者是唯一能证明"编译产物真的能用"的检查。
