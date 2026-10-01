// TypePHP\Qt — 桥接主实现。
//
// 应用/窗口生命周期、事件循环、渲染遍历（diff）、窗口装饰、对话框、
// 定时器、托盘、剪贴板，以及所有 php_qt_* 包装符号。
//
// 渲染策略：按节点 id 做差异更新。
//   * 新 id → 创建控件
//   * 已有 id → 只刷签名变化的属性（propSigs_ 记上次签名）
//   * 消失的 id → 销毁控件
// 因此输入框光标、表格选中、滚动位置在重渲染后保持。

#include "qt_common.h"

#include <QAction>
#include <QCloseEvent>
#include <QDesktopServices>
#include <QGuiApplication>
#include <QKeySequence>
#include <QScreen>
#include <QSizePolicy>
#include <QSpacerItem>
#include <QUrl>

#include <functional>

// ────────────────────────────── 小工具 ──────────────────────────────

namespace {

QString cleanPath(const QString &path) { return QDir::fromNativeSeparators(path); }

/** 判断 widget 是否是 root 的后代（含自身）。 */
bool isDescendantOf(QWidget *widget, QWidget *root) {
    if (!widget || !root) return false;
    for (QWidget *w = widget; w != nullptr; w = w->parentWidget()) {
        if (w == root) return true;
    }
    return false;
}

/** 从 options 数组里取字符串，带默认值。 */
QString optString(const Array &options, const char *key, const QString &fallback = {}) {
    const Variant v = options.get(key);
    return v.isUndef() || v.isNull() ? fallback : toQString(v);
}

int optInt(const Array &options, const char *key, int fallback = 0) {
    const Variant v = options.get(key);
    return v.isUndef() || v.isNull() ? fallback : static_cast<int>(v.toInt());
}

bool optBool(const Array &options, const char *key, bool fallback = false) {
    const Variant v = options.get(key);
    return v.isUndef() || v.isNull() ? fallback : v.toBool();
}

/** 把 Variant 数组转成 QStringList。 */
QStringList variantStringList(const Variant &value) {
    QStringList out;
    if (!value.isArray()) return out;
    const Array array = value.toArray();
    for (size_t i = 0; i < array.count(); ++i) out << toQString(array.get(i));
    return out;
}

/** 把 Variant 数组转成 QList<int>。 */
QList<int> variantIntList(const Variant &value) {
    QList<int> out;
    if (!value.isArray()) return out;
    const Array array = value.toArray();
    for (size_t i = 0; i < array.count(); ++i) out << static_cast<int>(array.get(i).toInt());
    return out;
}

/** 从节点数组里解析菜单项。 */
struct MenuItem {
    QString id;
    QString text;
    QString shortcut;
    bool checked = false;
    bool enabled = true;
    bool separator = false;
    bool isMenu = false;
    QList<MenuItem> children;
};

QList<MenuItem> parseMenuItems(const Variant &items) {
    QList<MenuItem> out;
    if (!items.isArray()) return out;
    const Array array = items.toArray();
    for (size_t i = 0; i < array.count(); ++i) {
        const Variant item = array.get(i);
        if (!item.isArray()) continue;
        const Array a = item.toArray();
        MenuItem entry;
        entry.id = toQString(a.get("id"));
        entry.text = toQString(a.get("text"));
        entry.shortcut = toQString(a.get("shortcut"));
        entry.checked = a.get("checked").toBool();
        entry.enabled = a.get("enabled").isUndef() || a.get("enabled").isNull() ? true : a.get("enabled").toBool();
        const QString type = toQString(a.get("type"));
        entry.separator = (type == QLatin1String("sep") || type == QLatin1String("separator"));
        entry.isMenu = (type == QLatin1String("menu") || type == QLatin1String("submenu"));
        entry.children = parseMenuItems(a.get("children"));
        out << entry;
    }
    return out;
}

QAction *buildMenu(QMenu *menu, const QList<MenuItem> &items, QtWindowBox *box) {
    QAction *first = nullptr;
    for (const MenuItem &item : items) {
        if (item.separator) {
            menu->addSeparator();
            continue;
        }
        if (item.isMenu) {
            auto *sub = menu->addMenu(item.text);
            buildMenu(sub, item.children, box);
            if (!first) first = sub->menuAction();
            continue;
        }
        auto *action = menu->addAction(item.text);
        if (!item.shortcut.isEmpty()) action->setShortcut(QKeySequence(item.shortcut));
        action->setCheckable(true);
        action->setChecked(item.checked);
        action->setEnabled(item.enabled);
        const QString itemId = item.id.isEmpty() ? QStringLiteral("_%1").arg(reinterpret_cast<quintptr>(action)) : item.id;
        QObject::connect(action, &QAction::triggered, box->window(), [box, itemId](bool checked) {
            Array payload;
            payload.set("checked", Variant(checked));
            box->enqueue(QStringLiteral("menu"), itemId, QString(), payload);
        });
        if (!first) first = action;
    }
    return first;
}

}  // namespace

