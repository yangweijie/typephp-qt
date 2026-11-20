#!/bin/sh
# 冻结验收回压（A2UI 回放吞吐实验，a2ui-support 段）：
# 任何一轮优化在 keep 之前，PHP 侧单测与桥接契约 lint 必须全绿。
# autoresearch 冻结契约：实验循环期间本文件不得修改。
set -eu
cd "$(dirname "$0")/.."

php vendor/bin/phpunit --no-coverage
php bin/qtphp lint
