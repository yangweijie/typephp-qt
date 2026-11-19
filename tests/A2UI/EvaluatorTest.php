<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests\A2UI;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\A2UI\DataModel;
use TypePHP\Qt\A2UI\Evaluator;
use TypePHP\Qt\A2UI\Scope;

/**
 * Evaluator 单测 —— Dynamic* 求值 / 强制转换表 / formatString / 函数注册表的钉点。
 * 转换表逐行出处：spec「DynamicBoolean and CheckRule Coercion」规则 3 的表。
 */
final class EvaluatorTest extends TestCase
{
    private Evaluator $evaluator;

    protected function setUp(): void
    {
        $model = new DataModel();
        $model->replaceModel([
            'company' => 'Acme',
            'count' => 3,
            'flag' => true,
            'empty' => '',
            'employees' => [
                ['name' => 'Alice', 'role' => 'Engineer'],
                ['name' => 'Bob', 'role' => 'Designer'],
            ],
        ]);
        $this->evaluator = new Evaluator($model);
    }

    // ── resolve：三种绑定形态 ──────────────────────────────────────────

    public function testLiteralPassthrough(): void
    {
        $this->assertSame('plain', $this->evaluator->resolve('plain', Scope::root()));
        $this->assertSame(42, $this->evaluator->resolve(42, Scope::root()));
        $this->assertNull($this->evaluator->resolve(null, Scope::root()));
    }

    public function testAbsolutePathBinding(): void
    {
        $this->assertSame('Acme', $this->evaluator->resolve(['@path' => '/company'], Scope::root()));
    }

    public function testRelativeBindingInsideCollectionScope(): void
    {
        $scope = Scope::root()->child('/employees/1', 1);
        $this->assertSame('Bob', $this->evaluator->resolve(['@path' => 'name'], $scope));
        // 绝对路径不受作用域影响
        $this->assertSame('Acme', $this->evaluator->resolve(['@path' => '/company'], $scope));
    }

    public function testUnknownAtKeyRejected(): void
    {
        // 单-@ 规则：未识别的保留前缀键 = null + warning
        $value = $this->evaluator->resolve(['@future' => 1], Scope::root());
        $this->assertNull($value);
        $warnings = $this->evaluator->takeWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('@future', $warnings[0]);
    }

    public function testDoubleAtEscapeIsLiteralKey(): void
    {
        $value = $this->evaluator->resolve(['@@path' => 'literal'], Scope::root());
        $this->assertSame(['@path' => 'literal'], $value);
    }

    public function testPlainPathKeyIsNotIntercepted(): void
    {
        // "path"/"call" 键（无 @）是普通字面对象，永不拦截
        $value = $this->evaluator->resolve(['path' => '/company', 'call' => 'x'], Scope::root());
        $this->assertSame(['path' => '/company', 'call' => 'x'], $value);
    }

    public function testResolveDeepWalksContext(): void
    {
        $context = ['email' => ['@path' => '/company'], 'nested' => ['ok' => 1, 'flag' => ['@path' => '/flag']]];
        $resolved = $this->evaluator->resolveDeep($context, Scope::root());
        $this->assertSame(['email' => 'Acme', 'nested' => ['ok' => 1, 'flag' => true]], $resolved);
    }

    // ── coerceBoolean：七行转换表逐行 ─────────────────────────────────

    public function testCoerceBooleanTable(): void
    {
        $this->assertTrue(Evaluator::coerceBoolean(true));
        $this->assertFalse(Evaluator::coerceBoolean(false));
        $this->assertTrue(Evaluator::coerceBoolean(['valid' => true]));          // ValidationResult
        $this->assertFalse(Evaluator::coerceBoolean(['valid' => false]));
        $this->assertTrue(Evaluator::coerceBoolean('TRUE'));                     // 大小写不敏感
        $this->assertFalse(Evaluator::coerceBoolean('False'));
        $this->assertFalse(Evaluator::coerceBoolean(''));                        // 其它字符串
        $this->assertFalse(Evaluator::coerceBoolean('hello'));
        $this->assertFalse(Evaluator::coerceBoolean(0));                         // 0 → false
        $this->assertTrue(Evaluator::coerceBoolean(3));                          // 其它数字 → true
        $this->assertFalse(Evaluator::coerceBoolean(null));                      // null/缺席 → false
        $this->assertFalse(Evaluator::coerceBoolean(['x' => 1]));               // 其它对象 → false
    }

    // ── stringify：插值类型转换表 ─────────────────────────────────────

    public function testStringifyConversions(): void
    {
        $this->assertSame('', Evaluator::stringify(null));
        $this->assertSame('true', Evaluator::stringify(true));   // JSON 口径，非 PHP "1"
        $this->assertSame('false', Evaluator::stringify(false));
        $this->assertSame('42', Evaluator::stringify(42));
        $this->assertSame('1.5', Evaluator::stringify(1.5));
        $this->assertSame('text', Evaluator::stringify('text'));
        $this->assertSame('["a",1]', Evaluator::stringify(['a', 1]));
    }

    // ── formatString ──────────────────────────────────────────────────

    public function testFormatStringAbsoluteAndRelative(): void
    {
        $scope = Scope::root()->child('/employees/0', 0);
        $this->assertSame('Alice @ Acme', $this->evaluator->formatString('${name} @ ${/company}', $scope));
    }

    public function testFormatStringEscapedDollarBrace(): void
    {
        $this->assertSame('cost ${5}', $this->evaluator->formatString('cost \\${5}', Scope::root()));
    }

    public function testFormatStringUnclosedBraceIsLiteral(): void
    {
        $this->assertSame('hi ${oops', $this->evaluator->formatString('hi ${oops', Scope::root()));
    }