// ────────────────────────────── QtWindowBox 生命周期 ──────────────────────────────

QtWindowBox::QtWindowBox(const QString &title, const Array &options) {
    if (!qt_application) {
        qt_application = new QApplication(qt_argc, qt_argv);
        qt_application->setStyle(QStyleFactory::create("Fusion"));
        QFont font = qt_application->font();
#ifdef Q_OS_WIN
        font.setFamily("Microsoft YaHei UI");
#endif
        font.setPointSizeF(10);
        qt_application->setFont(font);
    }

    window_ = new QMainWindow();
    window_->setWindowTitle(title);
    window_->resize(optInt(options, "width", 960), optInt(options, "height", 620));

    const int minW = optInt(options, "min_width");
    const int minH = optInt(options, "min_height");
    if (minW > 0 || minH > 0) window_->setMinimumSize(minW > 0 ? minW : 0, minH > 0 ? minH : 0);

    if (!optBool(options, "resizable", true)) {
        window_->setFixedSize(window_->size());
    }
    if (optBool(options, "frameless")) window_->setWindowFlag(Qt::FramelessWindowHint);
    if (optBool(options, "maximized")) window_->setWindowState(Qt::WindowMaximized);

    central_ = new QWidget();
    rootLayout_ = new QVBoxLayout(central_);
    rootLayout_->setContentsMargins(12, 12, 12, 12);
    rootLayout_->setSpacing(8);
    window_->setCentralWidget(central_);

    const QString iconPath = optString(options, "icon");
    if (!iconPath.isEmpty()) window_->setWindowIcon(QIcon(iconPath));

    const QString stylesheet = optString(options, "stylesheet");
    if (!stylesheet.isEmpty()) window_->setStyleSheet(stylesheet);

    if (optBool(options, "centered")) {
        const QRect screen = QGuiApplication::primaryScreen()->availableGeometry();
        window_->move((screen.width() - window_->width()) / 2, (screen.height() - window_->height()) / 2);
    }

    QObject::connect(window_, &QMainWindow::destroyed, window_, [this]() { window_ = nullptr; });
    QObject::connect(window_, &QObject::destroyed, window_, [this]() { central_ = nullptr; rootLayout_ = nullptr; });

    window_->show();
}

QtWindowBox::~QtWindowBox() { cleanup(); }

bool QtWindowBox::isOpen() const { return window_ != nullptr && window_->isVisible(); }

void QtWindowBox::processEvents() {
    if (!qt_application) return;
    QEventLoop loop;
    QTimer::singleShot(16, &loop, &QEventLoop::quit);
    loop.exec(QEventLoop::AllEvents);
}

Array QtWindowBox::pollEvent() {
    if (events_.empty()) return {};
    Array event = events_.front();
    events_.pop_front();
    return event;
}

void QtWindowBox::close() {
    if (window_) window_->close();
}

