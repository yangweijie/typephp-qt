# AOT 注意事项

TypePHP 把 PHP 提前编译成原生代码。**编译器比解释器严格** —— 通过 `php -l`、通过 PHPUnit、在解释器下完整跑通的代码，仍可能在编译产物里失败。

::: danger 不舒服的推论
**绿色的单元测试证明不了任何事。** 包自带的 `FakeBridge` 跑在宽容的解释器上，所以它**结构上无法**复现下面第 1 条。这一页每个坑都是跑真实 AOT 二进制才发现的。
:::

## 闭包实参个数必须精确匹配

**症状** —— 程序编译通过、启动正常、界面也对；点**某一个**按钮（或菜单项）时报：

```
stdClass::{closure}() expects exactly 0 arguments, 1 given
```

**成因** —— Zend 引擎对用户函数的**多余实参静默忽略**。AOT 编译后的闭包不会：它检查个数并抛 `ArgumentCountError`。所以一个把所有处理器都按 `$handler($event)` 调用的分发器，会让每个声明为 `function () {...}` 的处理器全部报错。

**为什么这是最阴险的一个** —— 它是**潜伏的**：在你点那个控件之前什么都不报。一个不点任何控件的冒烟测试会完全通过。

**修法** —— 按处理器**声明**的个数调用。在注册时探测一次：

```php
private function arityOf(callable $handler): int
{
    if (is_array($handler)) {
        $methodRef = new \ReflectionMethod($handler[0], $handler[1]);
        return $methodRef->getNumberOfRequiredParameters();
    }
    $functionRef = new \ReflectionFunction($handler);
    return $functionRef->getNumberOfRequiredParameters();
}
```

框架已经这么做了（`QtApp::on()` 存 `[handler, arity]`，分发时按实际个数调用），所以 `on()` / `onAny()` 注册的处理器两种写法都能用。**但你自己的分发逻辑要留意这一点。**

::: tip AOT 支持反射
`getNumberOfRequiredParameters()` 在 AOT 下对闭包、`[对象, '方法']` 数组、函数名字符串**都返回正确值**。不用绕开反射。
:::

**怎么测** —— 一个逐个触发所有已注册事件的 `--selftest` 开关是唯一能抓到它的检查。见[无头验收](/zh/advanced/headless.md)。

## `require` 不可用（`require_once` 也不行）

**症状**

```
All execution code must be within a function, found stray code
```

**成因** —— 编译器要求全局作用域**只放声明**。`require_once` 是**语句**，所以算 stray code —— 在全局作用域**和**函数体内都是。

**修法** —— 跨文件可见性靠 `project.yml` 的 `sources:` 列表建立，不靠 `require`：

```yaml
sources:
  - src/main.php
  - ../../src/QtApp.php
  - ../../src/WidgetTree.php
  - ../../php-src/qt.stub.php
  - ../../cpp-src/qt_bridge.cc
```

这对从 Composer 自动加载过来的人很意外。`qtphp new` 生成的 `sources:` 是正确的。

## `main()` 是全局函数，`global $argv` 会崩

**症状** —— 要么找不到入口，要么直接硬崩（`0xC0000409`，栈缓冲区溢出），没有 PHP 层面的报错。

**修法** —— 在全局作用域声明，参数签名精确：

```php
function main(int $argc, array $argv): void
{
    // 用 $argv，永远不要 `global $argv`
}
```

`global $argv` 就是崩的原因。要查开关就遍历 `$argv`（从索引 1 开始）：

```php
function has_flag(array $argv, string $flag): bool
{
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === $flag) {
            return true;
        }
    }
    return false;
}
```

## 闭包参数需要显式类型

**症状** —— 运行时 `The variable $event is undefined`，出现在一个明显带该参数的闭包里。

**修法** —— 标注类型：

```php
$app->on('btn', 'click', function (array $event) { /* … */ });   // 不是 function ($event)
```

编译器靠参数类型推断；没标注的参数不会被绑定。

## 模态对话框在无头环境永久阻塞

**症状** —— 无头运行（`--selftest`、CI、构建服务器）挂住。进程不退出，在对话框那一点之后不再有任何输出。栈采样会看到 `QDialog::exec` 等在嵌套事件循环里。

**成因** —— `QMessageBox::exec()`、`QMessageBox::question()`、`QFileDialog::getOpenFileName()` 都会自旋自己的循环直到有人操作。没有人在。

**修法** —— 一个无头标志，让所有阻塞对话框返回默认值而不碰 Qt 的模态 API：

