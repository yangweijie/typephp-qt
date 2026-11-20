#!/bin/sh
# 冻结测量脚本（A2UI 回放吞吐实验，a2ui-support 段）：
# 重建 a2ui-demo → 纯 PHP 回放 20000 条信封消息计时 → 输出主指标。
# autoresearch 冻结契约：实验循环期间本文件不得修改。
#
# 为什么先 build：--bench 段是 AOT 编译产物（main.php + src/A2UI/ 编进二进制），
# 循环里改 PHP 侧代码必须重新编译才生效（main.php 的 --bench 在 new QtApp() 之前
# exit(0)，不建 QApplication、不碰 Qt 事件循环，计时干净）。
set -eu
cd "$(dirname "$0")/.."

php bin/qtphp build examples/a2ui-demo >/dev/null

OUT=$(examples/a2ui-demo/build/a2ui-demo --bench 20000)
PER_S=$(printf '%s\n' "$OUT" | sed -n 's/^METRIC a2ui_replay_messages_per_s=//p')
[ -n "$PER_S" ] || { echo "measure.sh: bench 输出缺 a2ui_replay_messages_per_s：$OUT" >&2; exit 1; }
TOTAL_MS=$(printf '%s\n' "$OUT" | sed -n 's/^METRIC a2ui_replay_total_ms=//p')

# 主指标名与实验段配置（a2ui_replay_messages_per_s，higher）一致；total_ms 作次要观测
echo "METRIC a2ui_replay_messages_per_s=$PER_S"
echo "METRIC a2ui_replay_total_ms=$TOTAL_MS"