void QtWindowBox::cleanup() {
    if (tray_) {
        tray_->hide();
        delete tray_;
        tray_ = nullptr;
    }
    qDeleteAll(timers_);
    timers_.clear();
    qDeleteAll(statusWidgets_);
    statusWidgets_.clear();
    if (window_) {
        window_->close();
        delete window_;
        window_ = nullptr;
    }
    central_ = nullptr;
    rootLayout_ = nullptr;
    widgets_.clear();
    types_.clear();
    propSigs_.clear();
    slots_.clear();
    nodeTitles_.clear();
    nodeLabels_.clear();
    seen_.clear();
    events_.clear();
}

bool QtWindowBox::snapshot(const QString &path) {
    if (!window_) return false;
    qt_application->processEvents();
    return window_->grab().save(path, "PNG");
}

// ────────────────────────────── 渲染遍历 ──────────────────────────────

void QtWindowBox::render(const Array &tree) {
    seen_.clear();
    QList<ChildSlot> siblings;
    buildNode(Variant(tree), central_, QStringLiteral("window"), siblings, QStringLiteral("0"));
    syncChildren(central_, QStringLiteral("window"), siblings);
    removeStale();
}

QWidget *QtWindowBox::buildNode(const Variant &node, QWidget *parentWidget, const QString &parentType,
                                QList<ChildSlot> &siblings, const QString &path) {
    if (!node.isArray()) return nullptr;
    const Array spec = node.toArray();
    const QString type = toQString(spec.get("type"));
    if (type.isEmpty()) return nullptr;

    // 没有显式 id 的节点用「结构路径」当 id。
    // 必须稳定 —— 用递增计数器会导致每帧生成新 id，进而每帧重建控件、
    // 删旧控件时留下悬垂指针而崩溃。
    QString id = toQString(spec.get("id"));
    if (id.isEmpty()) id = QStringLiteral("_p") + path;
    seen_.insert(id);

    QWidget *widget = ensureWidget(node, type, id);
    if (!widget) {
        // spacer：不落控件，只占位
        if (type == QLatin1String("spacer")) {
            ChildSlot slot;
            slot.widget = nullptr;
            slot.spacerSize = qtPropInt(spec, "size", 0);
            slot.spacerOrient = Qt::Horizontal;
            siblings << slot;
            return nullptr;
        }
        return nullptr;
    }

    applyNodeProps(widget, type, id, spec);

    // 记录 title/label 供容器使用
    if (qtHasProp(spec, "title")) nodeTitles_.insert(widget, toQString(spec.get("title")));
    if (qtHasProp(spec, "label")) nodeLabels_.insert(widget, toQString(spec.get("label")));

    // 递归子节点
    const Variant children = spec.get("children");
    if (children.isArray() && qtIsContainer(type)) {
        const Array childArray = children.toArray();
        QWidget *childParent = widget;
        QString childParentType = type;

        if (auto *scroll = qobject_cast<QScrollArea *>(widget)) {
            childParent = scroll->widget();
            childParentType = QStringLiteral("vbox");
        }

        QList<ChildSlot> childSlots;
        for (size_t i = 0; i < childArray.count(); ++i) {
            const QString childPath = path + QLatin1Char('.') + QString::number(i);
            buildNode(childArray.get(i), childParent, childParentType, childSlots, childPath);
        }
        syncChildren(childParent, childParentType, childSlots);
    }

    // 重型重建
    if (type == QLatin1String("table")) {
        if (auto *table = qobject_cast<QTableWidget *>(widget)) qtRebuildTable(this, table, spec);
    } else if (type == QLatin1String("tree")) {
        if (auto *tree = qobject_cast<QTreeWidget *>(widget)) qtRebuildTree(this, tree, spec);
    }

    // 把自己登记到父容器的 siblings
    ChildSlot slot;
    slot.widget = widget;
    slot.grow = qtPropInt(spec, "grow", 0);
    slot.row = qtPropInt(spec, "row", 0);
    slot.col = qtPropInt(spec, "col", 0);
    slot.rowSpan = qtPropInt(spec, "row_span", 1);
    slot.colSpan = qtPropInt(spec, "col_span", 1);
    siblings << slot;

    return widget;
}

