<?php

/**
 * Demo data layer. In-memory so the scaffold builds and runs with zero setup.
 *
 * To persist, swap this for PDO SQLite (the extension is loaded via runtime.ini):
 *
 *   $this->db = new PDO('sqlite:' . $file);
 *   $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
 *   $this->db->exec('CREATE TABLE IF NOT EXISTS items ('
 *       . 'id TEXT PRIMARY KEY, title TEXT NOT NULL, status TEXT NOT NULL)');
 *
 * Keeping this class Qt-free is the point: you can test it with a plain
 * `php tests/store_test.php` without compiling anything.
 */
final class AppStore
{
    /** @var array<int, array<string, string>> */
    private array $rows = [
        ['id' => '1', 'title' => '设计首页', 'status' => 'done'],
        ['id' => '2', 'title' => '接通 Qt 界面', 'status' => 'doing'],
        ['id' => '3', 'title' => '打包发布', 'status' => 'todo'],
    ];

    private int $next = 4;

    /** @return array<int, array<string, string>> */
    public function all(): array
    {
        return $this->rows;
    }

    public function advance(string $id): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row['id'] !== $id) {
                continue;
            }
            $this->rows[$index]['status'] = match ($row['status']) {
                'todo' => 'doing',
                'doing' => 'done',
                default => 'todo',
            };
            return;
        }
        throw new InvalidArgumentException('任务不存在：' . $id);
    }

    public function delete(string $id): void
    {
        $kept = [];
        $found = false;
        foreach ($this->rows as $row) {
            if ($row['id'] === $id) {
                $found = true;
                continue;
            }
            $kept[] = $row;
        }
        if (!$found) {
            throw new InvalidArgumentException('任务不存在：' . $id);
        }
        $this->rows = $kept;
    }

    public function add(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('标题不能为空');
        }
        $id = (string) $this->next++;
        $this->rows[] = ['id' => $id, 'title' => $title, 'status' => 'todo'];
        return $id;
    }
}
