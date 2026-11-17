<?php

declare(strict_types=1);

namespace TypePHP\Qt\AGUI;

/**
 * Session — AG-UI 事件 reducer：把展开后的规范事件归约成渲染层视图模型。
 *
 * 为什么吃展开后的事件：chunk 紧凑展开是传输层的流变换（打开项/挂起 END
 * 属于某条流），Session 若持有展开状态，同一 Session 被两个 run 复用
 * （resume）时会跨 run 泄漏 —— 展开状态由 SseClient 的 Decoder 实例承载，
 * 本类只做「事件 → 状态」的纯归约。
 *
 * 语义钉死（评审轮修订②）：
 *   * STATE_SNAPSHOT 整体替换（不合并）；STATE_DELTA 走 JsonPatch，失败记 warnings；
 *   * MESSAGES_SNAPSHOT = 全量历史**替换**（不是追加；生产方拥有的全部消息、有序）；
 *   * 文本 delta 按 messageId 拼接，重开继续追加；工具调用 args 拼接 + result 落位；
 *   * steps 打开/关闭配对，重开同名已开 step 记 warning；
 *   * SUBAGENT_* 记账键名是 `subagents`（不是 subAgents，拼写陷阱）。
 */
final class Session
{
    /** @var array<int, array<string, mixed>> 按序消息（snapshot 替换后保持 snapshot 序） */
    private array $messages = [];

    /** @var array<string, int> messageId → messages 下标（重开继续追加用） */
    private array $messageIndex = [];

    /** @var array<string, array<string, mixed>> 工具调用表：toolCallId → 记录 */
    private array $toolCalls = [];

    /** @var array<string, mixed> */
    private array $state = [];

    /** @var array<string, array<string, mixed>> stepId → ['name'=>…, 'status'=>open|closed] */
    private array $steps = [];

    /** @var array<string, array<string, mixed>> ACTIVITY_* 物化：activityId → 数据 */
    private array $activity = [];

    /** @var array<string, array<string, mixed>> 键名 subagents（拼写陷阱），不是 subAgents */
    private array $subagents = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<string, mixed> run 生命周期：status / threadId / runId / outcome / error */
    private array $run = ['status' => 'idle'];