QWidget *QtWindowBox::ensureWidget(const Variant &node, const QString &type, const QString &id) {
    QWidget *existing = widgets_.value(id);
    if (existing) {
        // 类型变了 → 销毁重建
        if (types_.value(id) != type) {
            forgetSubtree(existing);
            widgets_.remove(id);
            types_.remove(id);
            propSigs_.remove(id);
        } else {
            return existing;
        }
    }

    QWidget *widget = qtCreateWidget(this, type, node);
    if (!widget) return nullptr;

    widgets_.insert(id, widget);
    types_.insert(id, type);
    return widget;
}

void QtWindowBox::applyNodeProps(QWidget *widget, const QString &type, const QString &id, const Variant &node) {
    if (!widget) return;
    const Array spec = node.toArray();
    QHash<QString, QString> &sigs = propSigs_[id];

    for (auto it = spec.begin(); it != spec.end(); ++it) {
        const QString key = toQString(it.key());
        if (key == QLatin1String("id") || key == QLatin1String("type") || key == QLatin1String("children")
            || key == QLatin1String("rows") || key == QLatin1String("row_ids") || key == QLatin1String("nodes")
            || key == QLatin1String("columns") || key == QLatin1String("headers")) {
            continue;  // 结构字段由 buildNode/重建逻辑处理
        }
        const Variant value = it.value();
        const QString sig = qtSignature(value);
        if (sigs.value(key) == sig) continue;  // 没变，跳过
        sigs.insert(key, sig);
        qtApplyProp(this, widget, type, key, value);
    }
}

void QtWindowBox::syncChildren(QWidget *container, const QString &type, const QList<ChildSlot> &desired) {
    if (!container) return;
    const QList<ChildSlot> &previous = slots_[container];
    if (previous == desired) return;  // 没变，不动布局

    // 清空布局
    if (auto *layout = qtContainerLayout(container)) {
        QLayoutItem *item;
        while ((item = layout->takeAt(0)) != nullptr) {
            if (item->widget()) item->widget()->setParent(nullptr);
            delete item;
        }
    } else if (auto *tabs = qobject_cast<QTabWidget *>(container)) {
        while (tabs->count() > 0) tabs->removeTab(0);
    } else if (auto *stack = qobject_cast<QStackedWidget *>(container)) {
        while (stack->count() > 0) stack->removeWidget(stack->widget(0));
    } else if (auto *splitter = qobject_cast<QSplitter *>(container)) {
        QWidget *w;
        while ((w = splitter->widget(0)) != nullptr) w->setParent(nullptr);
    }

    // 重新添加
    for (const ChildSlot &slot : desired) {
        if (!slot.widget) {
            if (slot.spacerSize > 0) {
                if (auto *layout = qtContainerLayout(container)) {
                    layout->addItem(new QSpacerItem(slot.spacerSize, 0, QSizePolicy::Fixed, QSizePolicy::Minimum));
                }
            }
            continue;
        }
        QWidget *child = slot.widget;

        if (auto *tabs = qobject_cast<QTabWidget *>(container)) {
            const QString title = nodeTitles_.value(child, QStringLiteral("页"));
            tabs->addTab(child, title);
        } else if (auto *stack = qobject_cast<QStackedWidget *>(container)) {
            stack->addWidget(child);
        } else if (auto *splitter = qobject_cast<QSplitter *>(container)) {
            splitter->addWidget(child);
        } else if (auto *grid = qobject_cast<QGridLayout *>(qtContainerLayout(container))) {
            grid->addWidget(child, slot.row, slot.col, slot.rowSpan, slot.colSpan);
        } else if (auto *form = qobject_cast<QFormLayout *>(qtContainerLayout(container))) {
            const QString label = nodeLabels_.value(child);
            if (!label.isEmpty()) form->addRow(label, child);
            else form->addRow(child);
        } else if (auto *layout = qtContainerLayout(container)) {
            if (auto *boxLayout = qobject_cast<QBoxLayout *>(layout)) {
                boxLayout->addWidget(child, slot.grow);
            } else {
                layout->addWidget(child);
            }
        }
    }

    slots_[container] = desired;
}

