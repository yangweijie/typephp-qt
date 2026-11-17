<?php

declare(strict_types=1);

namespace TypePHP\Qt\AGUI;

/**
 * JsonPatch — RFC 6902 子集（AG-UI STATE_DELTA 的形态），纯 PHP、不碰网络。
 *
 * 为什么宽容而不是抛错：AG-UI 规范对 STATE_DELTA 的定案是「格式合法但应用失败」
 * 记 warning 跳过、不中断流（漂移后由消费方请求新 snapshot 重新同步）。
 * 因此 apply() 永远返回 ['doc'=>…, 'warnings'=>[string]]，
 * 坏 patch（非数组 / 坏 JSON）同样降级为 warning + 原样返回 doc。
 *
 * 输入 doc 不可变：PHP 数组按值拷贝，操作的是独立副本；
 * 对象键统一走 JSON 语义（json 往返），保证与生产方的 JSON 状态同构。
 */
final class JsonPatch
{
    /** 六个 op 的合法名（spec 定稿，无更多）。 */
    public const OPS = ['add', 'remove', 'replace', 'move', 'copy', 'test'];

    /**
     * 把 $patch 应用到 $doc。
     *
     * @param mixed $doc   当前状态（JSON 值；AG-UI 里是关联数组）
     * @param mixed $patch op 列表，或编码它的 JSON 字符串
     *
     * @return array{doc: mixed, warnings: string[]}
     */
    public static function apply(mixed $doc, mixed $patch): array
    {
        if (is_string($patch)) {
            $decoded = json_decode($patch, true);
            if (!is_array($decoded)) {
                return ['doc' => $doc, 'warnings' => ['STATE_DELTA: patch 不是合法 JSON，整体跳过']];
            }
            $patch = $decoded;
        }
        if (!is_array($patch) || self::isAssoc($patch)) {
            // 空 patch（[]）= 零 op，合法且无事可做。
            return ['doc' => $doc, 'warnings' => ['STATE_DELTA: patch 不是 op 列表（数组），整体跳过']];
        }

        // 按值拷贝：PHP 数组本身是引用计数值语义，改 $working 不影响 $doc。
        $working = $doc;
        $warnings = [];
        foreach ($patch as $i => $op) {
            if (!is_array($op) || !isset($op['op']) || !is_string($op['op'])) {
                $warnings[] = "op#{$i}: 形态非法（缺 op 字段），跳过";
                continue;
            }
            if ($message = self::applyOp($working, $op, $i)) {
                $warnings[] = $message;  // 单 op 失败记 warning、继续后面的 op（STATE_DELTA 同语义）
            }
        }
        return ['doc' => $working, 'warnings' => $warnings];
    }

