# 参考

| 页面 | 内容 |
|---|---|
| [CLI](/zh/reference/cli.md) | `qtphp` 七个子命令 |
| [API](/zh/reference/api.md) | `QtApp` / `WidgetTree` 全部方法、事件表、桥接契约 |
| [打包](/zh/reference/packaging.md) | 三平台产物结构、自检机制、运行时依赖 |
| [平台支持](/zh/reference/platforms.md) | 各平台能力矩阵与实测环境 |

## 快速索引

**最常用的 API**

```php
$app->create($options);                    // 初始化 QApplication
$app->createWindow($title, $options);      // 建窗口
$app->view($builder);                      // 注册视图构建函数
$app->on($id, $type, $handler);            // 注册事件处理器
$app->run();                               // 进主循环
```

**读控件值**

```php
$app->text($id);      // string
$app->value($id);     // mixed
$app->checked($id);   // bool
```

**命令式（不在控件树里）**

```php
$app->setTitle($t);  $app->setMenu($items);  $app->setStatus($segs);
$app->setTray($spec);  $app->setTimer($id, $ms);  $app->resize($w, $h);
```

**对话框**

```php
$app->alert($text);  $app->confirm($text);  $app->openFile();  $app->saveFile();
```

**验收**

```php
$app->headless(true);  $app->snapshot($path);  $app->lastError();
```