void QtWindowBox::forgetSubtree(QWidget *root) {
    if (!root) return;
    // 从 widgets_/types_/propSigs_ 里移除以该控件为根的所有 id
    QList<QString> toRemove;
    for (auto it = widgets_.begin(); it != widgets_.end(); ++it) {
        if (it.value() == root || isDescendantOf(it.value(), root)) toRemove << it.key();
    }
    for (const QString &id : toRemove) {
        widgets_.remove(id);
        types_.remove(id);
        propSigs_.remove(id);
    }
    slots_.remove(root);
    nodeTitles_.remove(root);
    nodeLabels_.remove(root);
}

void QtWindowBox::removeStale() {
    QList<QString> toRemove;
    for (auto it = widgets_.begin(); it != widgets_.end(); ++it) {
        if (!seen_.contains(it.key())) toRemove << it.key();
    }
    for (const QString &id : toRemove) {
        QWidget *widget = widgets_.take(id);
        types_.remove(id);
        propSigs_.remove(id);
        slots_.remove(widget);
        nodeTitles_.remove(widget);
        nodeLabels_.remove(widget);
        if (!widget) continue;

        // 先从父布局/父容器里摘除，再 delete。
        // 直接 delete 会让布局留下悬垂 item，下次渲染访问就崩。
        if (QLayout *parentLayout = widget->parentWidget() ? widget->parentWidget()->layout() : nullptr) {
            parentLayout->removeWidget(widget);
        }
        if (auto *tabs = qobject_cast<QTabWidget *>(widget->parentWidget())) {
            const int index = tabs->indexOf(widget);
            if (index >= 0) tabs->removeTab(index);
        }
        if (auto *stack = qobject_cast<QStackedWidget *>(widget->parentWidget())) {
            stack->removeWidget(widget);
        }
        widget->setParent(nullptr);
        widget->deleteLater();
    }
}

// ────────────────────────────── patch ──────────────────────────────

void QtWindowBox::patch(const Array &ops) {
    for (size_t i = 0; i < ops.count(); ++i) {
        const Variant op = ops.get(i);
        if (!op.isArray()) continue;
        const Array spec = op.toArray();
        const QString kind = toQString(spec.get("op"));
        const QString id = toQString(spec.get("id"));
        QWidget *widget = widgets_.value(id);
        if (!widget) continue;

        if (kind == QLatin1String("set")) {
            const Variant props = spec.get("props");
            if (!props.isArray()) continue;
            const Array propArray = props.toArray();
            const QString type = types_.value(id);
            QHash<QString, QString> &sigs = propSigs_[id];
            for (auto it = propArray.begin(); it != propArray.end(); ++it) {
                const QString key = toQString(it.key());
                const Variant value = it.value();
                const QString sig = qtSignature(value);
                if (sigs.value(key) == sig) continue;
                sigs.insert(key, sig);
                qtApplyProp(this, widget, type, key, value);
            }
            // 重型重建
            if (type == QLatin1String("table")) {
                if (auto *table = qobject_cast<QTableWidget *>(widget)) qtRebuildTable(this, table, spec);
            } else if (type == QLatin1String("tree")) {
                if (auto *tree = qobject_cast<QTreeWidget *>(widget)) qtRebuildTree(this, tree, spec);
            }
        } else if (kind == QLatin1String("call")) {
            const QString method = toQString(spec.get("method"));
            const Variant args = spec.get("args");
            // 预留：appendRows / clear / setText / setValue 等
            Q_UNUSED(method);
            Q_UNUSED(args);
        }
    }
}

// ────────────────────────────── 取值 ──────────────────────────────

Variant QtWindowBox::widgetValue(const QString &id) {
    QWidget *widget = widgets_.value(id);
    if (!widget) return {};
    const QString type = types_.value(id);
    return qtWidgetValue(widget, type);
}