```php
$app->headless(true);   // message() 返回 default；文件对话框返回空
```

**凡是能到达对话框的路径都要守卫**，包括间接的。一个真实案例：没有系统托盘时，Qt 会把 `QSystemTrayIcon::showMessage` 回退成**模态**消息框 —— 于是 `notify()` 成了唯一漏掉守卫的阻塞调用。

## 反射：一个变量只能有一种类型

**症状**

```
Cannot re-assign typed object $ref from ReflectionMethod to ReflectionFunction
```

**修法** —— 编译器在首次赋值时固定变量的推断类型。用两个变量：

```php
if (is_array($handler)) {
    $methodRef = new \ReflectionMethod($handler[0], $handler[1]);
    return $methodRef->getNumberOfRequiredParameters();
}
$functionRef = new \ReflectionFunction($handler);
return $functionRef->getNumberOfRequiredParameters();
```

反射本身在 AOT 下工作正常 —— 这纯粹是变量类型推断的限制，不是功能缺失。

## `Array*` 会隐式转成 `bool`

**症状** —— 运行时 `parameter 1 must be of type array, bool given`，出现在一个你明明传了数组的桥接函数上。

**成因** —— `php::Array` 有到 `bool` 的隐式转换。把 `&$spec` 传给指针类型的参数会得到 `Array(true)` —— 一个"值是 bool 的数组"，而不是引用。

**修法** —— 传 `array` 类型的值时去掉 `&`。

## 命名空间文件里调用桥接函数要加 `\`

**症状** —— 编译报找不到标识符，或生成的 `php_qt_foo(...)` 调用没有全局限定。

**修法** —— 桥接函数是全局的。在命名空间文件里写 `\qt_foo(...)`。

## 两条 tpc 供给路线

一台机器上可能有两个 `tpc`，它们的运行时来源完全不同。混用会在配置阶段就失败。

| | 原生发行包 | composer 驱动 |
|---|---|---|
| 位置 | 解压目录，如 `tpc_v0.9.4_windows_x64/tpc.exe` | `vendor/bin/tpc.php` → `vendor/swoole/typephp/bin/tpc.php` |
| 运行时 | **自包含** —— `phpx.dll` / `SDK/` 就在可执行文件旁边 | `vendor/swoole/phpx` **源码树** |
| 能否直接用 | 能，解压即用 | 不能 —— 那个源码树不含 `build/phpx.dll`、`lib/phpx.lib`，要先用 phpx 工具链自建 |

**选错时的症状**

```
Fatal error: The PHPX runtime library was not found at:
  ...\phpx\build\phpx.dll
Build PHPX first (for example, run `nmake phpx` in ...\phpx\build)
```

**原因** —— `Windows.php` 的 `getBuildLibraryWarnings()` 检查 `<phpxDir>\build\phpx.dll` 和 `<phpxDir>\lib\phpx.lib`，而 `<phpxDir>` 来自 `PhpxLocator::resolve()` —— 它找的是 **`vendor/swoole/phpx`**（可用 `PHPX_HOME` 覆盖）。

**`qtphp` 怎么处理** —— 不硬性排序（两条路线在各自平台上都合法：macOS/Linux 的完整链路本就建立在 composer 驱动 + 自建私有运行时之上），而是**探测运行时**：优先选带运行时的候选，都没有才退回。

```bash
qtphp doctor   # 打印实际选中的 tpc 与运行时库目录 —— 构建失败时先看这两行
```

要强制指定：

```bash
TPC=/path/to/tpc.exe   qtphp build .   # 跳过探测
TPC_DIR=/path/to/dir   qtphp build .
```

## 调试纪律

- **别信单元测试能覆盖这些坑。** 用真实二进制复现。
- **注入诊断代码可能制造出你正在找的 bug。** 注入的标记本身就是代码改动；如果它破坏了字符串字面量或结构，你会花好几轮追一个刚被自己引入的故障。注入后先跑一次确认标记出现**再**下结论 —— 而且优先用真正的编辑器改动，不要用脚本做文本替换。（`php -l` 通过不代表语义没坏。）
- **"上次还好好的"不等于代码回归。** 也可能是环境解析选到了另一套工具链 —— 上面的两条 tpc 路线就是这种情况。
- **完全没有输出的崩溃**通常意味着它在 PHP 刷出任何东西之前就死了，或者根本没到你以为的那个分支。把标记用 `FILE_APPEND` 写进文件，不要依赖有缓冲的 stdout。
