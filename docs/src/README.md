---
home: true
heroImage: null
heroText: TypePHP\Qt
tagline: Build native desktop apps with TypePHP (AOT) + Qt 6 — describe the UI as a PHP array, keep every decision in PHP
actions:
  - text: Get Started
    link: /guide/quickstart.html
    type: primary
  - text: Widget Catalog
    link: /widgets/
    type: secondary
features:
  - title: Declarative UI
    details: Describe the widget tree as a PHP array; the C++ side diffs it by id. Cursor position, table selection and scroll offset survive every re-render.
  - title: State-driven
    details: Event handlers only change state. The builder registered with view() re-describes the UI each frame, making the widget layer a pure function of state.
  - title: PHP owns the loop
    details: PHP drives the Qt event pump, so all business logic stays in PHP and unit-tests with a plain PHP interpreter — no Qt, no compiler.
  - title: Source-inlined bridge
    details: The bridge C++ compiles as part of your app. No prebuilt binary, so it can never drift from your Qt or PHPX version.
  - title: One-command packaging
    details: qtphp package assembles a self-contained bundle and verifies it — dist/ on Windows, a .app on macOS, an ldd closure on Linux.
  - title: Headless verification
    details: --shot renders a PNG, --selftest fires every event, --difftest covers table/tree diff boundaries. No display needed in CI.
footer: MIT Licensed | Copyright © yangweijie
---