// ────────────────────────────── 定时器 ──────────────────────────────

void QtWindowBox::setTimer(const QString &id, int intervalMs) {
    if (timers_.contains(id)) {
        delete timers_.take(id);
    }
    if (intervalMs <= 0) return;
    auto *timer = new QTimer(window_);
    timer->setInterval(intervalMs);
    QObject::connect(timer, &QTimer::timeout, window_, [this, id]() { enqueue(QStringLiteral("timer"), id); });
    timer->start();
    timers_.insert(id, timer);
}

// ────────────────────────────── 窗口装饰 ──────────────────────────────

void QtWindowBox::setTitle(const QString &title) {
    if (window_) window_->setWindowTitle(title);
}

void QtWindowBox::setMenu(const Array &items) {
    if (!window_) return;
    QList<MenuItem> parsed = parseMenuItems(Variant(items));
    QMenuBar *bar = window_->menuBar();
    bar->clear();
    for (const MenuItem &item : parsed) {
        if (item.isMenu) {
            auto *menu = bar->addMenu(item.text);
            buildMenu(menu, item.children, this);
        } else if (item.separator) {
            bar->addSeparator();
        } else {
            auto *action = bar->addAction(item.text);
            if (!item.shortcut.isEmpty()) action->setShortcut(QKeySequence(item.shortcut));
            action->setCheckable(true);
            action->setChecked(item.checked);
            action->setEnabled(item.enabled);
            const QString itemId = item.id.isEmpty() ? QStringLiteral("_%1").arg(reinterpret_cast<quintptr>(action)) : item.id;
            QObject::connect(action, &QAction::triggered, window_, [this, itemId](bool checked) {
                Array payload;
                payload.set("checked", Variant(checked));
                enqueue(QStringLiteral("menu"), itemId, QString(), payload);
            });
        }
    }
}

void QtWindowBox::setStatus(const Array &segments) {
    if (!window_) return;
    QStatusBar *bar = window_->statusBar();
    if (segments.count() == 0) {
        bar->hide();
        return;
    }
    bar->show();
    qDeleteAll(statusWidgets_);
    statusWidgets_.clear();
    for (size_t i = 0; i < segments.count(); ++i) {
        auto *label = new QLabel(toQString(segments.get(i)));
        label->setObjectName(QStringLiteral("status_%1").arg(i));
        bar->addWidget(label, 1);
        statusWidgets_ << label;
    }
}

void QtWindowBox::setTray(const Array &spec) {
    const QString iconPath = optString(spec, "icon");
    const QString tooltip = optString(spec, "tooltip");
    const bool visible = optBool(spec, "visible", true);

    if (!tray_) {
        tray_ = new QSystemTrayIcon(window_);
        QObject::connect(tray_, &QSystemTrayIcon::activated, window_, [this](QSystemTrayIcon::ActivationReason reason) {
            if (reason == QSystemTrayIcon::Trigger) enqueue(QStringLiteral("tray"));
        });
    }
    if (!iconPath.isEmpty()) tray_->setIcon(QIcon(iconPath));
    if (!tooltip.isEmpty()) tray_->setToolTip(tooltip);
    if (visible) tray_->show();
    else tray_->hide();
}

void QtWindowBox::notify(const QString &title, const QString &message) {
    if (tray_ && tray_->isVisible()) {
        tray_->showMessage(title, message, QSystemTrayIcon::Information, 3000);
    } else if (window_) {
        QMessageBox::information(window_, title, message);
    }
}

// ────────────────────────────── 对话框 ──────────────────────────────

