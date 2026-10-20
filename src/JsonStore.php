<?php

declare(strict_types=1);

namespace TypePHP\Qt;

/**
 * JsonStore — 单文件 JSON 键值存储，写入是原子的。
 *
 * 为什么不是「直接 file_put_contents」：进程被杀 / 断电时，写一半的文件会让
 * 下次启动 json_decode 直接失败，用户的全部设置一起丢。所以写进同目录的临时文件
 * 再 rename —— rename 在同一文件系统上是原子的，读者要么看到旧文件，要么看到新文件。
 *
 * 用法：
 *   $store = new JsonStore($data->configPath('settings.json'));
 *   $store->load();                       // 文件不存在时得到空数组
 *   $store->set('theme', 'dark');
 *   $store->flush();                      // 原子落盘
 *   $store->get('theme', 'light');
 *
 * 键用点号表示层级（'window.width'）会让「深合并」和「删一个键」都变复杂，
 * 这里故意只支持**顶层键**：需要层级就直接存数组。
 */
final class JsonStore
{
    private string $path;

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $loaded = false;

    public function __construct(string $path)
    {
        if ($path === '') {
            throw new \InvalidArgumentException('JsonStore 路径不能为空');
        }
        $this->path = $path;
    }

    public function path(): string
    {
        return $this->path;
    }

    // ── 读写 ──

    /**
     * 从磁盘载入。文件不存在视为空存储（首次运行，不是错误）。
     *
     * @throws \RuntimeException 文件存在但内容不是合法 JSON 对象
     */
    public function load(): self
    {
        $this->loaded = true;
        $this->data = [];

        if (!is_file($this->path)) {
            return $this;
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new \RuntimeException('无法读取: ' . $this->path);
        }
        if (trim($raw) === '') {
            return $this;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // 不静默当成空存储 —— 那会让下一次 flush 把用户剩下的数据覆盖掉。
            throw new \RuntimeException('JSON 解析失败: ' . $this->path . ' (' . $e->getMessage() . ')', 0, $e);
        }

        // `[]` 和 `{}` 解码后都是 array，只能靠 array_is_list 区分。空数组放行（空存储），
        // 非空的列表说明顶层不是对象 —— 那多半是文件写错了，不能当成键值表用。
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \RuntimeException('顶层必须是 JSON 对象: ' . $this->path);
        }

        $this->data = $decoded;
        return $this;
    }

    /** 惰性载入：第一次访问时自动 load()，调用方不必手动初始化。 */
    private function ensureLoaded(): void
    {
        if (!$this->loaded) {
            $this->load();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureLoaded();
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function has(string $key): bool
    {
        $this->ensureLoaded();
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): self
    {
        $this->ensureLoaded();
        $this->data[$key] = $value;
        return $this;
    }

    /** 删除一个键；返回它原本是否存在（便于区分「删掉了」和「本来就没有」）。 */
    public function delete(string $key): bool
    {
        $this->ensureLoaded();
        if (!array_key_exists($key, $this->data)) {
            return false;
        }
        unset($this->data[$key]);
        return true;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $this->ensureLoaded();
        return $this->data;
    }

    /** 整体替换（比如载入了一份预设）。 */
    public function replace(array $data): self
    {
        $this->loaded = true;
        $this->data = $data;
        return $this;
    }

    // ── 落盘 ──

    /**
     * 原子写入。返回是否真的写了 —— 内容没变时跳过，避免无谓的磁盘抖动
     * （view() 每帧都可能调到这里）。
     */
    public function flush(): bool
    {
        $this->ensureLoaded();

        $json = json_encode(
            $this->data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";

        $existing = is_file($this->path) ? @file_get_contents($this->path) : false;
        if ($existing === $json) {
            return false;
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建目录: ' . $dir);
        }

        // 临时文件必须和目标**同目录**：跨文件系统的 rename 会退化成拷贝+删除，
        // 就不再原子了。uniqid 加上 pid 防止并发写互相踩。
        $tmp = $dir . '/.' . basename($this->path) . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';

        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('无法写入临时文件: ' . $tmp);
        }

        try {
            if (flock($handle, LOCK_EX) === false) {
                throw new \RuntimeException('无法锁定临时文件: ' . $tmp);
            }
            $written = fwrite($handle, $json);
            if ($written !== strlen($json)) {
                throw new \RuntimeException('写入不完整: ' . $tmp);
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('无法替换: ' . $this->path);
        }

        return true;
    }
}
