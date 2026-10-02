# Qt Widgets component catalog

Every public class in the QtWidgets module of Qt 6.9.3, grouped by what you would actually use it for. When choosing controls for a TypePHP bridge, pick from here and link `Qt6Widgets.lib` (you already do).

**Reading this list:** the *convenience* widgets (`QListWidget`, `QTableWidget`, `QTreeWidget`) are the ones you want for a TypePHP bridge — they own their data, so you can fill them straight from a PHP array. The *model/view* base classes (`QListView` + `QAbstractItemModel`) give you scalability but require a C++ model object, which is more bridge surface than most apps need.

## Contents

1. [Top-level windows & containers](#1-top-level-windows--containers)
2. [Layouts & layout items](#2-layouts--layout-items)
3. [Buttons](#3-buttons)
4. [Text & display](#4-text--display)
5. [Numeric, slider & spin input](#5-numeric-slider--spin-input)
6. [Date & time](#6-date--time)
7. [Item views — convenience (widget-owned data)](#7-item-views--convenience)
8. [Item views — model/view base & delegates](#8-item-views--modelview-base--delegates)
9. [Menus, toolbars, status & actions](#9-menus-toolbars-status--actions)
10. [Dialogs](#10-dialogs)
11. [Graphics View framework](#11-graphics-view-framework)
12. [Gestures & scrolling](#12-gestures--scrolling)
13. [Styling, painting & system integration](#13-styling-painting--system-integration)
14. [Base & support classes](#14-base--support-classes)

---

## 1. Top-level windows & containers

| Class | Use it for |
|---|---|
| `QWidget` | Base of everything; a blank custom-draw surface. |
| `QMainWindow` | The app window: menu bar + toolbars + dock areas + status bar + one central widget. |
| `QDialog` | Modal or non-modal dialog window. Base for your form dialogs. |
| `QFrame` | Rectangle with a border/shadow — the usual building block for "cards" and panels. |
| `QSplitter` / `QSplitterHandle` | Draggable divider between two areas. |
| `QScrollArea` | Makes any widget scrollable. |
| `QStackedWidget` | Multiple pages, one visible at a time (wizard/tab-without-tabs). |
| `QTabWidget` | Tabbed pages. |
| `QToolBox` | Vertical collapsible sections. |
| `QMdiArea` / `QMdiSubWindow` | MDI document workspace. |
| `QDockWidget` | Dockable/floating panel inside a `QMainWindow`. |
| `QGroupBox` | Titled frame that groups related controls. |
| `QSplashScreen` | Startup splash image. |
| `QRubberBand` | Selection rectangle overlay. |
| `QSizeGrip` | Resize handle for frameless windows. |
| `QFocusFrame` | Draws the focus indicator outside a widget's own area. |

## 2. Layouts & layout items

| Class | Use it for |
|---|---|
| `QLayout` | Abstract base for layouts. |
| `QBoxLayout` | Base for the two below. |
| `QHBoxLayout` / `QVBoxLayout` | Horizontal / vertical stacking — the workhorses. |
| `QGridLayout` | Row/column grid with spanning. |
| `QFormLayout` | Label + field rows; ideal for settings and edit forms. |
| `QStackedLayout` | Layout form of `QStackedWidget`. |
| `QSpacerItem` | Flexible or fixed gap. |
| `QLayoutItem` / `QWidgetItem` / `QWidgetItemV2` | Items a layout manages (rarely touched directly). |
| `QSizePolicy` | How a widget wants to grow/shrink — set this to control resize behaviour. |

## 3. Buttons

| Class | Use it for |
|---|---|
| `QAbstractButton` | Base: clickable, checkable, icon support. |
| `QPushButton` | Standard button. |
| `QToolButton` | Compact icon button for toolbars. |
| `QRadioButton` | One-of-many choice. |
| `QCheckBox` | Independent on/off (or tri-state). |
| `QCommandLinkButton` | Vista-style "big label + description" button. |
| `QButtonGroup` | Groups buttons for exclusive/mutually-exclusive behaviour and id mapping. |
| `QDialogButtonBox` | Standard OK/Cancel/Save row with platform-correct ordering. |

## 4. Text & display

| Class | Use it for |
|---|---|
| `QLabel` | Static text, images, rich text. |
| `QLineEdit` | Single-line input (with placeholder, clear button, validator, echo mode). |
| `QTextEdit` | Rich-text multi-line editor. |
| `QPlainTextEdit` | Fast plain-text editor for large/log text. |
| `QTextBrowser` | Read-only rich text with hyperlinks. |
| `QComboBox` | Drop-down; `addItem(text, userData)` stashes an id — used constantly in bridges. |
| `QFontComboBox` | Combo pre-filled with installed fonts. |
| `QCompleter` | Autocomplete engine for line edits and combos. |
| `QLCDNumber` | Seven-segment style numeric display. |
| `QProgressBar` | Task progress. |

## 5. Numeric, slider & spin input

| Class | Use it for |
|---|---|
| `QAbstractSlider` | Base for slider/scrollbar/dial. |
| `QSlider` | Drag to pick a value in a range. |
| `QScrollBar` | Standalone scrollbar (rarely needed directly). |
| `QDial` | Rotary knob. |
| `QAbstractSpinBox` | Base for the numeric spinners. |
| `QSpinBox` | Integer spinner with optional prefix/suffix/step. |
| `QDoubleSpinBox` | Floating-point spinner with decimals. |

## 6. Date & time

| Class | Use it for |
|---|---|
| `QCalendarWidget` | Full month-view date picker. |
| `QDateEdit` | Date-only field. |
| `QTimeEdit` | Time-only field. |
| `QDateTimeEdit` | Combined date+time field. |
| `QKeySequenceEdit` | Captures a keyboard shortcut from the user. |

## 7. Item views — convenience

These own their data, so you can populate them directly from a PHP array — **prefer these in a TypePHP bridge.**

| Class | Use it for |
|---|---|
| `QListWidget` + `QListWidgetItem` | Simple list. |
| `QTableWidget` + `QTableWidgetItem`, `QTableWidgetSelectionRange` | Grid of cells; the taskboard example uses this. |
| `QTreeWidget` + `QTreeWidgetItem`, `QTreeWidgetItemIterator` | Hierarchical tree. |
| `QColumnView` | Cascading column browser. |
| `QUndoView` | Shows an undo stack. |

## 8. Item views — model/view base & delegates

| Class | Use it for |
|---|---|
| `QAbstractItemView` | Base for all item views. |
| `QListView` / `QTableView` / `QTreeView` | View half of model/view (needs a C++ model). |
| `QHeaderView` | Row/column headers; resize modes live here. |
| `QAbstractItemDelegate` / `QItemDelegate` / `QStyledItemDelegate` | Custom cell painting/editing. |
| `QDataWidgetMapper` | Binds model columns to individual widgets. |
| `QItemEditorFactory` / `QItemEditorCreator` / `QItemEditorCreatorBase` / `QStandardItemEditorCreator` | Default editor widget registry. |

## 9. Menus, toolbars, status & actions

| Class | Use it for |
|---|---|
| `QMenu` | Popup menu. |
| `QMenuBar` | Window menu bar. |
| `QToolBar` | Toolbar strip. |
| `QStatusBar` | Bottom status area of a `QMainWindow`. |
| `QWidgetAction` | Embeds an arbitrary widget inside a menu/toolbar. |
| `QToolTip` | Custom tooltips. |
| `QWhatsThis` | "What's this?" help mode. |

## 10. Dialogs

| Class | Use it for |
|---|---|
| `QMessageBox` | Info / warning / question / critical; returns the clicked button. |
| `QFileDialog` | Open/save file picker (native by default). |
| `QColorDialog` | Colour picker. |
| `QFontDialog` | Font picker. |
| `QInputDialog` | One-shot text/number/item prompt. |
| `QErrorMessage` | Non-blocking error log dialog. |
| `QProgressDialog` | Cancelable progress dialog. |
| `QWizard` + `QWizardPage` | Multi-step wizard. |

## 11. Graphics View framework

For diagrams, canvases, node editors, charts-by-hand. Heavier than plain widgets; only reach for it when you need many movable/scalable items.

| Class | Use it for |
|---|---|
| `QGraphicsScene` / `QGraphicsView` | The scene and the viewport. |
| `QGraphicsItem` / `QAbstractGraphicsShapeItem` / `QGraphicsObject` | Item base classes. |
| `QGraphicsRectItem` / `QGraphicsEllipseItem` / `QGraphicsLineItem` / `QGraphicsPathItem` / `QGraphicsPolygonItem` / `QGraphicsPixmapItem` / `QGraphicsSimpleTextItem` / `QGraphicsTextItem` | Concrete shapes/text/pixmaps. |
| `QGraphicsItemGroup` | Groups items for transforms. |
| `QGraphicsProxyWidget` | Embeds a real widget into a scene. |
| `QGraphicsWidget` / `QGraphicsLayout` / `QGraphicsLayoutItem` | Widget-like scene items. |
| `QGraphicsLinearLayout` / `QGraphicsGridLayout` / `QGraphicsAnchorLayout` / `QGraphicsAnchor` | Layouts inside a scene. |
| `QGraphicsEffect` / `QGraphicsBlurEffect` / `QGraphicsColorizeEffect` / `QGraphicsDropShadowEffect` / `QGraphicsOpacityEffect` | Per-item visual effects (drop shadows etc.). |
| `QGraphicsTransform` / `QGraphicsRotation` / `QGraphicsScale` | Animated transforms. |
| `QGraphicsItemAnimation` | Keyframe animation of an item. |
| `QGraphicsScene*Event` | Mouse/hover/wheel/context-menu/drag-drop/resize/move/help events (9 classes). |

## 12. Gestures & scrolling

| Class | Use it for |
|---|---|
| `QGesture` / `QGestureEvent` / `QGestureRecognizer` | Gesture framework. |
| `QPanGesture` / `QPinchGesture` / `QSwipeGesture` / `QTapGesture` / `QTapAndHoldGesture` | Concrete gestures. |
| `QScroller` / `QScrollerProperties` | Kinetic ("flick") scrolling for touch. |

## 13. Styling, painting & system integration

| Class | Use it for |
|---|---|
| `QStyle` / `QCommonStyle` / `QProxyStyle` | Drawing engine; subclass to tweak native look. |
| `QStyleFactory` | `QStyleFactory::create("Fusion")` — used by the taskboard for a consistent cross-platform look. |
| `QStylePainter` | Painter with style primitives. |
| `QStyleOption*` (≈30 classes: Button, ComboBox, Complex, DockWidget, FocusRect, Frame, GraphicsItem, GroupBox, Header, HeaderV2, MenuItem, ProgressBar, RubberBand, SizeGrip, Slider, SpinBox, Tab, TabBarBase, TabWidgetFrame, TitleBar, ToolBar, ToolBox, ToolButton, ViewItem) | Drawing-state structs passed to `QStyle` methods. |
| `QStyleHintReturn` / `…Mask` / `…Variant` | Extra style query returns. |
| `QStylePlugin` | Loadable style plugin base. |
| `QColormap` | Colour mapping info for a device. |
| `QFileIconProvider` | File-type icons for file views. |
| `QSystemTrayIcon` | Tray icon with menu and notifications. |
| `QRhiWidget` | Widget whose content is rendered through QRhi (modern GPU rendering; Qt 6.7+). |
| `QPlainTextDocumentLayout` | Layout for `QPlainTextEdit` documents. |
| `QAccessibleWidget` | Accessibility bridge for custom widgets. |
| `QTileRules` | Tile/stretch hints for pixmaps in styles. |

## 14. Base & support classes

| Class | Note |
|---|---|
| `QApplication` | **You must create this yourself, once, before any widget.** |
| `QAbstractScrollArea` | Base for scrollable views. |
| `QWidgetData` | Internal widget bookkeeping. |
| `QtWidgets` / `QtWidgetsVersion` | Module umbrella header / version macro — not components. |

---

## Choosing quickly

- **Form / settings screen** → `QFormLayout` + `QLineEdit` / `QSpinBox` / `QComboBox` / `QCheckBox` + `QDialogButtonBox`.
- **Data list / CRUD table** → `QTableWidget` + `QLineEdit` (search) + `QPushButton` (actions) + `QMessageBox` (confirm).
- **Master–detail** → `QListWidget`/`QTableWidget` on the left, `QLabel`-based detail pane on the right.
- **Tool window** → `QMainWindow` + `QToolBar` + `QDockWidget` + `QStatusBar`.
- **Diagram / canvas** → Graphics View framework.
- **Anything exotic** → style it with a global `setStyleSheet` before reaching for custom painting.
