---
home: true
heroImage: null
heroText: TypePHP\Qt
tagline: 用 TypePHP (AOT) + Qt 6 开发原生桌面应用 —— 界面用 PHP 数组描述，逻辑留在 PHP 里
actions:
  - text: 快速上手
    link: /zh/guide/quickstart.html
    type: primary
  - text: 控件目录
    link: /zh/widgets/
    type: secondary
features:
  - title: 声明式 UI
    details: 用 PHP 数组描述控件树，C++ 侧按 id 做差异更新。输入光标、表格选中、滚动位置在重渲染后原样保留。
  - title: 状态驱动
    details: 事件处理器只改状态，view() 注册的构建函数每帧按新状态重新描述界面。控件层是状态的纯函数。
  - title: PHP 主循环
    details: Qt 事件泵由 PHP 驱动，业务逻辑全部留在 PHP 里，可以用普通 PHP 解释器单测，不需要 Qt 或编译器。
  - title: 源码内联
    details: 桥接 C++ 直接参与应用编译，不依赖预编译二进制，永远不会和 Qt/PHPX 版本脱节。
  - title: 一键打包
    details: qtphp package 组装自包含产物并自检 —— Windows 出 dist/，macOS 出 .app，Linux 出带 ldd 闭包的目录。
  - title: 无头验收
    details: --shot 出 PNG 做视觉验收，--selftest 逐个触发事件，--difftest 验表格/树的 diff 边界。CI 里不需要显示器。
footer: MIT Licensed | Copyright © yangweijie
---
