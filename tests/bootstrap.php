<?php

declare(strict_types=1);

/**
 * 测试引导：注册 FakeBridge 的纯 PHP 全局函数。
 *
 * 必须在任何测试运行前加载，因为 QtApp / WidgetTree 直接调用 qt_* 函数。
 */

require_once __DIR__ . '/../src/FakeBridge.php';
