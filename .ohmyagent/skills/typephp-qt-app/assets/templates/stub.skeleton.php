<?php
/**
 * PHP-visible bridge API. Function bodies MUST be empty — this file only tells
 * the compiler the signatures and types of the C++ functions in cpp-src/.
 * Each function here `<app>_bar` maps to a C++ symbol `php_<app>_bar`.
 *
 * Convention: arrays in, arrays out. Opaque C++ objects travel as `mixed`.
 */

function <APP>_create(string $title): mixed {}
function <APP>_is_open(mixed $window): bool {}
function <APP>_process_events(mixed $window): void {}
function <APP>_poll_event(mixed $window): array {}
function <APP>_set_view(mixed $window, array $rows, array $metrics, string $selectedId): void {}
function <APP>_show_error(mixed $window, string $message): void {}
function <APP>_snapshot(mixed $window, string $path): bool {}
function <APP>_destroy(mixed $window): void {}