    /**
     * 归约一条展开后的规范事件（未知类型静默忽略，仓库兼容默认）。
     */
    public function consume(array $event): void
    {
        $type = $event['type'] ?? '';
        switch ($type) {
            case Decoder::RUN_STARTED:
                $this->run = [
                    'status' => 'running',
                    'threadId' => $event['threadId'] ?? null,
                    'runId' => $event['runId'] ?? null,
                ];
                break;

            case Decoder::RUN_FINISHED:
                // outcome 可选：缺省 = legacy 成功（旧 0.x 实现不产生该字段，必须容忍）。
                // 注意不用 array union（+ 保留左值，status 会停在 running）
                $this->run = [
                    'status' => 'finished',
                    'outcome' => $event['outcome'] ?? 'legacy',
                    'threadId' => $this->run['threadId'] ?? null,
                    'runId' => $this->run['runId'] ?? null,
                    'reasoning' => $this->run['reasoning'] ?? false,
                ];
                break;

            case Decoder::RUN_ERROR:
                $this->run = [
                    'status' => 'error',
                    'message' => $event['message'] ?? '',
                    'code' => $event['code'] ?? null,
                    'threadId' => $this->run['threadId'] ?? null,
                    'runId' => $this->run['runId'] ?? null,
                ];
                break;

            case Decoder::STEP_STARTED:
                $stepId = (string) ($event['stepId'] ?? $event['name'] ?? '');
                if (isset($this->steps[$stepId]) && $this->steps[$stepId]['status'] === 'open') {
                    // 生产方 MUST NOT 重开同名已打开 step；消费方重开 = 记 warning（宽容路径）
                    $this->warnings[] = 'step "' . $stepId . '" 重开（已打开中），正常流不应出现';
                    break;
                }
                $this->steps[$stepId] = ['name' => $event['name'] ?? $stepId, 'status' => 'open'];
                break;

            case Decoder::STEP_FINISHED:
                $stepId = (string) ($event['stepId'] ?? $event['name'] ?? '');
                if (isset($this->steps[$stepId])) {
                    $this->steps[$stepId]['status'] = 'closed';
                }
                break;

            case Decoder::TEXT_MESSAGE_START:
                $this->openMessage((string) $event['messageId'], $event['role'] ?? 'assistant', null);
                break;

            case Decoder::TEXT_MESSAGE_CONTENT:
                $this->appendText((string) $event['messageId'], $event['delta'] ?? '');
                break;

            case Decoder::TEXT_MESSAGE_END:
                $this->closeMessage((string) $event['messageId']);
                break;

            case Decoder::TOOL_CALL_START:
                $toolCallId = (string) $event['toolCallId'];
                if (isset($this->toolCalls[$toolCallId])) {
                    $this->warnings[] = 'tool call "' . $toolCallId . '" 重复 START（宽容重开）';
                }
                $this->toolCalls[$toolCallId] = [
                    'id' => $toolCallId,
                    'name' => $event['toolCallName'] ?? '',
                    'args' => '',
                    'result' => null,
                ];
                break;

            case Decoder::TOOL_CALL_ARGS:
                $toolCallId = (string) $event['toolCallId'];
                if (!isset($this->toolCalls[$toolCallId])) {
                    $this->toolCalls[$toolCallId] = ['id' => $toolCallId, 'name' => '', 'args' => '', 'result' => null];
                }
                // args 拼接后是**参数文本**（TOOL_CALL_END 只声明参数完整、非校验合法）
                $this->toolCalls[$toolCallId]['args'] .= (string) ($event['delta'] ?? '');
                break;

            case Decoder::TOOL_CALL_END:
                // 显式 END 不需要动作：args 已拼完，result 由 TOOL_CALL_RESULT 落位
                break;

            case Decoder::TOOL_CALL_RESULT:
                $toolCallId = (string) ($event['toolCallId'] ?? '');
                if (isset($this->toolCalls[$toolCallId])) {
                    $this->toolCalls[$toolCallId]['result'] = $event['content'] ?? null;
                }
                // spec：TOOL_CALL_RESULT 的 content（字符串或有序 ContentPart 列表）
                // 新建一条 role="tool" 消息
                $messageId = (string) ($event['messageId'] ?? ('tool_' . $toolCallId));
                $this->messages[] = [
                    'id' => $messageId,
                    'role' => 'tool',
                    'content' => $event['content'] ?? null,
                    'toolCallId' => $toolCallId,
                ];
                if ($messageId !== '') {
                    $this->messageIndex[$messageId] = count($this->messages) - 1;
                }
                break;

            case Decoder::REASONING_START:
                $this->run['reasoning'] = true;  // 不用 +（union 保留左值，重开会卡 false）
                break;

            case Decoder::REASONING_END:
                $this->run['reasoning'] = false;
                break;

            case Decoder::REASONING_MESSAGE_START:
                $this->openMessage((string) $event['messageId'], $event['role'] ?? 'reasoning', null);
                break;

            case Decoder::REASONING_MESSAGE_CONTENT:
                $this->appendText((string) $event['messageId'], $event['delta'] ?? '');
                break;

            case Decoder::REASONING_MESSAGE_END:
                $this->closeMessage((string) $event['messageId']);
                break;

            case Decoder::REASONING_ENCRYPTED_VALUE:
                // 加密值只登记在消息上，不解密不渲染
                $index = $this->messageIndex[(string) ($event['messageId'] ?? '')] ?? null;
                if ($index !== null) {
                    $this->messages[$index]['encrypted'] = $event['encryptedValue'] ?? $event['value'] ?? null;
                }
                break;

            case Decoder::STATE_SNAPSHOT:
                // 整体替换（MUST NOT 合并）
                $this->state = is_array($event['state'] ?? null) ? $event['state'] : [];
                break;

            case Decoder::STATE_DELTA:
                $result = JsonPatch::apply($this->state, $event['delta'] ?? []);
                $this->state = $result['doc'];
                foreach ($result['warnings'] as $warning) {
                    $this->warnings[] = $warning;
                }
                break;

            case Decoder::MESSAGES_SNAPSHOT:
                // 全量历史替换（不是追加）：生产方拥有的全部消息、有序
                $this->messages = [];
                $this->messageIndex = [];
                foreach ((array) ($event['messages'] ?? []) as $message) {
                    if (!is_array($message)) {
                        continue;
                    }
                    $this->messages[] = $this->normalizeSnapshotMessage($message);
                    $id = (string) ($message['id'] ?? '');
                    if ($id !== '') {
                        $this->messageIndex[$id] = count($this->messages) - 1;
                    }
                }
                break;

            case Decoder::ACTIVITY_SNAPSHOT:
                // replace 缺省 true；显式 false = 浅合并进既有数据
                $activityId = (string) ($event['id'] ?? '');
                if ($activityId === '') {
                    break;
                }
                $data = is_array($event['data'] ?? null) ? $event['data'] : [];
                if (($event['replace'] ?? true) === false && isset($this->activity[$activityId]) && is_array($this->activity[$activityId])) {
                    $this->activity[$activityId] = array_merge($this->activity[$activityId], $data);
                } else {
                    $this->activity[$activityId] = $data;
                }
                break;

            case Decoder::ACTIVITY_DELTA:
                $activityId = (string) ($event['id'] ?? '');
                if ($activityId === '') {
                    break;
                }
                $delta = is_array($event['delta'] ?? null) ? $event['delta'] : [];
                $this->activity[$activityId] = array_merge(
                    is_array($this->activity[$activityId] ?? null) ? $this->activity[$activityId] : [],
                    $delta
                );
                break;

            case Decoder::SUBAGENT_STARTED:
                $key = (string) ($event['subagentId'] ?? $event['subagent'] ?? 'subagent');
                $this->subagents[$key] = [
                    'name' => $event['name'] ?? $key,
                    'status' => 'running',
                    'subagentRunId' => $event['subagentRunId'] ?? null,
                ];
                break;

            case Decoder::SUBAGENT_FINISHED:
                $key = (string) ($event['subagentId'] ?? $event['subagent'] ?? 'subagent');
                $this->subagents[$key]['status'] = 'finished';
                break;

            case Decoder::SUBAGENT_ERROR:
                $key = (string) ($event['subagentId'] ?? $event['subagent'] ?? 'subagent');
                $this->subagents[$key]['status'] = 'error';
                $this->subagents[$key]['message'] = $event['message'] ?? '';
                break;

            // RAW / CUSTOM 带 untrusted 标记（解码器打的）：不推导协议行为、不物化
            // —— 标记是留给应用侧渲染层的纪律位，Session 不消费它们
            default:
                if (isset($event['unknown'])) {
                    $this->warnings[] = '未知事件类型 ' . (string) $type . '，已忽略';
                }
                break;
        }
    }

