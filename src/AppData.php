<?php

declare(strict_types=1);

namespace TypePHP\Qt;

/**
 * AppData — 解析「这个应用该把文件放哪」。
 *
 * 三平台各有一套约定，而且**配置和缓存必须分开**：
 * 配置是用户资产（备份、漫游），缓存是可丢弃的（清理工具会删）。
 * 把它们混在一个目录里，用户下次点「清理缓存」就把设置一起清了。
 *
 * 用法：
 *   $data = new AppData('MyApp');
 *   $data->configPath('settings.json');   // …/MyApp/settings.json
 *   $data->cachePath('thumbs');           // …/MyApp/thumbs
 *
 * 目录在**首次访问时**才创建 —— 只读缓存的应用不该在磁盘上留空目录。
 */
final class AppData
{
    /** 应用名，同时是末级目录名。 */
    private string $appName;

    /** 组织名，给了就多一层目录（Windows 上 APPDATA 里常见这种布局）。 */
    private ?string $orgName;

    /** @var array<string, string> 已解析的目录，避免重复探测环境变量。 */
    private array $resolved = [];

    public function __construct(string $appName, ?string $orgName = null)
    {
        // 目录名会直接拼进路径，必须挡住穿越与空名 —— 否则 appName = '../..' 就能
        // 把文件写到任意位置。这里只允许「看起来像目录名」的字符，不做宽松放行。
        self::assertSafeSegment($appName, 'appName');
        if ($orgName !== null && $orgName !== '') {
            self::assertSafeSegment($orgName, 'orgName');
        }
        $this->appName = $appName;
        $this->orgName = ($orgName === null || $orgName === '') ? null : $orgName;
    }

    public function appName(): string
    {
        return $this->appName;
    }

    // ── 目录 ──

    /** 配置文件目录（用户资产，需备份）。 */
    public function configDir(): string
    {
        return $this->resolved['config'] ??= $this->ensure($this->baseDir('config'));
    }

    /** 缓存目录（可随时删除）。 */
    public function cacheDir(): string
    {
        return $this->resolved['cache'] ??= $this->ensure($this->baseDir('cache'));
    }

    /** 应用数据目录（数据库、用户生成的内容）。 */
    public function dataDir(): string
    {
        return $this->resolved['data'] ??= $this->ensure($this->baseDir('data'));
    }

    // ── 文件路径 ──

    /**
     * 拼接配置目录下的路径。$file 为空时返回目录本身。
     * 只拼一层：传进来的应当是不含目录分隔符的文件名（或相对子路径）。
     */
    public function configPath(string $file = ''): string
    {
        return self::join($this->configDir(), $file);
    }

    public function cachePath(string $file = ''): string
    {
        return self::join($this->cacheDir(), $file);
    }

    public function dataPath(string $file = ''): string
    {
        return self::join($this->dataDir(), $file);
    }

    // ── 内部 ──

    /**
     * @param 'config'|'cache'|'data' $kind
     */
    private function baseDir(string $kind): string
    {
        $family = PHP_OS_FAMILY;

        if ($family === 'Windows') {
            // Roaming 跟着域账户漫游，适合配置；缓存和数据放 Local —— 漫游一份几百 MB
            // 的缓存是登录时间的灾难。
            $root = $kind === 'config'
                ? (self::env('APPDATA') ?? self::home() . '/AppData/Roaming')
                : (self::env('LOCALAPPDATA') ?? self::home() . '/AppData/Local');
        } elseif ($family === 'Darwin') {
            // 三者必须落在不同的根上。曾经 config 与 data 都指向 Application Support，
            // 于是 configPath('x.json') 和 dataPath('x.json') 是同一个文件 —— Windows
            // 的 APPDATA/LOCALAPPDATA 天然分开，Linux 的 XDG 也天然分开，只有 macOS
            // 这一支写歪了，且单测在 Windows 上永远发现不了。
            // 取值与 Qt 的 QStandardPaths 对齐：AppConfigLocation = ~/Library/Preferences，
            // AppDataLocation = ~/Library/Application Support，CacheLocation = ~/Library/Caches。
            $root = match ($kind) {
                'config' => self::home() . '/Library/Preferences',
                'cache' => self::home() . '/Library/Caches',
                default => self::home() . '/Library/Application Support',
            };
        } else {
            // XDG Base Directory：变量为空或相对路径时按规范回落到默认值。
            [$var, $fallback] = [
                'config' => ['XDG_CONFIG_HOME', '.config'],
                'cache' => ['XDG_CACHE_HOME', '.cache'],
                'data' => ['XDG_DATA_HOME', '.local/share'],
            ][$kind];
            $xdg = self::env($var);
            $root = ($xdg !== null && str_starts_with($xdg, '/')) ? $xdg : self::home() . '/' . $fallback;
        }

        $segments = [$root];
        if ($this->orgName !== null) {
            $segments[] = $this->orgName;
        }
        $segments[] = $this->appName;

        return implode('/', array_map(static fn (string $s): string => rtrim($s, '/\\'), $segments));
    }

    /** 创建目录（含父级）并返回规范化后的路径。 */
    private function ensure(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目录: ' . $dir);
        }
        return $dir;
    }

    private static function join(string $dir, string $file): string
    {
        return $file === '' ? $dir : rtrim($dir, '/\\') . '/' . ltrim($file, '/\\');
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        return ($value === false || $value === '') ? null : $value;
    }

    private static function home(): string
    {
        // Windows 的 HOME 常常没设，USERPROFILE 才是权威；反过来在 Unix 上 HOME 才是权威。
        $home = self::env('HOME') ?? self::env('USERPROFILE');
        if ($home === null) {
            throw new \RuntimeException('无法确定用户主目录（HOME / USERPROFILE 都为空）');
        }
        return rtrim($home, '/\\');
    }

    private static function assertSafeSegment(string $value, string $label): void
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new \InvalidArgumentException("$label 不能为空或 . / ..");
        }
        if (strpbrk($value, "/\\:*?\"<>|") !== false) {
            throw new \InvalidArgumentException("$label 含有非法字符: $value");
        }
    }
}
