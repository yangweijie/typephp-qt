# 深入

这一部分讲框架内部怎么工作，以及 AOT 编译特有的约束。**如果你只是用包写应用，可以先跳过** —— 遇到构建问题或需要扩展桥接时再回来。

| 页面 | 什么时候读 |
|---|---|
| [AOT 注意事项](/zh/advanced/aot-notes.md) | **第一次编译运行前就该读。** 这些坑只在编译产物里出现 |
| [手写桥接](/zh/advanced/bridge.md) | 要用包未暴露的 Qt 控件，或想理解桥内部 |
| [diff 引擎](/zh/advanced/diff-engine.md) | 想理解"控件状态为什么能保留" |
| [无头验收](/zh/advanced/headless.md) | 要接 CI，或在无显示器环境验证 |

## 一句话概括

```
PHP 描述界面 ──► C++ 按 id diff ──► Qt 显示
   状态驱动         稳定 id           只显示
```

三层分工的完整说明见[架构原理](/zh/guide/architecture.md)。
