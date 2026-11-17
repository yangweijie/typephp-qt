<?php

declare(strict_types=1);

namespace TypePHP\Qt\AGUI;

/**
 * Decoder — AG-UI 事件的**流域有状态**展开器（SSE 帧 → 规范事件流）。
 *
 * 为什么不是静态无状态：紧凑 chunk（*_CHUNK）的展开是有状态流变换 —— spec
 * （streaming.md「Closing a chunk stream」）“END is automatically emitted when
 * the stream switches to a new message ID or when the stream completes”，
 * 打开的 messageId / toolCallId / reasoning 段与挂起的隐式 END 属于**这条流**：
 * 跨 poll 分批 feed() 不能丢状态（否则重发 START、done 时挂起 END 无人 flush）。
 * 展开状态放 Decoder 实例而不是 Session，是因为 Session 可被两个 run 复用
 * （resume），展开状态泄漏过去就脏了。
 *
 * 用法（SseClient 每个 run 持一个实例）：
 *   $decoder = new Decoder();
 *   foreach (每批解码出的事件 $batch) { $out = $decoder->feed($batch); … }
 *   $tail = $decoder->finish();   // 流 done/error 时 flush 全部挂起隐式 END
 *
 * 离线回放 / 单测入口：Decoder::decodeSse(string $sseText) 静态一次性便捷。
 *
 * 宽容原则（与仓库「未知默认静默忽略」同族，QT_DEBUG_STRICT=1 可见）：
 *   * 重复 START = 先自动 END 再重开（warning）；已关消息再 CONTENT = 重开追加（warning）
 *   * TOOL_CALL_CHUNK 后续重复同名冲突 = 终止该项展开 + warning，不中断整条流
 *   * legacy THINKING_TEXT_MESSAGE_* 映射成 REASONING_MESSAGE_*（mapping warning）
 *   * 未知 type 原样透传并带 'unknown'=>true；RAW / CUSTOM 带 'untrusted'=>true
 */
final class Decoder
{
    // ── 事件类型常量（AG-UI 1.0；META_EVENT 是 draft，不进常量表，按未知透传）──

    // Lifecycle
    public const RUN_STARTED = 'RUN_STARTED';
    public const RUN_FINISHED = 'RUN_FINISHED';
    public const RUN_ERROR = 'RUN_ERROR';
    public const STEP_STARTED = 'STEP_STARTED';
    public const STEP_FINISHED = 'STEP_FINISHED';
    // Text（显式三件 + 紧凑 chunk）
    public const TEXT_MESSAGE_START = 'TEXT_MESSAGE_START';
    public const TEXT_MESSAGE_CONTENT = 'TEXT_MESSAGE_CONTENT';
    public const TEXT_MESSAGE_END = 'TEXT_MESSAGE_END';
    public const TEXT_MESSAGE_CHUNK = 'TEXT_MESSAGE_CHUNK';
    // Tool
    public const TOOL_CALL_START = 'TOOL_CALL_START';
    public const TOOL_CALL_ARGS = 'TOOL_CALL_ARGS';
    public const TOOL_CALL_END = 'TOOL_CALL_END';
    public const TOOL_CALL_RESULT = 'TOOL_CALL_RESULT';
    public const TOOL_CALL_CHUNK = 'TOOL_CALL_CHUNK';
    // Reasoning
    public const REASONING_START = 'REASONING_START';
    public const REASONING_END = 'REASONING_END';
    public const REASONING_MESSAGE_START = 'REASONING_MESSAGE_START';
    public const REASONING_MESSAGE_CONTENT = 'REASONING_MESSAGE_CONTENT';
    public const REASONING_MESSAGE_END = 'REASONING_MESSAGE_END';
    public const REASONING_MESSAGE_CHUNK = 'REASONING_MESSAGE_CHUNK';
    public const REASONING_ENCRYPTED_VALUE = 'REASONING_ENCRYPTED_VALUE';
    // State
    public const STATE_SNAPSHOT = 'STATE_SNAPSHOT';
    public const STATE_DELTA = 'STATE_DELTA';
    public const MESSAGES_SNAPSHOT = 'MESSAGES_SNAPSHOT';
    // Activity
    public const ACTIVITY_SNAPSHOT = 'ACTIVITY_SNAPSHOT';
    public const ACTIVITY_DELTA = 'ACTIVITY_DELTA';
    // Subagents
    public const SUBAGENT_STARTED = 'SUBAGENT_STARTED';
    public const SUBAGENT_FINISHED = 'SUBAGENT_FINISHED';
    public const SUBAGENT_ERROR = 'SUBAGENT_ERROR';
    // Passthrough
    public const RAW = 'RAW';
    public const CUSTOM = 'CUSTOM';
    // legacy（1.0 退役，容错映射进 REASONING_*；映射表名就是这三个，没有 THINKING_MESSAGE_*）
    public const THINKING_TEXT_MESSAGE_START = 'THINKING_TEXT_MESSAGE_START';
    public const THINKING_TEXT_MESSAGE_CONTENT = 'THINKING_TEXT_MESSAGE_CONTENT';
    public const THINKING_TEXT_MESSAGE_END = 'THINKING_TEXT_MESSAGE_END';

