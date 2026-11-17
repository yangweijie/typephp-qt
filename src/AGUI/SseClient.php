<?php

declare(strict_types=1);

namespace TypePHP\Qt\AGUI;

/**
 * SseClient — AG-UI 传输薄缝：把 \qt_sse_* 桥接原语包成「开流 → 拉帧 → 展开 → 归约」。
 *
 * 持一个 Decoder 实例（run() 时创建，流域展开状态不跨 run 泄漏）+ 一个
 * Session（reducer）。测试与真实桥接用**同一个类**（FakeBridge 提供同名全局
 * 函数，QtApp 先例），不引入闭包注入 —— AOT 闭包实参精确匹配 + 反射 arity
 * 探测的坑（QtApp::arityOf 先例）。
 *
 * ⚠ 泵限制（写进 docblock 是评审轮修订①）：帧到达依赖 Qt 事件泵，现行唯一
 * 泵入口在窗口（QtWindowBox::processEvents 的 16ms 嵌套循环）。最后一个窗口
 * cleanup 后存活流的 readyRead 永不再触发、数据永不到 —— v1 示例恒有窗口
 * （无头 runFrames 泵帧）；无窗口的 CLI 式用法 = v1 范围外。
 *
 * AOT 注意：类体引用 \qt_sse_* 全限定桥接函数 —— project.yml 列了本类就**必须**
 * 列 cpp-src/qt_sse.cc，否则链接期 undefined symbol（配对规则，与 41.10 的
 * qt_qml.cc 静态表教训同族、方向相反）。
 */
final class SseClient
{
    /** run() 时创建的 Decoder（流域展开状态；不提前创建 = 未开流零状态） */
    private ?Decoder $decoder = null;

    private ?Session $session;

    private int $stream = -1;

    /** \qt_sse_open 返回 -1（url 空 / QApplication 未建 / 无 Network 构建）时记的结构化错误；可读不抛 */
    private ?string $openError = null;

    /** @var string[] 传输层警告（坏 JSON 帧等）+ 解码器警告 */
    private array $warnings = [];

    private bool $flushed = false;

    public function __construct(?Session $session = null)
    {
        // resume 场景允许注入既有 Session 复用历史（展开状态仍按 run 新建，不泄漏）
        $this->session = $session ?? new Session();
    }

    /**
     * RunAgentInput 构造器（纯 PHP、无桥接依赖，可单测）：
     * 必填键存在性校验（缺 = 提前 \InvalidArgumentException，不等到开流失败才报），
     * 缺省键按 spec 语义补齐（state 缺省 = 空对象「什么都没约定」）。
     *
     * @param array<string, mixed> $input threadId / runId / messages 必填；
     *                                    tools / context / state / forwardedProps /
     *                                    resume / parentRunId / protocolVersion 可选
     *
     * @return array<string, mixed> 可直接 json_encode 的 RunAgentInput
     */
    public static function buildRunInput(array $input): array
    {
        foreach (['threadId', 'runId', 'messages'] as $required) {
            if (!array_key_exists($required, $input)) {
                throw new \InvalidArgumentException("RunAgentInput 缺必填键 \"{$required}\"");
            }
        }
        $input['state'] ??= [];
        return $input;
    }

    /**
     * POST RunAgentInput 开流。
     *
     * 返回流 id（从 1 起）；-1 时**不抛异常**，记结构化错误到 openError（后续
     * poll/status 可读）—— 桌面应用该走降级分支而不是崩。
     */
    public function run(string $url, array $input): int
    {
        $body = json_encode(self::buildRunInput($input));
        $this->decoder = new Decoder();
        if ($this->session === null) {
            $this->session = new Session();  // resume 场景注入的 Session 必须原样复用，只新建 Decoder（展开状态属流）
        }
        $this->openError = null;
        $this->flushed = false;
        $this->stream = \qt_sse_open([
            'url' => $url,
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string) $body,
        ]);
        if ($this->stream < 0) {
            $this->openError = 'qt_sse_open 返回 -1（url 空 / QApplication 未创建 / 构建未编入 Qt Network）';
            $this->stream = -1;
        }
        return $this->stream;
    }

    /**
     * 拉一轮：qt_sse_read 帧 → JSON 解码 → decoder->feed() 展开 → session->consume()。
     * 返回本轮新事件（展开后，含补发的隐式 END）；流状态到 done/error/unknown
     * （close 后）时自动 decoder->finish() flush 挂起 END（随最后一批交给 Session，
     * 挂起 END 不遗漏），之后恒返回 []。
     *
     * @return array<int, array<string, mixed>>
     */
    public function poll(int $max = 64): array
    {
        if ($this->stream < 0 || $this->flushed) {
            return [];
        }
        $frames = \qt_sse_read($this->stream, $max);
        $events = [];
        foreach ($frames as $frame) {
            $decoded = json_decode((string) ($frame['data'] ?? ''), true);
            if (!is_array($decoded)) {
                $this->warnings[] = 'SSE 帧不是合法 JSON，已跳过';
                continue;
            }
            $events = array_merge($events, $this->decoder->feed([$decoded]));
        }

        $status = \qt_sse_status($this->stream);
        if (in_array($status['state'] ?? '', ['done', 'error', 'unknown'], true)) {
            // 终态：flush 挂起隐式 END，随最后一批一起进 Session
            $events = array_merge($events, $this->decoder->finish());
            $this->flushed = true;
        }
        foreach ($events as $event) {
            $this->session->consume($event);
        }
        return $events;
    }

    /**
     * 合并状态：qt_sse_status 的流状态 + Session 的 run 状态。
     *
     * @return array<string, mixed> {state, status, error, run}
     */
    public function status(): array
    {
        $base = $this->stream >= 0
            ? \qt_sse_status($this->stream)
            : ['state' => 'unknown', 'status' => 0, 'error' => (string) $this->openError];
        if ($this->openError !== null) {
            $base['error'] = (string) $base['error'] . '；' . $this->openError;
        }
        $base['run'] = $this->session->runState();
        return $base;
    }

    /** abort + 释放，幂等（重复调用 / 未知流无副作用）。 */
    public function close(): void
    {
        if ($this->stream >= 0) {
            \qt_sse_close($this->stream);
        }
        $this->stream = -1;
    }

    /** 本 client 的 reducer（resume 复用同一 Session 时用构造注入）。 */
    public function session(): Session
    {
        return $this->session;
    }

    /** 渲染层唯一入口（= session->viewModel()）。 */
    public function viewModel(): array
    {
        return $this->session->viewModel();
    }

    /** 传输层 + 解码器警告（含 decoder 的映射/冲突/歧义警告）。 */
    public function warnings(): array
    {
        if ($this->decoder !== null) {
            return array_merge($this->warnings, $this->decoder->warnings());
        }
        return $this->warnings;
    }
}
