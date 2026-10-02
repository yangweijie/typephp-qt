# 增量补丁

`patch()` 是声明式视图的**命令式旁路**，用于日志流、进度刷新这类每秒改很多次的热路径 —— 整棵树每帧重建太浪费。

```php
$app->patch([
    ['op' => 'set',  'id' => 'status', 'props' => ['text' => '已完成']],
    ['op' => 'call', 'id' => 'log', 'method' => 'appendRows',
     'args' => [[['10:32', '启动'], ['10:33', '就绪']], ['l1', 'l2']]],
]);
```

每条操作是两种形态之一：`set` 改属性，`call` 调方法。

## `set` —— 改属性

`props` 与[声明式属性](/zh/guide/properties.md)**同一套语义**：

```php
$app->patch([
    ['op' => 'set', 'id' => 'progress_bar', 'props' => ['value' => 65]],
    ['op' => 'set', 'id' => 'hint', 'props' => ['text' => '处理中…', 'visible' => true]],
]);
```

结构字段（`rows` / `columns` / `row_ids` / `nodes` / `headers`）会触发整表/整树重建，并按行 id 保留选中：

```php
$app->patch([
    ['op' => 'set', 'id' => 'tbl', 'props' => [
        'rows'    => [['乙', '2'], ['甲', '1']],
        'row_ids' => ['r2', 'r1'],
    ]],
]);
```

## `call` —— 调方法

`args` 是**位置参数**。已实现六个方法：

| method | args | 作用域 |
|---|---|---|
| `appendRows` | `args[0]` = 行列表（每行是单元格列表），`args[1]` = 可选行 id 列表 | 仅 `table` |
| `clear` | 无 | 表格去行、树/列表/下拉去条目、文本类置空 |
| `setText` | `args[0]` = 文本 | `label` `button` `lineedit` `textedit` `checkbox` `radio` |
| `setValue` | `args[0]` = 值 | 进度条/滑块/数字框按数值，输入类按文本 |
| `select` | `args[0]` = id 或索引 | 与该控件的 `current` 属性**完全同一套语义** |
| `focus` | 无 | 把键盘焦点交给该控件 |

```php
$app->patch([
    ['op' => 'call', 'id' => 'log',  'method' => 'appendRows', 'args' => [[['10:32', '启动']]]],
    ['op' => 'call', 'id' => 'body', 'method' => 'setText',    'args' => ['新内容']],
    ['op' => 'call', 'id' => 'bar',  'method' => 'setValue',   'args' => [80]],
    ['op' => 'call', 'id' => 'name', 'method' => 'focus'],
]);
```

## 核心约束：它是旁路，不是新状态

::: danger `call` 改的东西只在下次渲染前有效
执行后，被改属性的 diff 签名会**作废**，下一次 `render()` 一律**以树为准**重新同步。

所以：追加的行、清空的内容都只在这次渲染之前有效。**要长期存在就得写回状态。**
:::

这带来一个选择：

### 数据在状态里（推荐）

```php
// 追加时同时写状态
$state['logLines'][] = [date('H:i:s'), $line];

// view() 里生成完整表格 —— 重渲染不丢
$app->view(function () use (&$state): array {
    return WidgetTree::table(['时间', '事件'], $state['logLines'], ['id' => 'log_tbl']);
});
```

`patch` 只用于**立刻见效**（不用等下一帧），状态才是真相。

### 纯 `patch`（只适合短命内容）

```php
$app->patch([['op' => 'call', 'id' => 'log', 'method' => 'appendRows', 'args' => [[$row]]]]);
```

下次任何原因导致重渲染（比如用户点了别的按钮），这些行就没了。

## 什么时候用 `patch`

| 场景 | 用 `patch`？ |
|---|---|
| 日志流、进度条、实时数值 | ✅ 每秒几十次，整树重建浪费 |
| 用户操作引起的界面变化 | ❌ 改状态，让 `view()` 反映 |
| 需要长期存在的数据 | ❌ 写状态 |
| 大表格追加一行 | ✅ 但要同时写回状态 |

**默认用状态。** 只有当某处的渲染开销实测成问题时，才为它引入 `patch`。

## 未知内容静默忽略

未知 `method`、未知 `id` 都静默忽略 —— 与未知属性一致。好处是降级和容错容易；代价是**打错字不会告诉你**。

## 完整例子：进度条 + 日志

```php
$app->on('start_btn', 'click', function () use ($app, &$state) {
    for ($i = 1; $i <= 100; $i++) {
        // 进度：热路径，走 patch
        $app->patch([['op' => 'set', 'id' => 'progress_bar', 'props' => ['value' => $i]]]);

        // 日志：写回状态（要长期存在）
        if ($i % 10 === 0) {
            $state['logLines'][] = [(string) $i . '%', '处理中'];
        }

        $app->runFrames(1);   // 让界面有机会刷新
    }

    $app->patch([['op' => 'set', 'id' => 'hint', 'props' => ['text' => '完成']]]);
});
```

::: tip 长循环里要主动泵帧
处理器执行期间界面不会自己刷新 —— 上面的循环里每轮 `runFrames(1)` 才让进度条动起来。不加的话界面会在循环结束后一次性跳到 100%。
:::
