# Patching

`patch()` is the **imperative bypass** of the declarative view, for hot paths that change many times a second — a log stream, a progress refresh — where rebuilding the whole tree every frame is wasteful.

```php
$app->patch([
    ['op' => 'set',  'id' => 'status', 'props' => ['text' => 'Done']],
    ['op' => 'call', 'id' => 'log', 'method' => 'appendRows',
     'args' => [[['10:32', 'started'], ['10:33', 'ready']], ['l1', 'l2']]],
]);
```

Each operation is one of two shapes: `set` changes properties, `call` invokes a method.

## `set` — change properties

`props` follows **exactly the same semantics** as [declarative properties](/guide/properties.md):

```php
$app->patch([
    ['op' => 'set', 'id' => 'progress_bar', 'props' => ['value' => 65]],
    ['op' => 'set', 'id' => 'hint', 'props' => ['text' => 'Working…', 'visible' => true]],
]);
```

Structural fields (`rows` / `columns` / `row_ids` / `nodes` / `headers`) trigger a full table/tree rebuild and preserve the selection by row id:

```php
$app->patch([
    ['op' => 'set', 'id' => 'tbl', 'props' => [
        'rows'    => [['B', '2'], ['A', '1']],
        'row_ids' => ['r2', 'r1'],
    ]],
]);
```

## `call` — invoke a method

`args` are **positional**. Six methods are implemented:

| method | args | Scope |
|---|---|---|
| `appendRows` | `args[0]` = row list (each row is a list of cells), `args[1]` = optional row id list | `table` only |
| `clear` | none | Empties a table, a tree/list/combo's items, or a text control |
| `setText` | `args[0]` = text | `label` `button` `lineedit` `textedit` `checkbox` `radio` |
| `setValue` | `args[0]` = value | Numeric for progress/slider/spin, text for input controls |
| `select` | `args[0]` = id or index | **Exactly the same semantics** as that control's `current` property |
| `focus` | none | Give keyboard focus to the control |

```php
$app->patch([
    ['op' => 'call', 'id' => 'log',  'method' => 'appendRows', 'args' => [[['10:32', 'started']]]],
    ['op' => 'call', 'id' => 'body', 'method' => 'setText',    'args' => ['new content']],
    ['op' => 'call', 'id' => 'bar',  'method' => 'setValue',   'args' => [80]],
    ['op' => 'call', 'id' => 'name', 'method' => 'focus'],
]);
```

## The core constraint: it is a bypass, not new state

::: danger What `call` changes only survives until the next render
After execution the changed properties' diff signature is **invalidated**, so the next `render()` re-syncs **from the tree**.

Which means: appended rows and cleared content are only valid until that render. **To persist, write back into state.**
:::

That gives you a choice:

### Keep the data in state (recommended)

```php
// append to state at the same time
$state['logLines'][] = [date('H:i:s'), $line];

// view() builds the full table — a re-render does not lose it
$app->view(function () use (&$state): array {
    return WidgetTree::table(['Time', 'Event'], $state['logLines'], ['id' => 'log_tbl']);
});
```

`patch` is only for **immediate effect** (no waiting for the next frame); state is the source of truth.

### Pure `patch` (only for short-lived content)

```php
$app->patch([['op' => 'call', 'id' => 'log', 'method' => 'appendRows', 'args' => [[$row]]]]);
```

The next re-render — for any reason, say the user clicks another button — and those rows are gone.

## When to use `patch`

| Situation | Use `patch`? |
|---|---|
| Log stream, progress bar, live numbers | ✅ dozens of times a second; a full tree rebuild is wasteful |
| UI change caused by a user action | ❌ change state and let `view()` reflect it |
| Data that must persist | ❌ write state |
| Appending one row to a big table | ✅ but also write back into state |

**Default to state.** Only introduce `patch` for a spot whose render cost is a measured problem.

## Unknown content is silently ignored

An unknown `method` or an unknown `id` is silently ignored — consistent with unknown properties. That makes degradation and fault tolerance easy; the price is that **a typo will not tell you**.

## Full example: progress bar plus log

```php
$app->on('start_btn', 'click', function () use ($app, &$state) {
    for ($i = 1; $i <= 100; $i++) {
        // progress: a hot path, go through patch
        $app->patch([['op' => 'set', 'id' => 'progress_bar', 'props' => ['value' => $i]]]);

        // log: write back into state (it must persist)
        if ($i % 10 === 0) {
            $state['logLines'][] = [(string) $i . '%', 'working'];
        }

        $app->runFrames(1);   // give the UI a chance to refresh
    }

    $app->patch([['op' => 'set', 'id' => 'hint', 'props' => ['text' => 'Done']]]);
});
```

::: tip Pump frames inside a long loop
The UI does not refresh by itself while a handler runs — the `runFrames(1)` above is what makes the progress bar move. Without it, the UI jumps to 100% all at once after the loop ends.
:::