    // 逐事件分类用的清单全部提为 const 哈希表（R2 提 const 去构造，R3 换哈希去线性）：
    // expandOne 热路径上每个事件原先要现场构造 6 个小数组 + 一次 31 元素的
    // knownTypes() 临时清单（R2 已消）；in_array 对清单仍是 O(n) 线性（KNOWN 每事件
    // 全扫 31 项），R3 换 type => true 哈希表 + isset O(1)。语义与同一元素集合的
    // in_array(..., true) 逐一相同。
    /** legacy THINKING_* → REASONING_* 映射源（1.0 退役；映射表名就是这三个）。 */
    private const LEGACY_SET = [
        self::THINKING_TEXT_MESSAGE_START => true,
        self::THINKING_TEXT_MESSAGE_CONTENT => true,
        self::THINKING_TEXT_MESSAGE_END => true,
    ];
    /** 触发③：run 级事件关所有 lane。 */
    private const RUN_LEVEL_SET = [
        self::RUN_STARTED => true,
        self::RUN_FINISHED => true,
        self::RUN_ERROR => true,
        self::MESSAGES_SNAPSHOT => true,
    ];
    /** 触发④：subagent 终止事件关归属 lane。 */
    private const SUBAGENT_END_SET = [self::SUBAGENT_FINISHED => true, self::SUBAGENT_ERROR => true];
    /** 触发② stand-aside 例外（不关 lane：不可信透传 / 物化 / 开新 lane / 加密值）。 */
    private const STAND_ASIDE_SET = [
        self::RAW => true,
        self::ACTIVITY_SNAPSHOT => true,
        self::ACTIVITY_DELTA => true,
        self::REASONING_ENCRYPTED_VALUE => true,
        self::SUBAGENT_STARTED => true,
    ];
    /** 不可信 passthrough 打 untrusted 标记。 */
    private const UNTRUSTED_SET = [self::RAW => true, self::CUSTOM => true];
    /** SUBAGENT_* 归属 lane 判定（laneOf）。 */
    private const SUBAGENT_LANE_SET = [
        self::SUBAGENT_STARTED => true,
        self::SUBAGENT_FINISHED => true,
        self::SUBAGENT_ERROR => true,
    ];
    /** 已知事件全集（unknown 标记判定；META_EVENT 是 draft，不入表按未知透传）。 */
    private const KNOWN_SET = [
        self::RUN_STARTED => true,
        self::RUN_FINISHED => true,
        self::RUN_ERROR => true,
        self::STEP_STARTED => true,
        self::STEP_FINISHED => true,
        self::TEXT_MESSAGE_START => true,
        self::TEXT_MESSAGE_CONTENT => true,
        self::TEXT_MESSAGE_END => true,
        self::TEXT_MESSAGE_CHUNK => true,
        self::TOOL_CALL_START => true,
        self::TOOL_CALL_ARGS => true,
        self::TOOL_CALL_END => true,
        self::TOOL_CALL_RESULT => true,
        self::TOOL_CALL_CHUNK => true,
        self::REASONING_START => true,
        self::REASONING_END => true,
        self::REASONING_MESSAGE_START => true,
        self::REASONING_MESSAGE_CONTENT => true,
        self::REASONING_MESSAGE_END => true,
        self::REASONING_MESSAGE_CHUNK => true,
        self::REASONING_ENCRYPTED_VALUE => true,
        self::STATE_SNAPSHOT => true,
        self::STATE_DELTA => true,
        self::MESSAGES_SNAPSHOT => true,
        self::ACTIVITY_SNAPSHOT => true,
        self::ACTIVITY_DELTA => true,
        self::SUBAGENT_STARTED => true,
        self::SUBAGENT_FINISHED => true,
        self::SUBAGENT_ERROR => true,
        self::RAW => true,
        self::CUSTOM => true,
        self::THINKING_TEXT_MESSAGE_START => true,
        self::THINKING_TEXT_MESSAGE_CONTENT => true,
        self::THINKING_TEXT_MESSAGE_END => true,
    ];

