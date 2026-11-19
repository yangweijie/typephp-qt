<?php

declare(strict_types=1);

namespace TypePHP\Qt\A2UI;

/**
 * Scope — 路径解析作用域链（v1.0 spec「Path resolution & scope」）。
 *
 * Root Scope（base=""）是默认；List/Column/Row 等容器用 ChildList 模板
 * （children: {path, componentId}）对数据模型数组迭代时，**每个 item 开一个
 * Child Scope**：模板实例内「不以 / 开头的路径」相对当前 item 解析
 * （spec 例：迭代 /employees 时模板内 "name" → "/employees/N/name"）；
 * 绝对路径（/ 开头）恒从根解析，不受嵌套影响。
 *
 * @index 只在最内层 Collection Scope 合法（spec「The @index function」）：
 * 链上向上找最近的模板项下标；Root Scope 里调用 = 协议错误（renderers MUST
 * treat as error or invalid）——本实现记 warning 并返回 null，不抛异常
 * （协议层不中断，仓库兼容默认）。
 *
 * 不可变对象：每个模板项 child() 生成新节点，不回写父链（渲染期间同一 Scope
 * 被多个绑定复用，可变状态会互相污染——AGUI Decoder 流状态的教训同族）。
 */
final class Scope
{
    private function __construct(
        private readonly string $base,
        private readonly int $index,
        private readonly ?Scope $parent,
    ) {
    }

    /** Root Scope：base=""（空 = 根），无模板下标。 */
    public static function root(): self
    {
        return new self('', -1, null);
    }

    /** 进入模板项：$base 是该 item 的绝对指针（如 /employees/0），$index 是迭代下标。 */
    public function child(string $base, int $index): self
    {
        return new self($base, $index, $this);
    }

    /**
     * 路径解析："/" 开头 = 绝对（原样）；否则相对当前 base 拼接。
     * Root Scope 里的裸路径按根解析（spec 未定义根作用域相对路径，宽容取
     * "/<path>"——比报错更利于 agent 渐进输出）。
     */
    public function resolve(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }
        return DataModel::resolveRelative($this->base, $path);
    }

    /**
     * 最内层模板迭代下标（+ offset）；不在任何模板内返回 null（@index 越界）。
     */
    public function templateIndex(int $offset = 0): ?int
    {
        $scope = $this;
        while ($scope !== null) {
            if ($scope->index >= 0) {
                return $scope->index + $offset;
            }
            $scope = $scope->parent;
        }
        return null;
    }

    /** 当前 base（模板项绝对指针；Root Scope 返回 ""）。诊断/单测用。 */
    public function base(): string
    {
        return $this->base;
    }
}
