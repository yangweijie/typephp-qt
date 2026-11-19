<?php

declare(strict_types=1);

namespace TypePHP\Qt\A2UI;

/**
 * Evaluator — A2UI Dynamic* 表达式求值（v1.0 spec「Data model representation」
 * 与「Basic Component Catalog」的函数子集）。
 *
 * 三种绑定形态：字面量透传 / {"@path": "<JSON Pointer>"} / {"@call": "<fn>", "args": {...}}。
 * 单-@ 前缀规则（spec「Single-@ Directive Rule」）：识别以外的 `@xxx` 键 = 协议错误
 * （保留给未来扩展），记 warning 并解析为 null；`@@path` 是字面 `@path` 的转义。
 *
 * DynamicBoolean 强制转换表（spec「DynamicBoolean and CheckRule Coercion」规则 3，
 * 逐行钉死）：ValidationResult → 取 valid；"true"/"false"（任意大小写）→ 布尔；
 * 其它字符串（含 ""）→ false；0 → false、其它数字 → true；null/缺席 → false；
 * 其它对象/数组 → false。
 *
 * formatString（spec「The formatString function」）：${...} 插值；绝对 ${/a/b} 与
 * 相对 ${name}（按 Scope）；函数调用带 ()、命名参数 key:value、嵌套 ${...}；
 * 字面 ${ 转义 \${。插值类型转换：null/undefined → ""、布尔 → "true"/"false"
 * （JSON 口径，不是 PHP 的 "1"/""——协议是 JS 语义）、数字 → 标准字符串、
 * 对象/数组 → JSON 字符串。
 *
 * 函数注册表（v1 子集）：required / email / regex / length / numeric / and / or / not
 * + 系统函数 @index。formatNumber/Currency/Date/pluralize/openUrl 未实现
 * （F59 §59.2 范围外）：未注册函数 = null + warning，不中断。
 *
 * regex 的 A2UI 方言：v1 落「禁用结构词法扫描 + 长度 256 上限 + PCRE(u) 执行」；
 * spec 的四条静态 ReDoS 拒绝规则未逐条实现（范围外）——PCRE 自带回溯上限兜底
 * （preg_match 失败 = 不匹配，非崩溃），如实记 F59。
 */
final class Evaluator
{
    /** @var array<int, string> 求值期间累积的告警（取走即清，Renderer 聚合） */
    private array $warnings = [];

    public function __construct(private readonly DataModel $model)
    {
    }

    /** @return array<int, string> 取走全部告警（幂等清空）。 */
    public function takeWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    /**
     * Dynamic* 单层求值入口：属性槽位上的一个值。
     * 字面量（标量）透传；@path/@call 求值；未知 @ 键 = null + warning；
     * 普通对象/数组原样返回（嵌套绑定用 resolveDeep）。
     */
    public function resolve(mixed $dynamic, Scope $scope): mixed
    {
        if (!is_array($dynamic)) {
            return $dynamic;
        }
        if (array_key_exists('@path', $dynamic) && is_string($dynamic['@path'])) {
            return $this->model->get($scope->resolve($dynamic['@path']));
        }
        if (array_key_exists('@call', $dynamic) && is_string($dynamic['@call'])) {
            $args = is_array($dynamic['args'] ?? null) ? $this->resolveDeep($dynamic['args'], $scope) : [];
            return $this->callFunction($dynamic['@call'], $args, $scope);
        }
        foreach (array_keys($dynamic) as $key) {
            if (is_string($key) && preg_match('/^@([^@]|$)/', $key) === 1) {
                $this->warnings[] = "未识别的保留前缀键 \"{$key}\"（单-@ 规则），解析为 null";
                return null;
            }
        }
        // @@ 前缀 = 字面 @ 键的转义（spec「Escaping via Prefix Doubling」）
        $needsUnescape = false;
        foreach (array_keys($dynamic) as $key) {
            if (is_string($key) && str_starts_with($key, '@@')) {
                $needsUnescape = true;
                break;
            }
        }
        if (!$needsUnescape) {
            return $dynamic;
        }
        $literal = [];
        foreach ($dynamic as $key => $value) {
            $literal[is_string($key) && str_starts_with($key, '@@') ? substr($key, 1) : $key] = $value;
        }
        return $literal;
    }

