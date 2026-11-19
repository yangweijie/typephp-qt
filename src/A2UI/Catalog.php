<?php

declare(strict_types=1);

namespace TypePHP\Qt\A2UI;

/**
 * Catalog — A2UI Basic Catalog 组件类型 → 本仓库 WidgetTree 控件类型的映射表
 * （F59 §59.2 钉死；零新桥接函数，映射是纯 PHP 层的职责）。
 *
 * 18 个 Basic Catalog 组件里 13 个有原生对应；5 个无对应（Icon/Video/AudioPlayer/
 * Modal/DateTimeInput）走**占位 label**（spec 的 progressive rendering 语义允许渲染器
 * 按能力降级；「不静默白屏」是本仓库 qml 占位的既有纪律）。
 *
 * 属性名口径：catalog.json 本窗口抓取超时（F59 §59.5），映射只使用**协议正文示例
 * 背书过的键**（Text.text / Button.child+action / Column·Row.children /
 * List.children 模板 {path, componentId}）；其余组件的标签类键按
 * label/text/src 候选探测、未命中 = 占位文本（未知属性静默忽略的仓库兼容默认）。
 */
final class Catalog
{
    /** 有原生对应的组件类型 → WidgetTree 工厂键。 */
    public const MAP = [
        'Text' => 'label',
        'Row' => 'hbox',
        'Column' => 'vbox',
        'List' => 'scroll',
        'Card' => 'group',
        'Tabs' => 'tabs',
        'Divider' => 'separator',
        'Button' => 'button',
        'CheckBox' => 'checkbox',
        'TextField' => 'lineEdit',
        'Slider' => 'slider',
        'ChoicePicker' => 'combo',
        'Image' => 'image',
    ];

    /** 无原生对应 → 占位 label 的组件类型。 */
    public const PLACEHOLDER = ['Icon', 'Video', 'AudioPlayer', 'Modal', 'DateTimeInput'];

    /** ComponentId / 组件类型名的 UAX #31 规则（spec「Catalog Entity Naming Rules」）。 */
    public const ID_PATTERN = '/^[\p{XID_Start}_][\p{XID_Continue}]*$/u';

    /** root 组件挂载 Surface 容器（协议保留，catalog 不得定义同名组件）。 */
    public const ROOT_ID = 'root';

    public static function isSupported(string $type): bool
    {
        return isset(self::MAP[$type]);
    }

    /** WidgetTree 工厂键；未认识 / 占位类型返回 null（调用方负责占位）。 */
    public static function widgetType(string $type): ?string
    {
        return self::MAP[$type] ?? null;
    }

    public static function isPlaceholder(string $type): bool
    {
        return in_array($type, self::PLACEHOLDER, true);
    }

    /** @var array<string, bool> UAX #31 判定缓存（键 = id 串；判定是纯函数） */
    private static array $idValidity = [];

    /** UAX #31 校验：`user_card`/`userCard` 合法；`user-card`/`1st_item` 非法。 */
    public static function isValidId(string $id): bool
    {
        // 解析是纯函数（id 串 → 布尔，与运行时状态无关）⇒ 按串缓存判定结果：
        // 回放里同一批组件 id 每条消息重复过 Unicode 属性正则（\p{XID_*} 明显
        // 慢于 isset），是 applyComponents 的主要常数开销
        if (isset(self::$idValidity[$id])) {
            return self::$idValidity[$id];
        }
        $valid = preg_match(self::ID_PATTERN, $id) === 1;
        self::$idValidity[$id] = $valid;
        return $valid;
    }
}