    /**
     * 隐式 END 的五个触发（spec「Closing a chunk stream」）：
     * ① 同 lane chunk 换开不同 item；② lane 内到达 message/tool-call/step/state/
     * custom/reasoning 族事件（stand-aside 例外不关：RAW / ACTIVITY_* /
     * REASONING_ENCRYPTED_VALUE；SUBAGENT_STARTED 开新 lane 不动本 lane）；
     * ③ run 级事件 RUN_STARTED / RUN_FINISHED / RUN_ERROR / MESSAGES_SNAPSHOT 关所有 lane；
     * ④ 归属 subagent 终止关其 lane；⑤ 流结束（finish()）。
     * metadata-only 续接（无 delta）合法且**不**关闭。
     */

    /** @var array<string, array{text:?string, tool:?string, reasoning:?string}> 各 lane 打开中的紧凑项 */
    private array $openItems = ['' => ['text' => null, 'tool' => null, 'reasoning' => null]];

    /** @var array<string, array<string, string[]>> lane → 族 → 已关 item 标识（重开判重 warning 用） */
    private array $closedItems = ['' => ['text' => [], 'tool' => [], 'reasoning' => []]];

    /** @var string[] */
    private array $warnings = [];

    private bool $finished = false;

    /**
     * 喂一批已 json_decode 的 AG-UI 事件，返回本批展开后的规范事件。
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    public function feed(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            if (!is_array($event) || !isset($event['type']) || !is_string($event['type'])) {
                $this->warnings[] = '丢弃形态非法事件（缺 type 字符串）';
                continue;
            }
            $this->expandOne($event, $out);
        }
        return $out;
    }

    /**
     * 流结束（done/error）：flush 全部 lane 的挂起隐式 END（触发⑤）。
     * 返回本批补发的事件（正常流里就是最后挂起的那些 END）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function finish(): array
    {
        $out = [];
        foreach (array_keys($this->openItems) as $laneKey) {
            $out = array_merge($out, $this->closeLane($laneKey));
        }
        $this->finished = true;
        return $out;
    }

    /** 本流累计的 warning 文本。 */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * 静态一次性便捷：SSE 文本 → 帧 → JSON 解码 → 展开 → finish，全在内存里。
     * 离线回放与单测入口（不依赖网络、不依赖桥接函数）。
     *
     * @return array{events: array<int, array<string, mixed>>, warnings: string[]}
     */
    public static function decodeSse(string $sseText): array
    {
        $decoder = new self();
        $events = [];
        $frameIndex = 0;
        foreach (self::parseSseFrames($sseText) as $frameData) {
            $frameIndex++;
            $decoded = json_decode($frameData, true);
            if (!is_array($decoded)) {
                // 坏 JSON 帧记 warning、不中断后续帧（宽容默认）。
                $decoder->warnings[] = "第 {$frameIndex} 帧不是合法 JSON，已跳过";
                continue;
            }
            $events = array_merge($events, $decoder->feed([$decoded]));
        }
        $events = array_merge($events, $decoder->finish());
        return ['events' => $events, 'warnings' => $decoder->warnings];
    }

    // ── SSE 帧解码（data: 行 → 事件对象）──

