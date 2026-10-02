<?php

/**
 * Controller: filtering, selection and every mutation live here.
 * The Qt bridge only reports what the user did — it never decides anything.
 */
final class AppController
{
    private AppStore $store;
    private mixed $window;
    private string $search = '';
    private string $selectedId = '';

    public function __construct(AppStore $store)
    {
        $this->store = $store;
        $this->window = <APP>_create('TypePHP <APP>');
    }

    public function run(): int
    {
        $this->refresh();

        // Headless verification: render one frame, save a PNG, exit.
        $shot = getenv('<APP>_SCREENSHOT');
        if (is_string($shot) && $shot !== '') {
            if (!<APP>_snapshot($this->window, $shot)) {
                throw new RuntimeException('截图失败：' . $shot);
            }
            <APP>_destroy($this->window);
            return 0;
        }

        // PHP owns the loop; Qt only pumps in slices.
        while (<APP>_is_open($this->window)) {
            <APP>_process_events($this->window);
            while (true) {
                $event = <APP>_poll_event($this->window);
                if ($event === []) {
                    break;
                }
                try {
                    $this->handle($event);
                } catch (Throwable $error) {
                    <APP>_show_error($this->window, $error->getMessage());
                }
            }
        }
        <APP>_destroy($this->window);
        return 0;
    }

    private function handle(array $event): void
    {
        $type = (string) ($event['type'] ?? '');
        $id = (string) ($event['id'] ?? '');
        if ($type === 'search') {
            $this->search = trim((string) ($event['value'] ?? ''));
            $this->selectedId = '';
        } elseif ($type === 'select') {
            $this->selectedId = $id;
        } elseif ($type === 'advance') {
            $this->store->advance($id);
            $this->selectedId = $id;
        } elseif ($type === 'delete') {
            $this->store->delete($id);
            $this->selectedId = '';
        }
        $this->refresh();
    }

    private function refresh(): void
    {
        $rows = [];
        $total = 0;
        foreach ($this->store->all() as $row) {
            $total++;
            if ($this->search !== '' && stripos($row['title'], $this->search) === false) {
                continue;
            }
            $row['status_label'] = match ($row['status']) {
                'todo' => '待处理',
                'doing' => '进行中',
                default => '已完成',
            };
            $rows[] = $row;
        }
        <APP>_set_view($this->window, $rows, [
            'total' => $total,
            'visible' => count($rows),
        ], $this->selectedId);
    }
}