    /**
     * 渲染层唯一入口（方案 42.6 钉死六个键；subagents 记账挂在 run 里，
     * 键名 subagents —— 拼写陷阱不是 subAgents）。
     *
     * @return array<string, mixed>
     */
    public function viewModel(): array
    {
        $messages = [];
        foreach ($this->messages as $message) {
            $view = $message + ['status' => 'closed'];
            $messages[] = $view;
        }
        return [
            'run' => $this->run + ['subagents' => $this->subagents],
            'messages' => $messages,
            'state' => $this->state,
            'steps' => $this->steps,
            'activity' => $this->activity,
            'warnings' => $this->warnings,
        ];
    }

    /** run 生命周期切片（SseClient::status() 合并 qt_sse_status 用）。 */
    public function runState(): array
    {
        return $this->run;
    }

    /**
     * 工具调用表（toolCallId → ['id','name','args','result']，args 拼接后的参数文本，
     * result 由 TOOL_CALL_RESULT 落位）。不进 viewModel 六键（渲染层走 messages 的
     * role=tool 条目）；单测与 42.9 示例的工具调用表格读这里。
     *
     * @return array<string, array<string, mixed>>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    /** 打开一条消息：已关重开 = 继续追加（spec 允许），未知直接创建（宽容）。 */
    private function openMessage(string $id, string $role, mixed $extra): void
    {
        if ($id === '') {
            return;
        }
        if (isset($this->messageIndex[$id])) {
            $index = $this->messageIndex[$id];
            if (($this->messages[$index]['status'] ?? '') === 'closed') {
                $this->messages[$index]['status'] = 'open';
                $this->warnings[] = "已关闭的消息 \"{$id}\" 重开追加（宽容处理）";
            }
            return;
        }
        $this->messages[] = ['id' => $id, 'role' => $role, 'content' => '', 'status' => 'open'];
        $this->messageIndex[$id] = count($this->messages) - 1;
    }

    /** 文本 delta 按 messageId 拼接；已关消息再 CONTENT = 重开继续追加（宽容语义）。 */
    private function appendText(string $id, string $delta): void
    {
        if ($id === '') {
            return;
        }
        if (isset($this->messageIndex[$id])) {
            $index = $this->messageIndex[$id];
            if (($this->messages[$index]['status'] ?? '') === 'closed') {
                $this->messages[$index]['status'] = 'open';  // 重开：等下一个 END 再关
            }
            $this->messages[$index]['content'] .= $delta;
            return;
        }
        $this->openMessage($id, 'assistant', null);
        if (isset($this->messageIndex[$id])) {
            $this->messages[$this->messageIndex[$id]]['content'] .= $delta;
        }
    }

    private function closeMessage(string $id): void
    {
        if ($id === '' || !isset($this->messageIndex[$id])) {
            return;
        }
        $this->messages[$this->messageIndex[$id]]['status'] = 'closed';
    }

    /** snapshot 消息归一：缺省键补齐（status 一律 closed —— 历史消息是已关闭的）。 */
    private function normalizeSnapshotMessage(array $message): array
    {
        $id = (string) ($message['id'] ?? '');
        return [
            'id' => $id,
            'role' => $message['role'] ?? 'assistant',
            'content' => $message['content'] ?? '',
            'status' => 'closed',
        ] + array_diff_key($message, ['id' => 1, 'role' => 1, 'content' => 1, 'status' => 1]);
    }
}