    /**
     * SSE 分帧：空行分帧；一帧内多条 data: 行按 \n 拼接；
     * event:/id:/retry: 与 “:” 注释行忽略（AG-UI 事件类型在 JSON 的 type 字段里，
     * 不用 SSE 的 event 字段）。返回每帧的 data 文本（无 data 的帧不产）。
     *
     * @return string[]
     */
    private static function parseSseFrames(string $text): array
    {
        $frames = [];
        $dataLines = [];
        foreach (preg_split("/\r\n|\n/", $text) as $line) {
            if ($line === '') {
                if ($dataLines !== []) {
                    $frames[] = implode("\n", $dataLines);
                    $dataLines = [];
                }
                continue;
            }
            if ($line[0] === ':') {
                continue;  // 注释行（含 “: ” 前缀行）
            }
            if (str_starts_with($line, 'data:')) {
                $value = substr($line, 5);
                // SSE 字段值最多剥一个前导空格
                $dataLines[] = ($value !== '' && $value[0] === ' ') ? substr($value, 1) : $value;
            }
            // event:/id:/retry: 与未知字段：协议层面忽略
        }
        // 流结束时的悬挂帧（空行未收尾）：按 end-of-input 语义处理掉
        if ($dataLines !== []) {
            $frames[] = implode("\n", $dataLines);
        }
        return $frames;
    }

    // ── 单事件展开 ──

    /**
     * 展开单事件并直接追加进 $out（R5：原先返回临时数组、feed() 再二次遍历逐条
     * 追加，每事件一次临时数组 + 双重遍历是纯开销；按引用写回即消掉两者）。
     */
    private function expandOne(array $event, array &$out): void
    {
        $type = $event['type'];

        // legacy THINKING_* 容错映射（1.0 已退役，记 mapping warning 后按映射名走管线）
        if (isset(self::LEGACY_SET[$type])) {
            // 映射表名：THINKING_TEXT_MESSAGE_X → REASONING_MESSAGE_X（表内没有 THINKING_MESSAGE_*）
            $event['type'] = str_replace('THINKING_TEXT_MESSAGE_', 'REASONING_MESSAGE_', $type);
            $this->warnings[] = 'legacy 事件 ' . $type . ' 已映射为 ' . $event['type'] . '（1.0 退役）';
        }

        switch ($event['type']) {
            case self::TEXT_MESSAGE_CHUNK:
                $this->expandChunk($event, 'text', $out);
                return;
            case self::TOOL_CALL_CHUNK:
                $this->expandChunk($event, 'tool', $out);
                return;
            case self::REASONING_MESSAGE_CHUNK:
                $this->expandChunk($event, 'reasoning', $out);
                return;
        }

        // 非 chunk 事件：先做隐式 END 的关闭判定（触发②③④），再透传。
        $lane = $this->laneOf($event);

        if (isset(self::RUN_LEVEL_SET[$event['type']])) {
            // 触发③：run 级事件关所有 lane
            foreach (array_keys($this->openItems) as $key) {
                foreach ($this->closeLane($key) as $closed) {
                    $out[] = $closed;
                }
            }
        } elseif (isset(self::SUBAGENT_END_SET[$event['type']])) {
            // 触发④：关归属 subagent 的 lane
            foreach ($this->closeLane($this->subagentKeyOf($event)) as $closed) {
                $out[] = $closed;
            }
        } elseif (!isset(self::STAND_ASIDE_SET[$event['type']])) {
            // 触发②：message/tool-call/step/state/custom/reasoning 族到达，
            // 关本 lane 的打开项。stand-aside 例外见上面 in_array 白名单：
            // 它们不推导协议行为（RAW 不可信、ACTIVITY 物化、SUBAGENT_STARTED 只开新 lane）。
            $closed = $this->closeLane($lane);
            // 同族同项的显式 CONTENT/START 不在此列 —— chunk 展开路径（expandChunk）
            // 与显式事件路径的分界：chunk 走 expandChunk；显式同族事件若与打开的
            // 紧凑项冲突，上面已把该项关掉，下面按显式事件重开。
            foreach ($closed as $item) {
                $out[] = $item;
            }
        }
        if ($event['type'] === self::SUBAGENT_STARTED) {
            $this->ensureLane($this->subagentKeyOf($event));
        }

        // 透传标记：未知 type 与不可信 passthrough（消费方纪律位，见类头注释）
        if (!isset(self::KNOWN_SET[$event['type']])) {
            $event['unknown'] = true;
        }
        if (isset(self::UNTRUSTED_SET[$event['type']])) {
            $event['untrusted'] = true;
        }
        $out[] = $event;
    }

