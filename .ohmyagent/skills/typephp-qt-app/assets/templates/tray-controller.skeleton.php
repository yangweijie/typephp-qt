<?php

/**
 * Tray controller. Every decision (what a click means, when to quit, what the
 * tooltip says) is made here in PHP; Qt only reports what happened.
 *
 * Pairs with php-src/tray.stub.php and cpp-src/tray.cc — both use the fixed
 * `tray_*` function names, so this file needs no renaming.
 */
final class TrayController
{
    private mixed $app;
    private string $title;
    private int $clicks = 0;

    public function __construct(string $title)
    {
        $this->title = $title;
        $this->app = tray_create($title, self::iconPath());
        $this->updateStatus();
    }

    /**
     * Tray icon. Return '' for the built-in generated icon, or a path to use
     * your own image.
     *
     * A *relative* path resolves against the executable's folder (not the
     * process CWD), so dropping `icon.png` next to the exe and returning
     * 'icon.png' just works wherever the app is launched from.
     *
     * PNG / ICO / SVG all load. On Windows a multi-size .ico gives the crispest
     * result at every DPI; a single 64×64 PNG is fine everywhere else.
     *
     * TRAY_ICON overrides this without a rebuild. If the file cannot be loaded,
     * the C++ side logs it to stderr and falls back to the generated icon —
     * check stderr if the tray icon looks generic.
     */
    private static function iconPath(): string
    {
        // TRAY_ICON wins — handy for swapping the icon without a rebuild.
        $path = getenv('TRAY_ICON');
        if (is_string($path) && $path !== '') {
            return $path;
        }
        // The scaffolded project ships assets/icon.png, and package.bat copies
        // assets/ next to the executable. Replace that file to rebrand.
        return 'assets/icon.png';
    }

    public function run(): int
    {
        // Headless check: force the window visible, grab it, exit.
        $shot = getenv('TRAY_SCREENSHOT');
        if (is_string($shot) && $shot !== '') {
            tray_show_window($this->app, true);
            $ok = tray_snapshot($this->app, $shot);
            tray_destroy($this->app);
            if (!$ok) {
                throw new RuntimeException('截图失败：' . $shot);
            }
            return 0;
        }

        // The loop runs while the tray icon lives, not while a window is visible.
        while (tray_is_alive($this->app)) {
            tray_process_events($this->app);
            while (true) {
                $event = tray_poll_event($this->app);
                if ($event === []) {
                    break;
                }
                try {
                    $this->handle($event);
                } catch (Throwable $error) {
                    fwrite(STDERR, $this->title . ': ' . $error->getMessage() . PHP_EOL);
                }
            }
        }
        tray_destroy($this->app);
        return 0;
    }

    private function handle(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $value = (string) ($event['value'] ?? '');

        if ($type === 'tray_activated') {
            // Left click toggles the window; the others just count.
            $this->clicks++;
            if ($value === 'Trigger') {
                tray_show_window($this->app, !tray_is_window_visible($this->app));
            }
            $this->updateStatus();
        } elseif ($type === 'menu') {
            if ($value === 'toggle') {
                tray_show_window($this->app, !tray_is_window_visible($this->app));
            } elseif ($value === 'show') {
                tray_show_window($this->app, true);
            } elseif ($value === 'hide') {
                tray_show_window($this->app, false);
            } elseif ($value === 'notify') {
                tray_show_message($this->app, $this->title, '这是一条托盘气泡通知。');
            } elseif ($value === 'quit') {
                tray_quit($this->app);
                return;
            }
            $this->updateStatus();
        } elseif ($type === 'window_closed') {
            // The user hit the X: we keep running in the tray, we do not exit.
            $this->updateStatus();
        }
    }

    private function updateStatus(): void
    {
        $state = tray_is_window_visible($this->app) ? '窗口已显示' : '窗口已隐藏';
        tray_set_status($this->app, $this->title . ' · ' . $state . ' · 点击 ' . $this->clicks . ' 次');
    }
}
