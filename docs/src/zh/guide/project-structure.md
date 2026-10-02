# 项目结构

## 包自身的结构

```
typephp-qt/
├── bin/qtphp              # CLI（doctor/new/build/run/package/test/lint）
├── cpp-src/               # C++ 桥接（参与应用编译）
│   ├── qt_common.h        # 共享头：转换工具、Box 类、ChildSlot
│   ├── qt_bridge.cc       # 窗口 / 渲染 diff / 装饰 / 对话框 / 包装符号
│   └── qt_widgets.cc      # 控件工厂 / 属性应用 / 取值
├── php-src/qt.stub.php    # 桥接契约（PHP 签名，函数体必须为空）
├── src/                   # PHP 框架层
│   ├── QtApp.php          # 应用框架（事件循环 / 错误兜底）
│   ├── WidgetTree.php     # 声明式控件树构建器
│   └── FakeBridge.php     # 纯 PHP 桥接替身（测试用）
├── tests/                 # 单元测试
└── examples/hello/        # 示例应用
```

## 应用项目的结构

`qtphp new` 生成的是这个形状：

```
myapp/
├── src/main.php           # 入口。状态、视图、事件处理器都在这里
├── project.yml            # Windows 编译入口
├── project.macos.yml      # macOS 入口
├── project.linux.yml      # Linux 入口
├── Info.macos.plist       # 打包 .app 用
├── build.bat              # Windows 便捷脚本（等价于 qtphp build .）
├── run.bat                #   … qtphp run .
├── package.bat            #   … qtphp package .
└── assets/                # 运行时要读的文件
```

### 为什么有三个 `project.*.yml`

`qtphp build` 按 `PHP_OS_FAMILY` 挑入口：

| 平台 | 入口文件 |
|---|---|
| Windows | `project.yml` |
| macOS | `project.macos.yml` |
| Linux | `project.linux.yml` |

macOS / Linux 的入口用 `include: [project.yml]` 引入公共段，然后整体替换 Qt 相关字段（include-paths / link-libs / link-paths / cxx-flags / ld-flags）—— 因为 Qt 的位置和链接方式（framework vs `-lQt6Xxx`）是平台相关的。

Windows 直接用 `project.yml`，这样 `build.bat` 里的 `tpc.exe project.yml` 不会因为多平台支持而失效。

### `sources:` 是可见性的唯一来源

```yaml
sources:
  - src/main.php
  - ../../src/QtApp.php
  - ../../src/WidgetTree.php
  - ../../php-src/qt.stub.php
  - ../../cpp-src/qt_bridge.cc
  - ../../cpp-src/qt_widgets.cc
```

tpc 按 `project.yml` **所在目录**解析相对路径，所以同一份相对路径在三个平台都成立。

注意 `cpp-src/*.cc` 是**直接列进应用 `sources`** 的 —— 这是「源码内联」方案：桥接 C++ 参与应用编译，不依赖预编译库。原因见 [为什么是源码内联](/zh/advanced/bridge.md#为什么是源码内联)。

## 包如何被应用引用

应用不 `require` 包的任何文件 —— AOT 下 `require` 不可用。包的文件是通过 `project.yml` 的 `sources:` 列进去的（用相对路径指向 `vendor/yangweijie/typephp-qt/` 下的文件），由编译器统一装载。

这也意味着：**装包后如果移动了 `vendor/` 位置，`sources:` 里的相对路径要跟着改。**