    /**
     * 紧凑 chunk → 显式 START/CONTENT(+ARGS) 展开，隐式 END 挂起（R5：结果直接追加进 $out）。
     */
    private function expandChunk(array $event, string $family, array &$out): void
    {
        $tagLane = $this->laneOf($event);
        $identifier = $family === 'tool' ? $event['toolCallId'] ?? null : $event['messageId'] ?? null;

        // 无标识续接解析（spec「Continuation chunks」）：父 lane 的同类打开流优先，
        // 否则全部 lane 里唯一打开流；多 lane 同类打开 = 歧义 MUST 拒绝（跳过该 chunk）。
        if ($identifier === null) {
            if ($this->openItems[''][$family] !== null) {
                $tagLane = '';
                $identifier = $this->openItems[''][$family];
            } else {
                $openElsewhere = [];
                foreach ($this->openItems as $key => $items) {
                    if ($key !== '' && $items[$family] !== null) {
                        $openElsewhere[] = $items[$family];
                    }
                }
                if (count($openElsewhere) === 1) {
                    $identifier = $openElsewhere[0];
                } else {
                    $this->warnings[] = 'chunk 歧义续接（无标识且' . (count($openElsewhere) > 1 ? '多 lane 同类打开' : '无打开项') . '），已跳过该 chunk';
                    return;
                }
            }
        }

        // 标识匹配优先：该 item 若在某 lane 打开着，就是那条 lane 的续接（不动 lane 归属）
        $openLane = $this->findOpenLane($family, $identifier);
        if ($openLane !== null) {
            $lane = $openLane;
            $continuation = true;
        } else {
            $lane = $tagLane;
            $continuation = false;
        }

        if ($continuation && $family === 'tool') {
            // continuation 可重复 toolCallName，但只允许同值；冲突 = 终止该调用展开 + warning（v1 宽容：不断流）
            $name = $event['toolCallName'] ?? null;
            $known = $this->toolCallNames[$identifier] ?? null;
            if ($name !== null && $known !== null && $name !== $known) {
                $this->warnings[] = "TOOL_CALL_CHUNK 后续 chunk 的 toolCallName \"{$name}\" 与首值 \"{$known}\" 冲突，终止该调用展开";
                $out = array_merge($out, $this->closeOne($lane, 'tool'));
                return;
            }
        }

        if ($continuation) {
            // 续接：只有真带 delta 才发 CONTENT/ARGS。metadata-only 续接合法且不关闭。
            $delta = $this->chunkDelta($event, $family);
            if ($delta !== null && $delta !== '') {
                $out[] = $this->contentEvent($family, $identifier, $delta);
            }
            return;
        }

        // 新 item：触发① —— 同 lane 已打开别的 item，先补发旧者的隐式 END
        $laneState = $this->openItems[$lane];
        if ($laneState[$family] !== null) {
            $out = array_merge($out, $this->closeOne($lane, $family));
        }
        if ($this->wasClosed($lane, $family, (string) $identifier)) {
            $this->warnings[] = '已关闭的 ' . $family . " 项 \"{$identifier}\" 被 chunk 重开（宽容处理，正常流不应出现）";
        }
        $this->openItems[$lane][$family] = $identifier;

        if ($family === 'tool') {
            $name = $event['toolCallName'] ?? null;
            if ($name === null) {
                $this->warnings[] = 'TOOL_CALL_CHUNK 首 chunk 缺 toolCallName（spec MUST），终止该调用展开';
                return;
            }
            $this->toolCallNames[$identifier] = $name;
            $out[] = ['type' => self::TOOL_CALL_START, 'toolCallId' => $identifier, 'toolCallName' => $name];
        } else {
            $out[] = $this->startEvent($family, $identifier, $event);
        }

        $delta = $this->chunkDelta($event, $family);
        if ($delta !== null && $delta !== '') {
            $out[] = $this->contentEvent($family, $identifier, $delta);
        }
    }

    /** chunk 的增量载荷字段：tool 族 spec 用 argsDelta（容忍 delta 同义位），其余族用 delta。 */
    private function chunkDelta(array $event, string $family): ?string
    {
        if ($family === 'tool') {
            $value = $event['argsDelta'] ?? $event['delta'] ?? null;
        } else {
            $value = $event['delta'] ?? null;
        }
        return is_string($value) ? $value : null;
    }

