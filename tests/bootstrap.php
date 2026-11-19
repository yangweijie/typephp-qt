<?php

declare(strict_types=1);

/**
 * 测试引导：注册 FakeBridge 的纯 PHP 全局函数。
 *
 * 必须在任何测试运行前加载，因为 QtApp / WidgetTree 直接调用 qt_* 函数。
 */

require_once __DIR__ . '/../src/FakeBridge.php';

/**
 * std 是 tpc 的编译期类（polyfills.php，随编译器安装），AOT 产物在编译期把
 * std::any($x) 降级成 php::Var；纯 PHP 环境（phpunit）没有这个类。src/ 里
 * 用了 \std::any() 做「一个局部一种类型」陷阱的降级出口（DataModel::get），
 * 这里补一个运行期等价物：返回值即参数、无类型收窄 —— 与编译期语义一致。
 */
if (!class_exists('std')) {
    final class std
    {
        public static function any(mixed $value = null): mixed
        {
            return $value;
        }
    }
}
