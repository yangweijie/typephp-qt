# Advanced

This section covers how the framework works internally, and the constraints that come with AOT compilation. **If you are just building an app with the package, you can skip it** — come back when you hit a build problem or need to extend the bridge.

| Page | When to read it |
|---|---|
| [AOT Notes](/advanced/aot-notes.md) | **Read before your first compiled run.** These traps only appear in a compiled binary |
| [Hand-written Bridge](/advanced/bridge.md) | To use a Qt widget the package does not expose, or to understand the bridge internals |
| [Diff Engine](/advanced/diff-engine.md) | To understand why widget state survives a re-render |
| [Headless Verification](/advanced/headless.md) | To wire up CI, or to verify without a display |

## In one line

```
PHP describes the UI ──► C++ diffs by id ──► Qt displays
     state-driven          stable ids         display only
```

The full three-layer breakdown is in [Architecture](/guide/architecture.md).