QString QtWindowBox::messageBox(const Array &spec) {
    if (!window_) return QStringLiteral("cancel");
    const QString type = optString(spec, "type", QStringLiteral("info"));
    const QString title = optString(spec, "title", QStringLiteral("提示"));
    const QString text = optString(spec, "text");
    const QString buttons = optString(spec, "buttons", QStringLiteral("ok"));

    QMessageBox::Icon icon = QMessageBox::Information;
    if (type == QLatin1String("warning")) icon = QMessageBox::Warning;
    else if (type == QLatin1String("error")) icon = QMessageBox::Critical;
    else if (type == QLatin1String("question")) icon = QMessageBox::Question;

    QMessageBox box(icon, title, text, QMessageBox::NoButton, window_);

    QMessageBox::StandardButtons standard = QMessageBox::NoButton;
    if (buttons == QLatin1String("ok")) standard = QMessageBox::Ok;
    else if (buttons == QLatin1String("cancel")) standard = QMessageBox::Cancel;
    else if (buttons == QLatin1String("yesno")) standard = QMessageBox::Yes | QMessageBox::No;
    else if (buttons == QLatin1String("yesnocancel")) standard = QMessageBox::Yes | QMessageBox::No | QMessageBox::Cancel;
    box.setStandardButtons(standard);

    const QString defaultBtn = optString(spec, "default");
    if (defaultBtn == QLatin1String("ok")) box.setDefaultButton(QMessageBox::Ok);
    else if (defaultBtn == QLatin1String("cancel")) box.setDefaultButton(QMessageBox::Cancel);
    else if (defaultBtn == QLatin1String("yes")) box.setDefaultButton(QMessageBox::Yes);
    else if (defaultBtn == QLatin1String("no")) box.setDefaultButton(QMessageBox::No);

    const int result = box.exec();
    switch (result) {
    case QMessageBox::Ok: return QStringLiteral("ok");
    case QMessageBox::Cancel: return QStringLiteral("cancel");
    case QMessageBox::Yes: return QStringLiteral("yes");
    case QMessageBox::No: return QStringLiteral("no");
    default: return QStringLiteral("cancel");
    }
}

Array QtWindowBox::fileDialog(const Array &spec) {
    Array out;
    if (!window_) return out;
    const QString mode = optString(spec, "mode", QStringLiteral("open"));
    const QString title = optString(spec, "title");
    const QString filter = optString(spec, "filter");
    const QString dir = optString(spec, "dir");
    const QString filename = optString(spec, "filename");

    if (mode == QLatin1String("save")) {
        const QString path = QFileDialog::getSaveFileName(window_, title, dir + "/" + filename, filter);
        if (!path.isEmpty()) out.set(0, toPhpString(path));
    } else if (mode == QLatin1String("openmany")) {
        const QStringList paths = QFileDialog::getOpenFileNames(window_, title, dir, filter);
        for (int i = 0; i < paths.size(); ++i) out.set(static_cast<size_t>(i), toPhpString(paths.at(i)));
    } else {
        const QString path = QFileDialog::getOpenFileName(window_, title, dir, filter);
        if (!path.isEmpty()) out.set(0, toPhpString(path));
    }
    return out;
}

QString QtWindowBox::directoryDialog(const Array &spec) {
    if (!window_) return {};
    const QString title = optString(spec, "title");
    const QString dir = optString(spec, "dir");
    return QFileDialog::getExistingDirectory(window_, title, dir);
}

void QtWindowBox::showError(const QString &message) {
    if (window_) QMessageBox::warning(window_, QStringLiteral("错误"), message);
}

// ────────────────────────────── 事件入队 ──────────────────────────────

void QtWindowBox::enqueue(const QString &type, const QString &id, const QString &value, const Array &payload) {
    Array event;
    event.set("type", toPhpString(type));
    if (!id.isEmpty()) event.set("id", toPhpString(id));
    if (!value.isEmpty()) event.set("value", toPhpString(value));
    if (payload.count() > 0) event.set("payload", payload);
    events_.push_back(event);
}

// ────────────────────────────── php_qt_* 包装 ──────────────────────────────

static QtWindowBox *boxFrom(Variant box) { return box.toBox<QtWindowBox>(); }

String php_qt_bridge_version() { return String(QTBRIDGE_VERSION); }

