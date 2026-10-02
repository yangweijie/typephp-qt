# State and View

Writing the declarative pattern well comes down to keeping "state" and "UI" fully separate. This page is about where to draw the line.

## What belongs in state

**Yes**: anything the UI displays or that affects what it displays.

- The current text in an input, a checkbox's tick, the selected item in a combo
- A list's or table's data and its selected row
- Counters, progress values, loading flags
- Derived display text (compute it in `view()`, or cache it in state)

**No**: widget instances, direct references to widgets. You should never hold "that button object".

## Two styles

### State as an array (recommended for small apps)

```php
$state = ['name' => 'World', 'clicks' => 0];

$app->view(function () use (&$state): array {
    return WidgetTree::vbox([
        WidgetTree::lineEdit($state['name'], ['id' => 'name_input']),
        WidgetTree::label("{$state['clicks']} clicks", ['id' => 'count']),
    ]);
});
```

The `&` in `use (&$state)` matters — the handler must mutate **the same** array. Forget the `&` and you mutate a copy, which looks like "the UI does not react".

### State as an object (recommended for larger apps)

Once state grows, an array becomes a pile of string keys. Use your own class:

```php
final class AppState
{
    public string $name = 'World';
    public int $clicks = 0;
    /** @var list<Task> */
    public array $tasks = [];
}

$state = new AppState();

$app->view(function () use ($state): array {
    return WidgetTree::vbox([
        WidgetTree::label("Hello, {$state->name}", ['id' => 'greeting']),
        WidgetTree::label('Tasks: ' . count($state->tasks), ['id' => 'count']),
    ]);
});
```

Objects are passed by reference, so no `&` is needed. And you can hang methods off them:

```php
$app->on('add_btn', 'click', function () use ($app, $state) {
    $title = $app->text('new_task');
    if ($title !== '') {
        $state->tasks[] = new Task($title);   // domain logic lives in the class
    }
});
```

## Derived values: compute or cache

If it fits on one line inside `view()`, keep it out of state — one less thing to keep in sync:

```php
// good: derived from state
WidgetTree::label('Remaining ' . count(array_filter($state->tasks, fn($t) => !$t->done)))
```

Only cache in state when the computation is expensive (sorting a big table, reading from disk), and invalidate it when the inputs change.

## Reading widget values

To grab user input inside a handler, use `text()` / `value()` / `checked()`:

```php
$app->on('save_btn', 'click', function () use ($app, $state) {
    $state->name = $app->text('name_input');       // string
    $state->level = $app->value('level_slider');   // mixed (numeric for numeric controls)
    $state->agree = $app->checked('agree_box');    // bool
});
```

**Preferred: take it from the event instead**, avoiding the "read the widget" step altogether:

```php
$app->on('name_input', 'change', function (array $event) use ($state) {
    $state->name = (string) $event['value'];   // the event carries the new value
});
```

Both work. The event's `value` is what the control reported at the time; `text()` reads back from the widget — they can differ at the boundary where a widget was just rebuilt, so the event value is more reliable.

## Common mistakes

### Forgetting `&`, so the UI never updates

```php
$app->view(function () use ($state) { ... });   // array: mutates a copy!
$app->view(function () use (&$state) { ... });  // correct
```

### Mutating widgets in a handler

```php
// don't
$app->on('btn', 'click', function () use ($app) {
    $app->setTitle('New title');   // imperative, fights the declarative view
});

// do
$app->on('btn', 'click', function () use (&$state) {
    $state['title'] = 'New title';   // the next frame's view() reflects it
});
```

A few things really are imperative (window title, menu, status bar, tray, timers) and go through `setTitle()` / `setMenu()` and friends — they are **not in the widget tree**, so they do not participate in the diff.

### Side effects inside the view

`view()` runs every frame (about 60 times a second). Do not write files, make network calls, or change state inside it:

```php
// don't
$app->view(function () use ($state) {
    file_put_contents('log.txt', 'render');   // 60 writes a second
    return WidgetTree::label('...');
});
```

## Use `patch()` for hot paths

For places that change many times a second — a log stream, a progress refresh — rebuilding the whole view every frame is wasteful. `patch()` is the imperative bypass:

```php
$app->patch([
    ['op' => 'call', 'id' => 'log', 'method' => 'appendRows', 'args' => [[['10:32', 'started']]]],
]);
```

**But it is only a bypass** — the next `render()` re-syncs from the tree, so appended rows only survive until then. To persist, write back into state. See [Patching](/guide/patching.md).
