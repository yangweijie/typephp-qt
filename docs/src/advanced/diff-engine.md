# Diff Engine

This page explains **why widget state survives a re-render** — the core mechanism of the declarative approach.

## The problem

`view()` runs once per frame (about 60 times a second), each time returning a brand-new widget tree.

What happens if every frame "deletes all the old widgets and rebuilds from the new tree"?

- The caret in the text you are typing **jumps back to the start**
- The table's selected row **disappears**
- The scroll position **snaps back to the top**
- Rebuilding 200 widgets 60 times a second is slow

So it has to be a **difference update**.

## The approach: diff by `id`

The C++ side keeps an `id → widget` map. After receiving a new tree each frame:

```
id present in both the new tree and the old map  → update only the changed properties (the widget itself stays)
id present in the new tree but not the old map   → create the widget
id in the old map but not the new tree           → delete the widget (detach from the layout first, then deleteLater)
```

**The widget is never rebuilt, so its internal state — caret, selection, scroll, input-method state — is preserved entirely.**

## Stable ids are the precondition

The diff keys on `id`, so an id must be **stable across frames** — the same logical control must get the same id this frame and the next.

### Explicit ids

The `['id' => 'name_input']` you write is exactly that.

### Automatic ids: structural path

A node without an id gets one derived from its **structural position**: `_p0.1.2` (child 2 of child 1 of root 0).

Because it is derived from **position**, as long as the tree's structure holds, the same node gets the same id every frame.

### Counter-example: an id that changes every frame

::: danger This was a real bug
An early implementation generated ids for id-less nodes with an **auto-incrementing counter**. Result: the id differed every frame → the diff saw "everything old vanished, everything is new" → the whole tree was rebuilt each frame.

The consequence was not just slowness: **rebuilding deleted the old widgets while the layout still held dangling pointers to them** — the second render crashed outright (`0xC0000005`).
:::

**Lesson**: an automatic id must be derived from **structure**, never from execution order.

## Preserving the selection

Table / list / tree selection is a special case: once the user clicks a row, the selection lives in the **widget**, not in your state.

What the diff engine does:

1. Before re-rendering, remember the current selection's **row id / node id**.
2. Rebuild the table data.
3. Find that row by the remembered id and re-select it.

So **inserting a row or swapping the data never misplaces the selection** — provided you pass `row_ids`:

```php
// with row_ids: the selection follows r2
WidgetTree::table(['Name'], $rows, ['id' => 'tbl', 'row_ids' => ['r0', 'r1', 'r2'], 'current' => 'r2']);

// without row_ids: the row id degrades to the index '0', '1', …, and inserting a row moves the selection
WidgetTree::table(['Name'], $rows, ['id' => 'tbl']);
```

That is why the [data controls](/widgets/data.md#why-you-should-always-pass-row-ids) keep insisting on `row_ids`.

## Property-level diff

Inside a single widget, comparison is also **per property**: a value is written back to Qt only when it actually changed.

So writing this in `view()`:

```php
WidgetTree::label('Clicks: ' . $state['clicks'], ['id' => 'count'])
```

constructs a fresh label node every frame, but the diff sees `text` unchanged and does nothing — no Qt repaint.

## Why `patch()` is a "bypass"

`patch()` changes widgets directly, skipping the tree. After it runs, the diff engine's record of "that widget's last property signature" no longer matches reality.

So the framework **invalidates the signature** — the next `render()` re-syncs from the tree.

That explains the core constraint in [Patching](/guide/patching.md):

> Appended rows and cleared content are only valid until that render. To persist, write back into state.

## Cost and benefit

| | Declarative diff | Imperative handles |
|---|---|---|
| Application code | Describe only "what the UI should look like" | Maintain widget lifecycle and incremental sync yourself |
| State preservation | Automatic (via ids) | Handled by hand |
| Per-frame cost | Walk the tree + compare properties | Only what you explicitly change moves |
| Failure mode | Unstable id → rebuild (visible stutter) | Missed sync → UI and data disagree (hard to trace) |

The framework chose the former: **the least application code**, at the cost of one tree walk per frame. In practice that cost is far below the mental overhead of writing imperative sync code — and hot paths can be optimized individually with `patch()`.

## Related

- [Architecture](/guide/architecture.md) — the three layers and the journey of a click
- [Properties](/guide/properties.md) — the full set of diffable properties
- [Patching](/guide/patching.md) — the price of bypassing the diff