Variant php_qt_app_create(Array options) {
    if (!qt_application) {
        qt_application = new QApplication(qt_argc, qt_argv);
        qt_application->setStyle(QStyleFactory::create("Fusion"));
        QFont font = qt_application->font();
#ifdef Q_OS_WIN
        font.setFamily("Microsoft YaHei UI");
#endif
        font.setPointSizeF(optInt(options, "font_size", 10));
        qt_application->setFont(font);
        const QString stylesheet = optString(options, "stylesheet");
        if (!stylesheet.isEmpty()) qt_application->setStyleSheet(stylesheet);
        const QString name = optString(options, "name");
        if (!name.isEmpty()) qt_application->setApplicationName(name);
        const QString version = optString(options, "version");
        if (!version.isEmpty()) qt_application->setApplicationVersion(version);
        const QString org = optString(options, "organization");
        if (!org.isEmpty()) qt_application->setOrganizationName(org);
        const QString iconPath = optString(options, "icon");
        if (!iconPath.isEmpty()) qt_application->setWindowIcon(QIcon(iconPath));
        if (optBool(options, "quit_on_last_window_closed", true)) {
            qt_application->setQuitOnLastWindowClosed(true);
        } else {
            qt_application->setQuitOnLastWindowClosed(false);
        }
    }
    return {};
}

Variant php_qt_window_create(String title, Array options) {
    if (!qt_application) {
        qt_application = new QApplication(qt_argc, qt_argv);
        qt_application->setStyle(QStyleFactory::create("Fusion"));
    }
    auto *box = new QtWindowBox(toQString(title), options);
    return {box};
}

void php_qt_window_render(Variant box, Array tree) { boxFrom(box)->render(tree); }
void php_qt_window_patch(Variant box, Array ops) { boxFrom(box)->patch(ops); }
Bool php_qt_window_is_open(Variant box) { return boxFrom(box)->isOpen(); }
void php_qt_window_process_events(Variant box) { boxFrom(box)->processEvents(); }
Array php_qt_window_poll_event(Variant box) { return boxFrom(box)->pollEvent(); }
void php_qt_window_close(Variant box) { boxFrom(box)->close(); }
void php_qt_window_destroy(Variant box) { boxFrom(box)->cleanup(); }
Bool php_qt_window_snapshot(Variant box, String path) { return boxFrom(box)->snapshot(toQString(path)); }
Variant php_qt_window_widget_value(Variant box, String id) { return boxFrom(box)->widgetValue(toQString(id)); }

void php_qt_window_set_title(Variant box, String title) { boxFrom(box)->setTitle(toQString(title)); }
void php_qt_window_set_menu(Variant box, Array items) { boxFrom(box)->setMenu(items); }
void php_qt_window_set_status(Variant box, Array segments) { boxFrom(box)->setStatus(segments); }
void php_qt_window_resize(Variant box, Int width, Int height) {
    if (boxFrom(box)->window()) boxFrom(box)->window()->resize(static_cast<int>(width), static_cast<int>(height));
}

String php_qt_window_message(Variant box, Array spec) { return toPhpString(boxFrom(box)->messageBox(spec)); }
Array php_qt_window_file_dialog(Variant box, Array spec) { return boxFrom(box)->fileDialog(spec); }
String php_qt_window_directory_dialog(Variant box, Array spec) {
    return toPhpString(boxFrom(box)->directoryDialog(spec));
}

void php_qt_window_notify(Variant box, String title, String message) {
    boxFrom(box)->notify(toQString(title), toQString(message));
}

void php_qt_window_set_tray(Variant box, Array spec) { boxFrom(box)->setTray(spec); }
void php_qt_window_set_timer(Variant box, String id, Int intervalMs) {
    boxFrom(box)->setTimer(toQString(id), static_cast<int>(intervalMs));
}

String php_qt_clipboard_read() {
    if (!qt_application) return {};
    const QString text = QGuiApplication::clipboard()->text();
    return toPhpString(text);
}

void php_qt_clipboard_write(String text) {
    if (!qt_application) return;
    QGuiApplication::clipboard()->setText(toQString(text));
}
