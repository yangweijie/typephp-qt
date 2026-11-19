<?php

declare(strict_types=1);

namespace TypePHP\Qt\A2UI;

/**
 * Outbound — Renderer→Agent 信封构造（v1.0 spec「Renderer-to-agent event messages」）。
 *
 * 信封规则与 Agent→Renderer 同构：顶层 `version` + 恰好一个协议键。v1 实现三种：
 *   * action —— 用户触发组件动作（Button 的 action.event）；
 *   * rendererFunctionResponse —— callRendererFunction 的必达回执（void 也要回，
 *     spec 原文 "MUST always send ... even if the function's return type is void"；
 *     本仓库 v1 未注册任何渲染器函数 ⇒ 恒以 INVALID_FUNCTION_CALL 错误回执）；
 *   * error —— 信封级错误（INTEGRITY_ERROR 等；surfaceId 与 functionCallId 互斥）。
 * callAgentFunction（渲染器调 agent 函数）v1 范围外（F59 §59.4）——没有 live agent
 * 的离线回放里不存在该触发面，落了也是死代码。
 *
 * timestamp：ISO 8601 UTC（spec 例 "2026-06-02T08:57:23Z" 形态），默认当前时刻；
 * 单测可注入固定值（时间不该是断言的随机项）。
 */
final class Outbound
{
    /**
     * @param array<string, mixed> $context 动态绑定已解析（Evaluator::resolveDeep 产物）
     */
    public static function action(
        string $name,
        string $surfaceId,
        string $sourceComponentId,
        array $context = [],
        ?string $userMessage = null,
        ?string $timestamp = null,
    ): array {
        $payload = [
            'name' => $name,
            'surfaceId' => $surfaceId,
            'sourceComponentId' => $sourceComponentId,
            'timestamp' => $timestamp ?? gmdate('Y-m-d\TH:i:s\Z'),
            'context' => $context,
        ];
        if ($userMessage !== null) {
            $payload['userMessage'] = $userMessage;
        }
        return ['version' => '1.0', 'action' => $payload];
    }

    /** callRendererFunction 的回执（value 与 error 二选一；本 v1 只用 error 臂）。 */
    public static function rendererFunctionResponse(string $functionCallId, mixed $value, ?array $error = null): array
    {
        $payload = ['functionCallId' => $functionCallId];
        if ($error !== null) {
            $payload['error'] = $error;
        } else {
            $payload['value'] = $value;
        }
        return ['version' => '1.0', 'rendererFunctionResponse' => $payload];
    }

    /** 信封级错误（surfaceId 与 functionCallId 互斥，spec 原文）。 */
    public static function error(string $code, string $message, ?string $surfaceId = null, ?string $functionCallId = null): array
    {
        $payload = ['code' => $code, 'message' => $message];
        if ($surfaceId !== null) {
            $payload['surfaceId'] = $surfaceId;
        }
        if ($functionCallId !== null) {
            $payload['functionCallId'] = $functionCallId;
        }
        return ['version' => '1.0', 'error' => $payload];
    }
}