    /** 应用单个 op；成功返回 null，失败返回 warning 文本。 */
    private static function applyOp(mixed &$working, array $op, int $index): ?string
    {
        $name = $op['op'];
        if (!in_array($name, self::OPS, true)) {
            return "op#{$index}: 未知 op \"{$name}\"，跳过";
        }
        $path = isset($op['path']) && is_string($op['path']) ? $op['path'] : null;
        if ($path === null) {
            return "op#{$index} ({$name}): 缺 path，跳过";
        }
        $tokens = self::splitPointer($path);
        if ($tokens === null) {
            return "op#{$index} ({$name}): 路径 \"{$path}\" 非法，跳过";
        }

        switch ($name) {
            case 'add':
                if (!array_key_exists('value', $op)) {
                    return "op#{$index} (add): 缺 value，跳过";
                }
                return self::setByPath($working, $tokens, $op['value'], $index, 'add', true);
            case 'remove':
                return self::removeByPath($working, $tokens, $index);
            case 'replace':
                if (!array_key_exists('value', $op)) {
                    return "op#{$index} (replace): 缺 value，跳过";
                }
                return self::setByPath($working, $tokens, $op['value'], $index, 'replace', false);
            case 'move':
                $from = isset($op['from']) && is_string($op['from']) ? self::splitPointer($op['from']) : null;
                if ($from === null) {
                    return "op#{$index} (move): from 路径非法，跳过";
                }
                $found = self::getAt($working, $from);
                if (!$found[0]) {
                    return "op#{$index} (move): from \"{$op['from']}\" 不存在，跳过";
                }
                // 原子性：先验证 to 可写（在副本上试写），再摘 from —— 不留下摘一半的状态。
                // move/copy 的 to 目标按 **add 语义**求值（RFC 6902：目标处 = add 的规则，
                // 新键可建、列表可追加），不是 replace
                $probe = $working;
                $probeError = self::setByPath($probe, $tokens, $found[1], $index, 'move', true);
                if ($probeError !== null) {
                    return $probeError;
                }
                if ($removed = self::removeByPath($working, $from, $index)) {
                    return $removed;
                }
                return self::setByPath($working, $tokens, $found[1], $index, 'move', true);
            case 'copy':
                $from = isset($op['from']) && is_string($op['from']) ? self::splitPointer($op['from']) : null;
                if ($from === null) {
                    return "op#{$index} (copy): from 路径非法，跳过";
                }
                $found = self::getAt($working, $from);
                if (!$found[0]) {
                    return "op#{$index} (copy): from \"{$op['from']}\" 不存在，跳过";
                }
                return self::setByPath($working, $tokens, $found[1], $index, 'copy', true);
            case 'test':
                $found = self::getAt($working, $tokens);
                if (!$found[0]) {
                    return "op#{$index} (test): 路径 \"{$path}\" 不存在，跳过";
                }
                if (json_encode($found[1]) !== json_encode($op['value'] ?? null)) {
                    return "op#{$index} (test): \"{$path}\" 值不符（test 失败），跳过";
                }
                return null;
        }
        return null;
    }

    /**
     * 沿引用逐层下潜写值（比「取值副本再写回」少一类深层路径的丢失更新）。
     * $asAdd=true：列表的 "-" 追加、下标 == 长度追加、下标 < 长度插入（元素后移）；
     * false：目标必须已存在（replace 语义，move/copy 写 to 时用它保证不越权写）。
     */
    private static function setByPath(mixed &$node, array $tokens, mixed $value, int $index, string $what, bool $asAdd): ?string
    {
        if (count($tokens) === 0) {
            // add 与 replace 在此同语义：写值。RFC 6902 的 add 在目标处就是「设值」
            //（根目标 = 整体替换根），「根路径不可 add」的旧守卫会让 add 新键时
            // 在占位节点上误报，已删。
            $node = $value;
            return null;
        }
        if (!is_array($node)) {
            return "op#{$index} ({$what}): 中间节点不是对象/数组，无法设路径，跳过";
        }
        $head = (string) $tokens[0];
        $rest = array_slice($tokens, 1);

        if (self::isList($node)) {
            $count = count($node);
            $terminal = count($rest) === 0;
            if ($head === '-') {
                if (!$asAdd || !$terminal) {
                    return "op#{$index} ({$what}): \"-\"（列表尾部）只有末端 add 可用，跳过";
                }
                $node[$count] = null;
                return self::setByPath($node[$count], $rest, $value, $index, $what, $asAdd);
            }
            if (!preg_match('/^\d+$/', $head)) {
                return "op#{$index} ({$what}): 非数字键 \"{$head}\" 对列表无效，跳过";
            }
            $k = (int) $head;
            if ($terminal && $asAdd) {
                // 末端 add = 插入语义：== 长度追加，< 长度插入（元素后移），> 长度越界
                if ($k > $count) {
                    return "op#{$index} (add): 下标 {$head} 越界（列表长 {$count}），跳过";
                }
                if ($k === $count) {
                    $node[$k] = null;
                } else {
                    array_splice($node, $k, 0, [null]);
                }
            } else {
                // 中间段下潜 / replace / 末端 replace：必须已存在（不能为不存在的键造值）
                if (!array_key_exists($k, $node)) {
                    return "op#{$index} ({$what}): 下标 {$head} 不存在（列表长 {$count}），跳过";
                }
            }
            return self::setByPath($node[$k], $rest, $value, $index, $what, $asAdd);
        }

        // 对象键
        if (!array_key_exists($head, $node)) {
            if (!$asAdd) {
                return "op#{$index} ({$what}): 键 \"{$head}\" 不存在，跳过";
            }
            $node[$head] = null;
        }
        return self::setByPath($node[$head], $rest, $value, $index, $what, $asAdd);
    }