    /**
     * 深度求值：数组/对象逐值下钻，命中 @path/@call 对象就地解析。
     * action.context（spec：context 是「动态绑定解析后」的键值对）与函数 args 都走这里。
     */
    public function resolveDeep(mixed $value, Scope $scope): mixed
    {
        if (is_array($value)) {
            if (array_key_exists('@path', $value) || array_key_exists('@call', $value)) {
                return $this->resolve($value, $scope);
            }
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->resolveDeep($item, $scope);
            }
            return $out;
        }
        return $value;
    }

    /** DynamicBoolean：先求值再按七行强制转换表收敛到布尔。 */
    public function resolveBoolean(mixed $dynamic, Scope $scope): bool
    {
        return self::coerceBoolean($this->resolve($dynamic, $scope));
    }

    /** 转换表本体（纯函数，单测逐行钉）。 */
    public static function coerceBoolean(mixed $resolved): bool
    {
        if (is_bool($resolved)) {
            return $resolved;
        }
        if (is_array($resolved) && array_key_exists('valid', $resolved) && is_bool($resolved['valid'])) {
            return $resolved['valid'];
        }
        if (is_string($resolved)) {
            $lower = strtolower($resolved);
            if ($lower === 'true') {
                return true;
            }
            return false;  // "false" 与其它一切字符串（含 ""）→ false
        }
        if (is_int($resolved) || is_float($resolved)) {
            return $resolved !== 0 && $resolved !== 0.0;
        }
        return false;  // null / 其它对象 / 数组
    }

    /** 插值与 Text 类属性的类型转换（类注释的转换口径，纯函数）。 */
    public static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** formatString：${...} 插值主入口（语法细节见类注释）。 */
    public function formatString(string $template, Scope $scope): string
    {
        $out = '';
        $length = strlen($template);
        $i = 0;
        while ($i < $length) {
            if ($template[$i] === '\\' && substr($template, $i, 3) === '\\${') {
                $out .= '${';
                $i += 3;
                continue;
            }
            if ($template[$i] === '$' && ($i + 1) < $length && $template[$i + 1] === '{') {
                $end = self::findMatchingBrace($template, $i + 1);
                if ($end === null) {
                    // 未闭合的 ${：字面输出（宽容——agent 流式输出中途截断不该崩渲染）
                    $out .= substr($template, $i);
                    break;
                }
                $expression = substr($template, $i + 2, $end - $i - 2);
                $out .= self::stringify($this->evaluateExpression($expression, $scope));
                $i = $end + 1;
                continue;
            }
            $out .= $template[$i];
            $i++;
        }
        return $out;
    }

    /** 表达式求值：函数调用 name(key:value,...) 或裸路径绑定。 */
    private function evaluateExpression(string $expression, Scope $scope): mixed
    {
        $expression = trim($expression);
        if ($expression === '') {
            return null;
        }
        // @? 前缀：系统函数 @index 的调用形态（catalog 函数没有 @）；调度名要把
        // @ 带回去，否则 'index' 在注册表里查不到
        if (preg_match('/^(@?)([A-Za-z_][A-Za-z0-9_]*)\((.*)\)$/sD', $expression, $m) === 1) {
            $args = self::parseNamedArgs($m[3], $scope, $this);
            return $this->callFunction($m[1] . $m[2], $args, $scope);
        }
        // 裸路径：绝对或相对（解析失败 = null，符合渐进渲染的 undefined 宽容）
        return $this->model->get($scope->resolve($expression));
    }

    /** 命名参数表 "key:value,..."（引号/嵌套 ${} 内的逗号不切分）。 */
    private static function parseNamedArgs(string $raw, Scope $scope, Evaluator $self): array
    {
        $args = [];
        foreach (self::splitTopLevel($raw) as $part) {
            $colon = self::splitKeyValue($part);
            if ($colon === null) {
                continue;  // 无键名的散件：v1 忽略（spec 语法是纯命名参数）
            }
            [$key, $valueText] = $colon;
            $args[trim($key)] = $self->parseValueText(trim($valueText), $scope);
        }
        return $args;
    }

    /** 单个参数值：${...} 嵌套 / 引号字面量 / 数字 / 布尔 / null / 裸路径。 */
    private function parseValueText(string $text, Scope $scope): mixed
    {
        if ($text === '') {
            return null;
        }
        if (str_starts_with($text, '${') && str_ends_with($text, '}')) {
            return $this->evaluateExpression(substr($text, 2, -1), $scope);
        }
        $first = $text[0];
        if (($first === '\'' || $first === '"') && str_ends_with($text, $first) && strlen($text) >= 2) {
            $body = substr($text, 1, -1);
            return str_replace('\\' . $first, $first, $body);
        }
        if (is_numeric($text)) {
            return str_contains($text, '.') ? (float) $text : (int) $text;
        }
        $lower = strtolower($text);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        return $this->model->get($scope->resolve($text));
    }

    /**
     * 按深度 0 的逗号切分；跳过引号段与嵌套 ${...}/括号段。
     */
    private static function splitTopLevel(string $raw): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($raw);
        for ($i = 0; $i < $length; $i++) {
            $ch = $raw[$i];
            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && ($i + 1) < $length) {
                    $current .= $raw[++$i];
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $quote = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === '{' || $ch === '(') {
                $depth++;
            } elseif ($ch === '}' || $ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /** "key:value" 顶层切分（冒号可能出现在嵌套段里）。 */
    private static function splitKeyValue(string $part): ?array
    {
        $quote = null;
        $depth = 0;
        $length = strlen($part);
        for ($i = 0; $i < $length; $i++) {
            $ch = $part[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $quote = $ch;
                continue;
            }
            if ($ch === '{' || $ch === '(') {
                $depth++;
            } elseif ($ch === '}' || $ch === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($ch === ':' && $depth === 0) {
                return [substr($part, 0, $i), substr($part, $i + 1)];
            }
        }
        return null;
    }

    /** ${ 的配对扫描（跳过引号段；返回闭 } 下标，未闭合 null）。 */
    private static function findMatchingBrace(string $text, int $open): ?int
    {
        $depth = 1;
        $quote = null;
        $length = strlen($text);
        for ($i = $open + 1; $i < $length; $i++) {
            $ch = $text[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $quote = $ch;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /**
     * 函数分发表。返回 mixed：ValidationResult 数组 / 布尔 / 字符串 / null。
     * 未注册函数（formatNumber 等 v1 未实现）→ null + warning（F59 范围外清单）。
     */
    public function callFunction(string $name, array $args, Scope $scope): mixed
    {
        switch ($name) {
            case '@index':
                $offset = is_int($args['offset'] ?? null) ? $args['offset'] : 0;
                $index = $scope->templateIndex($offset);
                if ($index === null) {
                    $this->warnings[] = '@index 在 Root Scope 调用（spec：模板外非法），解析为 null';
                    return null;
                }
                return $index;

            case 'formatString':
                $template = $args['value'] ?? '';
                return $this->formatString(is_string($template) ? $template : self::stringify($template), $scope);

            case 'required':
                return self::fnRequired($args['value'] ?? null);

            case 'email':
                return self::fnEmail($args['value'] ?? null);

            case 'regex':
                return self::fnRegex($args['value'] ?? null, $args['pattern'] ?? '');

            case 'length':
                return self::fnLength($args['value'] ?? null, $args['min'] ?? null, $args['max'] ?? null);

            case 'numeric':
                return self::fnNumeric($args['value'] ?? null, $args['min'] ?? null, $args['max'] ?? null);

            case 'and':
                $values = is_array($args['values'] ?? null) ? $args['values'] : [];
                foreach ($values as $item) {
                    if (!self::coerceBoolean($item)) {
                        return false;
                    }
                }
                return true;

            case 'or':
                $values = is_array($args['values'] ?? null) ? $args['values'] : [];
                foreach ($values as $item) {
                    if (self::coerceBoolean($item)) {
                        return true;
                    }
                }
                return false;

            case 'not':
                return !self::coerceBoolean($args['value'] ?? null);

            default:
                $this->warnings[] = "未注册函数 \"{$name}\"（v1 范围外），解析为 null";
                return null;
        }
    }

    /** required：非 null、非 ""、非空容器即通过（0/false 是「有值」，JS 语义口径）。 */
    private static function fnRequired(mixed $value): array
    {
        $valid = !($value === null || $value === '' || (is_array($value) && $value === []));
        return ['valid' => $valid];
    }

    private static function fnEmail(mixed $value): array
    {
        $valid = is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        return ['valid' => $valid];
    }

    /**
     * regex：先词法拒绝 spec 明列的禁用结构（反向引用 / 命名组与后行断言（(?<
     * 一刀切，两类都以它开头）/ 内联 flag / \p 类 / \cX / 八进制逃逸），再长度
     * ≤256，最后 PCRE(u) search 语义。值 >4096 码点 = 不匹配（valid false，无错误）。
     */
    private static function fnRegex(mixed $value, mixed $pattern): array
    {
        if (!is_string($value) || !is_string($pattern) || $pattern === '') {
            return ['valid' => false];
        }
        if (mb_strlen($value) > 4096) {
            return ['valid' => false];
        }
        if (mb_strlen($pattern) > 256) {
            return ['valid' => false, 'code' => 'EXPRESSION_ERROR', 'severity' => 'error',
                'message' => 'pattern 超过 256 码点上限'];
        }
        if (preg_match('/\\\\([1-9]|c)|\(\?<|\\\\p\{|\\\\P\{|\(\?[a-z]/i', $pattern) === 1) {
            return ['valid' => false, 'code' => 'EXPRESSION_ERROR', 'severity' => 'error',
                'message' => 'pattern 使用了 A2UI 方言不允许的结构（反向引用/命名组/后行断言/内联 flag/\\p/\\c）'];
        }
        $matched = @preg_match('/' . str_replace('/', '\\/', $pattern) . '/u', $value);
        // preg_match false = 编译失败或回溯超限（方言外结构兜底），按不匹配计
        return ['valid' => $matched === 1];
    }

    private static function fnLength(mixed $value, mixed $min, mixed $max): array
    {
        if (!is_string($value)) {
            return ['valid' => false];
        }
        $length = mb_strlen($value);
        if (is_int($min) && $length < $min) {
            return ['valid' => false];
        }
        if (is_int($max) && $length > $max) {
            return ['valid' => false];
        }
        return ['valid' => true];
    }

    private static function fnNumeric(mixed $value, mixed $min, mixed $max): array
    {
        if (!is_int($value) && !is_float($value)) {
            return ['valid' => false];
        }
        if ((is_int($min) || is_float($min)) && $value < $min) {
            return ['valid' => false];
        }
        if ((is_int($max) || is_float($max)) && $value > $max) {
            return ['valid' => false];
        }
        return ['valid' => true];
    }
}
