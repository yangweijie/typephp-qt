<?php

declare(strict_types=1);

namespace TypePHP\Qt\A2UI;

/**
 * DataModel — A2UI surface 数据模型：JSON Pointer（RFC 6901）寻址的可变树。
 *
 * （性能注记）decodePointer 按指针串缓存（纯函数 ⇒ 进程级缓存安全，长度以应用
 * 实际出现的指针串数为上界）；formatString 同策略在 Evaluator（R3）。
 *
 * updateDataModel 三语义的唯一实现点（v1.0 spec「Agent to renderer updates」原文）：
 *   * 路径存在 → 更新；路径不存在 → 创建（upsert —— agent 从未初始化的路径也可写，
 *     双向绑定的写入契约依赖这一点）；value = null → 删除该键（spec 明说 null 是
 *     「删除」不是「写入 null」——纯 JSON 想存 null 只能整段替换，这是协议的有意取舍）；
 *   * path 缺省 / "" / "/" → 整模型替换。注意 "/" 在 RFC 6901 里指向空名键，
 *     A2UI 在此**有意偏离**（spec「updateDataModel」原文 "(or is /)"），本类以协议为准。
 *
 * JSON Pointer 转义：token 内 ~1 → "/"、~0 → "~"（解码在 decodePointer，顺序不能反：
 * 先 ~1 后 ~0 会把字面 "~1" 误解码，RFC 6901 §4 原文钉死的顺序）。
 *
 * 宽容语义（仓库兼容默认）：get 未命中路径返回 null（不区分「键存 null」与「不存在」，
 * 上层 Evaluator 的 DynamicBoolean/类型转换表对两者行为一致）；remove 未命中 = 无操作。
 */
final class DataModel
{
    /** @var array<string, mixed> 根对象（updateDataModel 的 dataModel 恒为 JSON object） */
    private array $root = [];

    /** @var array<string, array<int, string>> 指针 token 缓存（键 = 原指针串） */
    private static array $pointerCache = [];

    /** 整模型替换（createSurface.dataModel / 根级 updateDataModel）。 */
    public function replaceModel(array $model): void
    {
        $this->root = $model;
    }

    /** 当前模型（只读快照；sendDataModel 回传 / 渲染读数用）。 */
    public function all(): array
    {
        return $this->root;
    }

    /** JSON Pointer 取值；未命中返回 null（宽容语义见类注释）。 */
    public function get(string $pointer): mixed
    {
        // AOT 陷阱（AGENTS.md「一个局部一种类型」的运行期变体）：$node 若先绑
        // $this->root（array）再被 $node[$token] 赋成标量，tpc 会把该变量推断成
        // array 并在回填处插隐式 Variant→Array 转换 —— 纯 PHP 单测全绿、AOT 产物
        // 运行到标量节点即 fatal（parameter 1 must be `array`, got `string`，
        // lldb 实测栈：checkArray ← datamodel__get ← evaluateExpression）。
        // std::any($x) 是 tpc 编译期降级（生成 php::Var，mixed 下钻合法）；
        // 纯 PHP 环境没有 std 类，tests/bootstrap.php 提供运行期 polyfill。
        $node = \std::any($this->root);
        foreach (self::decodePointer($pointer) as $token) {
            if (is_array($node) && array_key_exists($token, $node)) {
                $node = $node[$token];
                continue;
            }
            return null;
        }
        return $node;
    }

    /**
     * updateDataModel 语义入口：$path 为 null/""/"/" = 整模型替换；
     * value = null = 删键；否则 upsert。
     */
    public function applyUpdate(?string $path, mixed $value): void
    {
        if ($path === null || $path === '' || $path === '/') {
            // 根级 null 也按「删键」字面语义 = 清空模型（spec：value null → 删除该键，
            // 根指针的「该键」就是整个模型）
            $this->root = is_array($value) ? $value : [];
            return;
        }
        $tokens = self::decodePointer($path);
        if ($value === null) {
            $this->removeTokens($tokens);
            return;
        }
        $this->setTokens($tokens, $value);
    }