    /** 沿引用逐层删除（列表下标用 array_splice 保序，对象键 unset）。 */
    private static function removeByPath(mixed &$node, array $tokens, int $index): ?string
    {
        if (count($tokens) === 0) {
            return "op#{$index} (remove): 根路径不可 remove，跳过";
        }
        if (!is_array($node)) {
            return "op#{$index} (remove): 中间节点不是对象/数组，跳过";
        }
        $head = (string) $tokens[0];
        $rest = array_slice($tokens, 1);
        if (count($tokens) === 1) {
            if (self::isList($node)) {
                if ($head === '-' || !preg_match('/^\d+$/', $head)) {
                    return "op#{$index} (remove): 非数字键 \"{$head}\" 对列表无效，跳过";
                }
                $k = (int) $head;
                if (!array_key_exists($k, $node)) {
                    return "op#{$index} (remove): 下标 {$head} 不存在，跳过";
                }
                array_splice($node, $k, 1);
            } else {
                if (!array_key_exists($head, $node)) {
                    return "op#{$index} (remove): 键 \"{$head}\" 不存在，跳过";
                }
                unset($node[$head]);
            }
            return null;
        }
        if (self::isList($node)) {
            if (!preg_match('/^\d+$/', $head)) {
                return "op#{$index} (remove): 路径段 \"{$head}\" 对列表无效，跳过";
            }
            $k = (int) $head;
            if (!array_key_exists($k, $node)) {
                return "op#{$index} (remove): 路径段 \"{$head}\" 不存在，跳过";
            }
            return self::removeByPath($node[$k], $rest, $index);
        }
        if (!array_key_exists($head, $node)) {
            return "op#{$index} (remove): 路径段 \"{$head}\" 不存在，跳过";
        }
        return self::removeByPath($node[$head], $rest, $index);
    }

    /** 取路径处的值（只读）；返回 [found, value]。列表段用 array_splice 语义的整型下标。 */
    private static function getAt(mixed $doc, array $tokens): array
    {
        $current = $doc;
        foreach ($tokens as $token) {
            if (!is_array($current)) {
                return [false, null];
            }
            $key = self::listKey($current, (string) $token);
            $realKey = $key !== null ? $key : (string) $token;
            if (!array_key_exists($realKey, $current)) {
                return [false, null];
            }
            $current = $current[$realKey];
        }
        return [true, $current];
    }

    /** 列表下标解析：数字 token → int 下标；非数字（含 "-"）返回 null（走对象键）。 */
    private static function listKey(array $list, string $token): ?int
    {
        if ($token !== '-' && preg_match('/^\d+$/', $token)) {
            return (int) $token;
        }
        return null;
    }

    /** 列表判定：0..n-1 连续整型键（含空数组）= JSON 数组语义。 */
    private static function isList(array $node): bool
    {
        if ($node === []) {
            return true;
        }
        return array_keys($node) === range(0, count($node) - 1);
    }

    /** JSON Pointer（RFC 6901）："" = 根；其余必须 "/" 开头。非法返回 null。 */
    private static function splitPointer(string $pointer): ?array
    {
        if ($pointer === '') {
            return [];
        }
        if (!str_starts_with($pointer, '/')) {
            return null;
        }
        $parts = explode('/', substr($pointer, 1));
        $out = [];
        foreach ($parts as $part) {
            // RFC 6901 转义顺序：先 ~1 → /，再 ~0 → ~。
            $out[] = str_replace(['~1', '~0'], ['/', '~'], $part);
        }
        return $out;
    }

    /** 关联数组判定（JSON 对象语义 vs 列表语义）；空 patch 列表不算对象。 */
    private static function isAssoc(array $arr): bool
    {
        return !self::isList($arr) && $arr !== [];
    }
}
