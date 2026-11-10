# QML（内嵌组件）

`WidgetTree::qml()` 用 `QQuickWidget` 把 QML 组件嵌进你的窗口。和[网页视图](/zh/widgets/webview.md)
一样，这个能力是**编译期**开关 —— PHP 代码两边完全相同，开关没开时控件退化成带诊断文案的
占位标签，而不是白屏。

| | 定义了 `QT_QML` | 没定义 |
|---|---|---|
| 控件 | 加载 `.qml` 组件的 `QQuickWidget` | 一块占位标签，写清「QML 未启用 + 该控件 id + 怎么开」 |
| 属性 / 读回 | 正常工作 | 全部是空操作（`value()` 返回空数组） |
| 信号事件 | **同步**接进事件队列 —— 桥接把每个信号经一个真实 Qt 槽中转（隐形按钮的 `click()`，见[事件](#事件)），一次触发就是一个事件、无帧延迟；仅支持无参信号 | 全部是空操作 |
| `qmlAvailable()` | `true` | `false` |
| `qmlStatus('id')` | 组件真实状态 | `['status' => 'error', …]` 带一条诊断 |

## 控件

```php
WidgetTree::qml('assets/clock.qml', ['id' => 'clock']);
```

它是**叶子控件**：节点上的 `children` 被忽略 —— QML 内部结构由 `.qml` 文件自己组织，
框架不从 PHP 树里种 Qt Quick 节点。像所有会更新的控件一样，给它一个 `id`。

### 四个专用键

| 键 | 含义 |
|---|---|
| `source` | 要加载的 `.qml` 文件。不带 scheme 时相对**可执行文件所在目录**解析（与网页视图的 `url` 同一套契约）；带 scheme（`file://` 等）原样使用 |
| `signals` | 组件**根对象**暴露的信号名列表，逐个接进事件队列 —— 见[事件](#事件) |
| `props` | 写到根对象动态属性上的关联数组（如 `['timeText' => '12:00:00']`） |
| `read` | 要读回的属性名列表。事件 `payload` 与 `value('id')` 都是**恰好这几个属性**的快照 |

其余键走通用控件树语义（`id` `visible` `enabled` `style` `size` `grow` …）。

一个可用的形态：

```php
WidgetTree::qml('assets/clock.qml', [
    'id'      => 'clock',
    'signals' => ['tick'],
    'props'   => ['timeText' => $state['time']],
    'read'    => ['timeText'],
    'grow'    => 1,
]);
```

`source` 指向的组件是纯 QML：根对象暴露属性与信号，这就是它和 PHP 之间的全部契约。

```qml
Rectangle {
    id: root
    property string timeText: "00:00:00"
    signal tick

    Text { text: timeText; anchors.centerIn: parent }
    Timer { interval: 1000; repeat: true; running: true
            onTriggered: { root.timeText = new Date().toTimeString().slice(0, 8); root.tick(); } }
}
```

## 启用

必须**按应用显式开启**。框架默认是关的：`qt_qml.cc` 永远参与编译，但没开关时只构建
可诊断的占位实现。

开启要在常规 `Core` / `Gui` / `Widgets` / `Network` 之外引入三个 Qt 模块 —— `QtQml`、
`QtQuick`、`QtQuickWidgets`。

```yaml
# project.yml（Windows）：cxx-flags / link-paths / link-libs
cxx-flags:
  - /DQT_QML
  - /I"…/Qt/include/QtQml"
  - /I"…/Qt/include/QtQuick"
  - /I"…/Qt/include/QtQuickWidgets"
link-libs:
  - Qt6Qml.lib
  - Qt6Quick.lib
  - Qt6QuickWidgets.lib
```

```yaml
# project.macos.yml：cxx-flags / ld-flags
cxx-flags:
  - -DQT_QML
  - -I/opt/homebrew/opt/qtbase/lib/QtQml.framework/Headers
  - -I/opt/homebrew/opt/qtbase/lib/QtQuick.framework/Headers
  - -I/opt/homebrew/opt/qtbase/lib/QtQuickWidgets.framework/Headers
ld-flags:
  - -Wl,-framework,QtQml
  - -Wl,-framework,QtQuick
  - -Wl,-framework,QtQuickWidgets
```

```yaml
# project.linux.yml：cxx-flags / ld-flags
cxx-flags:
  - -DQT_QML
  - -I/usr/include/x86_64-linux-gnu/qt6/QtQml
  - -I/usr/include/x86_64-linux-gnu/qt6/QtQuick
  - -I/usr/include/x86_64-linux-gnu/qt6/QtQuickWidgets
ld-flags:
  - -lQt6Qml
  - -lQt6Quick
  - -lQt6QuickWidgets
```

::: warning QML 文件不会被替你部署
`source` 只是运行期路径，打包**不会**把你的 `.qml` 文件拷进产物。要打出可运行的包，
得像 assets 一样自己带上 —— 放进 `assets/`，`source` 写 `assets/your-component.qml`，
它会相对可执行文件所在目录解析。
:::

**QML 模块本体**（QtQuick 引擎侧）在 mac 上打包无需额外动作：把开发机上所有 QML import
路径藏掉后 bundle 无头自检依然全过，`qtphp package` 产物自包含成立——QML 类型注册
内嵌在 QtQuick.framework 里，引擎的 `qmldir` 走内置资源（F55）。动态 `qml/` 插件目录
是 Windows 侧的打包问题，那边仍未验证。

## 事件

`signals` 里的每个名字都在组件根对象上按名查找；找到了之后，每发一次就进既有事件队列：

```php
['type' => 'qml', 'id' => 'clock', 'value' => 'tick', 'payload' => ['timeText' => '12:00:01']]
```

- `value` 是**信号名** —— 处理器按它分派。
- `payload` 是 `read` 属性在发出时刻的**快照**。信号**实参不回传**（v1 的设计决定），
  这就是 `read` 存在的原因。（桥接事件队列只承载字符串 value + 扁平 payload，
  结构化实参需要新的转换通道，不在当前范围。）

桥接没有 moc 工具链，没法按常规声明新槽，于是把每个声明的信号经一个**真实 moc 出的槽**
中转：`QObject::connect(root, signal, relay, click())` —— 接收方是挂在本控件下的隐形
`QPushButton`，`click()` 是它自己 metaobject 里的槽。调用它就**同步** emit `clicked()`，
再由 lambda 转进事件队列 —— 一次触发一个事件、零帧延迟。两个 Qt 行为被探针钉死过：
`connect` **没有**「QMetaMethod + functor」重载（接收方 metaobject 绕不开），且 Qt 对
重复连接**不去重**（桥接按组件维护已接线清单，同一信号拒绝重复连接）。失败仍然是响的：
root 上不存在的信号名、或带参信号（v1 只接无参信号），都记进 `qmlStatus()` 的 errors
列表，`QT_DEBUG_STRICT=1` 时还往 stderr 打警告。

```php
$app->on('clock', 'tick', function (array $event) use (&$state, $app) {
    $state['time'] = $event['payload']['timeText'];
});
```

信号名在根对象上找不到时**不静默**：记进组件错误列表（`qmlStatus()` 可读），
并且 `QT_DEBUG_STRICT=1` 时往 stderr 打警告。

## 读与写组件

| 调用 | 作用 |
|---|---|
| `$app->value('clock')` | `read` 属性的快照（没声明 `read` 时是空数组）。`props` 写入下一帧生效，所以这是无头环境验属性往返的手段 |
| `qmlReload('clock')` | 走 `patch` 的命令式动作：销毁根对象、重新加载 `source` |

写属性走声明式的 `props` 键；组件内部的动画/定时器会自己改属性，你随时用 `value()`
读当前状态。

## 能力查询与错误通道

```php
if ($app->qmlAvailable()) {
    // 全功能分支
} else {
    // 构建没定义 QT_QML：显示降级方案
}

$status = $app->qmlStatus('clock');
// ['status' => 'none'|'loading'|'ready'|'error',
//  'errors' => [['line' => int, 'message' => string], …]]
// 控件不存在或不是 qml 类型时是 []
```

::: warning 无头验收不要拿 `status === 'ready'` 当判据
offscreen 平台下组件状态可能**永远停在 loading** —— 这是平台行为，不是失败。
可靠的判据是**事件到达 + 属性读回**，不是状态字符串。（这不代表错误被吞：
QML 加载失败会显式上报 —— 白屏加零诊断是最难查的一类缺陷 —— 只是它不作为闸门。）
:::

`qmlStatus()` 对不存在的 id 或非 qml 类型返回 `[]`；构建时没开 `QT_QML` 时，C++ 侧报
`status => 'error'` 并附一条「构建时未定义」的诊断。

## 注意事项

::: warning `--shot` 截不到组件画面
`QQuickWidget` 画在自己的渲染器里、在 Qt 控件绘制体系之外，`QWidget::grab()`（`--shot` 用的
就是它）截不到它。验 QML 组件走**事件 + 读回属性**，不走像素。（`--selftest` 与 `--difftest`
只分发事件、读 `value()`，无头照常工作；`--shot` 不行。）
:::

::: tip 给它高度
`QQuickWidget` 对 Qt 的布局尺寸计算毫无贡献，容器伸不开时会被压成一条细缝。照网页视图的
同款建议：在能伸展的容器里给它 `'grow' => 1`。
:::

::: warning QML 加载失败会显式上报，不会静默
组件加载失败（文件不对、编译错误）时，逐行错误记进组件状态（`qmlStatus()` 可读）；
`QT_DEBUG_STRICT=1` 时还会往 stderr 打警告。整体没开 `QT_QML` 时，控件显示的是带诊断文案的
占位标签而不是白屏。用 `qmlStatus()` 读出来，别靠肉眼猜。
:::

## 相关

- [网页视图](/zh/widgets/webview.md) —— 姊妹的内嵌控件
- [控件目录](/zh/widgets/) —— 其他控件
- [属性](/zh/guide/properties.md) —— 通用属性（`grow`、`size` 等）
- [事件](/zh/guide/events.md) —— 事件模型总览