    private function startEvent(string $family, string $identifier, array $event): array
    {
        switch ($family) {
            case 'reasoning':
                return ['type' => self::REASONING_MESSAGE_START, 'messageId' => $identifier];
            default:
                return [
                    'type' => self::TEXT_MESSAGE_START,
                    'messageId' => $identifier,
                    'role' => $event['role'] ?? 'assistant',
                    'threadId' => $event['threadId'] ?? null,
                    'runId' => $event['runId'] ?? null,
                ];
        }
    }

    private function contentEvent(string $family, string $identifier, string $delta): array
    {
        switch ($family) {
            case 'tool':
                return ['type' => self::TOOL_CALL_ARGS, 'toolCallId' => $identifier, 'delta' => $delta];
            case 'reasoning':
                return ['type' => self::REASONING_MESSAGE_CONTENT, 'messageId' => $identifier, 'delta' => $delta];
            default:
                return ['type' => self::TEXT_MESSAGE_CONTENT, 'messageId' => $identifier, 'delta' => $delta];
        }
    }

    /** 找 identifier 打开所在的 lane（跨 lane 续接判定）；未打开返回 null。 */
    private function findOpenLane(string $family, string $identifier): ?string
    {
        foreach ($this->openItems as $key => $items) {
            if ($items[$family] === $identifier) {
                return $key;
            }
        }
        return null;
    }

    // ── lane 管理 ──

    /** 事件归属 lane：subagent 事件（SUBAGENT_*）归其 subagent lane，其余归父 lane（''）。 */
    private function laneOf(array $event): string
    {
        if (isset(self::SUBAGENT_LANE_SET[$event['type']])) {
            $key = $this->subagentKeyOf($event);
            $this->ensureLane($key);
            return $key;
        }
        return '';
    }

    /** subagent 标识解析：字段名容错（spec 键名陷阱：subagents 是复数记账键，这里单数取事件字段）。 */
    private function subagentKeyOf(array $event): string
    {
        foreach (['subagentId', 'subagent', 'id'] as $field) {
            if (isset($event[$field]) && is_string($event[$field]) && $event[$field] !== '') {
                return $event[$field];
            }
        }
        return 'subagent';
    }

    private function ensureLane(string $key): void
    {
        if (!isset($this->openItems[$key])) {
            $this->openItems[$key] = ['text' => null, 'tool' => null, 'reasoning' => null];
            $this->closedItems[$key] ??= ['text' => [], 'tool' => [], 'reasoning' => []];
        }
    }

    /** 关单个 lane 全部打开项，补发隐式 END（族序 text → tool → reasoning）。 */
    private function closeLane(string $lane): array
    {
        // R4 快路径：lane 无任何打开项时直接 [] —— 原实现每个事件都要付
        // ['text','tool','reasoning'] 字面量 + 3×closeOne（各带 null 判定）+ 3×array_merge，
        // 而回放中绝大多数事件的 lane 是空的；结果与逐族 closeOne 返回 [] 再 merge 完全相同。
        $open = $this->openItems[$lane] ?? null;
        if ($open === null || ($open['text'] === null && $open['tool'] === null && $open['reasoning'] === null)) {
            return [];
        }
        $out = [];
        foreach (['text', 'tool', 'reasoning'] as $family) {
            $out = array_merge($out, $this->closeOne($lane, $family));
        }
        return $out;
    }

    private function closeOne(string $lane, string $family): array
    {
        $identifier = $this->openItems[$lane][$family] ?? null;
        if ($identifier === null) {
            return [];
        }
        $this->openItems[$lane][$family] = null;
        $this->closedItems[$lane][$family][] = (string) $identifier;
        switch ($family) {
            case 'text':
                return [['type' => self::TEXT_MESSAGE_END, 'messageId' => $identifier]];
            case 'reasoning':
                return [['type' => self::REASONING_MESSAGE_END, 'messageId' => $identifier]];
            case 'tool':
                return [['type' => self::TOOL_CALL_END, 'toolCallId' => $identifier]];
        }
        return [];
    }

    private function wasClosed(string $lane, string $family, string $identifier): bool
    {
        return in_array($identifier, $this->closedItems[$lane][$family] ?? [], true);
    }

    /** 首 chunk 记下的 toolCallName，供 continuation 冲突判定。 */
    private array $toolCallNames = [];
}
