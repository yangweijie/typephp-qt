<?php
/**
 * System-tray bridge declarations. Bodies MUST be empty — types only.
 * Pairs with assets/templates/tray.skeleton.cc (same names, php_ prefix in C++).
 *
 * Note the lifecycle API: `tray_is_alive` (the tray icon exists) instead of
 * `tray_is_open` (a window is visible). A tray app has no visible window most
 * of the time, so a window-based loop condition exits immediately.
 */

function tray_create(string $title, string $iconPath): mixed {}
function tray_is_alive(mixed $app): bool {}
function tray_process_events(mixed $app): void {}
function tray_poll_event(mixed $app): array {}
function tray_set_status(mixed $app, string $text): void {}
function tray_show_message(mixed $app, string $title, string $body): void {}
function tray_show_window(mixed $app, bool $show): void {}
function tray_is_window_visible(mixed $app): bool {}
function tray_quit(mixed $app): void {}
function tray_snapshot(mixed $app, string $path): bool {}
function tray_destroy(mixed $app): void {}