    public function testFormatStringFunctionCall(): void
    {
        $out = $this->evaluator->formatString('Hello ${formatString(value:"${/company}")}!', Scope::root());
        $this->assertSame('Hello Acme!', $out);
    }

    public function testFormatStringNamedArgsWithQuotedComma(): void
    {
        // 引号里的逗号/嵌套 ${} 不切分参数
        $out = $this->evaluator->formatString('${regex(value:"a,b", pattern:"a,b")}', Scope::root());
        // ValidationResult 对象在字符串槽位 = JSON 序列化（spec：Objects/Arrays → JSON）
        $this->assertSame('{"valid":true}', $out);
    }

    public function testFormatStringTypeConversionOfNull(): void
    {
        $this->assertSame('x=', $this->evaluator->formatString('x=${/missing}', Scope::root()));
    }

    // ── 函数注册表 ────────────────────────────────────────────────────

    public function testFnRequired(): void
    {
        $this->assertFalse($this->evaluator->callFunction('required', ['value' => null], Scope::root())['valid']);
        $this->assertFalse($this->evaluator->callFunction('required', ['value' => ''], Scope::root())['valid']);
        $this->assertFalse($this->evaluator->callFunction('required', ['value' => []], Scope::root())['valid']);
        $this->assertTrue($this->evaluator->callFunction('required', ['value' => 0], Scope::root())['valid']);  // 0 是「有值」
        $this->assertTrue($this->evaluator->callFunction('required', ['value' => 'x'], Scope::root())['valid']);
    }

    public function testFnEmail(): void
    {
        $this->assertTrue($this->evaluator->callFunction('email', ['value' => 'a@b.com'], Scope::root())['valid']);
        $this->assertFalse($this->evaluator->callFunction('email', ['value' => 'not-an-email'], Scope::root())['valid']);
    }

    public function testFnRegexAccepted(): void
    {
        // spec「regex examples」的接受例（search 语义）
        $this->assertTrue($this->evaluator->callFunction('regex', ['value' => 'user@example.com', 'pattern' => '^[a-z]+@[a-z]+\\.com$'], Scope::root())['valid']);
        $this->assertTrue($this->evaluator->callFunction('regex', ['value' => 'abc 123', 'pattern' => '\\w+\\s+\\w+'], Scope::root())['valid']);
    }

    public function testFnRegexRejectedDialect(): void
    {
        // spec「regex examples」的拒绝例：反向引用 / ReDoS 形态里的禁用结构（词法层）
        $r1 = $this->evaluator->callFunction('regex', ['value' => 'aa', 'pattern' => '(\\w)\\1'], Scope::root());
        $this->assertFalse($r1['valid']);
        $this->assertSame('EXPRESSION_ERROR', $r1['code']);
        $r2 = $this->evaluator->callFunction('regex', ['value' => 'aa', 'pattern' => '(?<name>a)'], Scope::root());
        $this->assertSame('EXPRESSION_ERROR', $r2['code']);  // 命名组（(?< 一刀切，含后行断言）
    }

    public function testFnRegexLengthCap(): void
    {
        $pattern = str_repeat('a', 257);
        $r = $this->evaluator->callFunction('regex', ['value' => 'x', 'pattern' => $pattern], Scope::root());
        $this->assertFalse($r['valid']);
        $this->assertSame('EXPRESSION_ERROR', $r['code']);
    }

    public function testFnLengthAndNumeric(): void
    {
        $this->assertTrue($this->evaluator->callFunction('length', ['value' => 'abcd', 'min' => 2, 'max' => 8], Scope::root())['valid']);
        $this->assertFalse($this->evaluator->callFunction('length', ['value' => 'a', 'min' => 2], Scope::root())['valid']);
        $this->assertTrue($this->evaluator->callFunction('numeric', ['value' => 5, 'min' => 1, 'max' => 10], Scope::root())['valid']);
        $this->assertFalse($this->evaluator->callFunction('numeric', ['value' => 50, 'max' => 10], Scope::root())['valid']);
        $this->assertFalse($this->evaluator->callFunction('numeric', ['value' => '5'], Scope::root())['valid']);  // 字符串不算数字
    }

    public function testFnAndOrNotComposeValidationResults(): void
    {
        $args = ['values' => [['valid' => true], ['valid' => false]]];
        $this->assertFalse($this->evaluator->callFunction('and', $args, Scope::root()));
        $args2 = ['values' => [['valid' => false], true]];
        $this->assertTrue($this->evaluator->callFunction('or', $args2, Scope::root()));
        $this->assertFalse($this->evaluator->callFunction('not', ['value' => true], Scope::root()));
        // 布尔组合器收「已强制转换」的参数：ValidationResult 在入参处取 valid
        $args3 = ['values' => [['valid' => true], 'true', 3]];
        $this->assertTrue($this->evaluator->callFunction('and', $args3, Scope::root()));
    }

    public function testIndexInsideAndOutsideTemplate(): void
    {
        $scope = Scope::root()->child('/employees/1', 1);
        $this->assertSame(1, $this->evaluator->callFunction('@index', [], $scope));
        $this->assertSame(2, $this->evaluator->callFunction('@index', ['offset' => 1], $scope));
        // Root Scope 调 @index = 协议错误（spec：MUST treat as error or invalid）
        $value = $this->evaluator->callFunction('@index', [], Scope::root());
        $this->assertNull($value);
        $this->assertCount(1, $this->evaluator->takeWarnings());
    }

    public function testUnregisteredFunctionIsNullWithWarning(): void
    {
        $value = $this->evaluator->callFunction('formatDate', ['value' => 'x'], Scope::root());
        $this->assertNull($value);
        $warnings = $this->evaluator->takeWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('formatDate', $warnings[0]);
    }
}