    /** 相对路径 → 绝对指针（模板 Collection Scope 的解析规则；$base = "" 表示根）。 */
    public static function resolveRelative(string $base, string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/')) {
            return $relative === '' ? '/' : $relative;
        }
        return rtrim($base, '/') . '/' . $relative;
    }

    /** RFC 6901 解码："/a~1b~0c/0" → ["a/b~c", "0"]；"" 与 "/" 都按根（A2UI 口径）。 */
    public static function decodePointer(string $pointer): array
    {
        if ($pointer === '' || $pointer === '/') {
            return [];
        }
        // 解析是纯函数（指针串 → token 列表，与模型状态无关）⇒ 按指针串缓存：
        // 回放里同一批 @path 每条消息/每帧重复解析，explode+str_replace 是纯浪费
        if (isset(self::$pointerCache[$pointer])) {
            return self::$pointerCache[$pointer];
        }
        $raw = ltrim($pointer, '/');
        $tokens = [];
        foreach (explode('/', $raw) as $token) {
            $tokens[] = str_replace(['~1', '~0'], ['/', '~'], $token);
        }
        self::$pointerCache[$pointer] = $tokens;
        return $tokens;
    }

    /**
     * upsert：中间节点不存在则建（对象）；命中标量则整段替换成容器（宽容——
     * spec 只定义「不存在→创建」，存在但类型不符是 agent 侧 bug，重建比报错更利于
     * progressive rendering）。列表 token（纯数字 + 父是列表）按下标；PHP 关联数组
     * 与 JSON object 的边界用 array_is_list 判（json_decode assoc 的数字键对象不是 list）。
     */
    private function setTokens(array $tokens, mixed $value): void
    {
        $node = &$this->root;
        $last = count($tokens) - 1;
        foreach ($tokens as $i => $token) {
            $isListIndex = is_array($node) && array_is_list($node) && preg_match('/^(0|[1-9][0-9]*)$/', $token) === 1;
            if ($i === $last) {
                if ($isListIndex) {
                    $index = (int) $token;
                    // PHP 下标赋值会留稀疏洞（json_encode 会把整个列表退化成对象）；
                    // JSON 语义没有洞 —— 越界下标用 null 补齐再写
                    if ($index > count($node)) {
                        for ($pad = count($node); $pad < $index; $pad++) {
                            $node[$pad] = null;
                        }
                    }
                    $node[$index] = $value;
                } else {
                    if (!is_array($node)) {
                        $node = [];  // 标量中间层被路径写入穿透：整段替换成容器
                    }
                    $node[$token] = $value;
                }
                return;
            }
            if ($isListIndex) {
                $index = (int) $token;
                if (!array_key_exists($index, $node) || !is_array($node[$index])) {
                    $node[$index] = [];
                }
                $node = &$node[$index];
                continue;
            }
            if (!is_array($node)) {
                $node = [];
            }
            if (!isset($node[$token]) || !is_array($node[$token])) {
                $node[$token] = [];
            }
            $node = &$node[$token];
        }
    }

    /** 删键：命中即删（列表按下标 splice 保持连续），未命中无操作。 */
    private function removeTokens(array $tokens): void
    {
        if ($tokens === []) {
            $this->root = [];
            return;
        }
        $node = &$this->root;
        $last = count($tokens) - 1;
        foreach ($tokens as $i => $token) {
            if (!is_array($node) || !array_key_exists($token, $node)) {
                return;  // 未命中：宽容无操作
            }
            if ($i === $last) {
                if (array_is_list($node) && preg_match('/^(0|[1-9][0-9]*)$/', $token) === 1) {
                    array_splice($node, (int) $token, 1);
                } else {
                    unset($node[$token]);
                }
                return;
            }
            $node = &$node[$token];
        }
    }
}
