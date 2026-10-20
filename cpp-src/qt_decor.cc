// TypePHP\Qt — 窗口装饰（标题/菜单/状态栏/托盘/通知）、对话框、
// 窗口集合/几何/内省、剪贴板，以及对应的 php_qt_* 包装符号。

#include "qt_common.h"

#include <QAction>
#include <QCloseEvent>
#include <QDebug>
#include <QDesktopServices>
#include <QGuiApplication>
#include <QKeySequence>
#include <QScreen>
#include <QUrl>

// ────────────────────────────── 小工具 ──────────────────────────────

namespace {

QString cleanPath(const QString &path) { return QDir::fromNativeSeparators(path); }

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

/**
 * 无障碍树的递归。
 *
 * 只把 widgets_ 里认识的控件当节点；Qt 内部包装控件（QSpinBox 的编辑框、
 * QComboBox 的按钮等）不在声明树里，直接穿透到它们的子控件，否则整个
 * 控件树会因为中间层而断掉。
 */
void appendA11yChildren(Array &out, QWidget *parent, int remaining, QWidget *window,
                        const QHash<QWidget *, QString> &reverse, const QHash<QString, QString> &types,
                        const QHash<QWidget *, QString> &titles, const QHash<QWidget *, QString> &labels) {
    if (!parent || remaining <= 0) return;
    const QList<QWidget *> kids = parent->findChildren<QWidget *>(Qt::FindDirectChildrenOnly);
    for (QWidget *child : kids) {
        const QString id = reverse.value(child);
        if (id.isEmpty()) {
            appendA11yChildren(out, child, remaining, window, reverse, types, titles, labels);
            continue;
        }
        const QString type = types.value(id);
        Array node;
        node.set("role", toPhpString(type));
        node.set("id", toPhpString(id));
        const QString text = titles.value(child, labels.value(child));
        node.set("text", toPhpString(text.isEmpty() ? child->accessibleName() : text));
        node.set("value", qtWidgetValue(child, type));
        node.set("visible", Variant(child->isVisible()));
        node.set("enabled", Variant(child->isEnabled()));
        Array geo;
        const QPoint origin = window ? child->mapTo(window, QPoint(0, 0)) : QPoint(0, 0);
        geo.set("x", Variant(static_cast<Int>(origin.x())));
        geo.set("y", Variant(static_cast<Int>(origin.y())));
        geo.set("width", Variant(static_cast<Int>(child->width())));
        geo.set("height", Variant(static_cast<Int>(child->height())));
        node.set("geometry", geo);
        Array children;
        appendA11yChildren(children, child, remaining - 1, window, reverse, types, titles, labels);
        if (children.count() > 0) node.set("children", children);
        out.append(node);
    }
}

}  // namespace

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
            // Qt 会区分 5 种激活方式，全部转给 PHP —— 只转发左击会让
            // 「右击/双击没反应」看起来像框架不支持，其实只是没往外传。
            switch (reason) {
                case QSystemTrayIcon::Trigger:
                    enqueue(QStringLiteral("tray"), QString(), QStringLiteral("left"));
                    break;
                case QSystemTrayIcon::Context:
                    // 右击：绑了托盘菜单就由 Qt 弹菜单（并抑制这个事件），
                    // 没绑菜单时才把它当普通事件交给 PHP。
                    if (!trayMenu_) enqueue(QStringLiteral("tray"), QString(), QStringLiteral("right"));
                    break;
                case QSystemTrayIcon::DoubleClick:
                    enqueue(QStringLiteral("tray"), QString(), QStringLiteral("double"));
                    break;
                case QSystemTrayIcon::MiddleClick:
                    enqueue(QStringLiteral("tray"), QString(), QStringLiteral("middle"));
                    break;
                default:
                    break;
            }
        });
    }

    // 图标解析顺序：显式路径 → 窗口图标 → 系统标准图标。
    //
    // **关键是每一步都要验空**：路径拼错、文件缺失时 QIcon 会得到空图标，
    // 而空图标的托盘项在 Windows 上直接不显示（macOS/Linux 同理）——
    // 于是「托盘没反应」的真实原因往往是「图标没加载上」，且完全静默。
    QIcon icon;
    if (!iconPath.isEmpty()) {
        icon = QIcon(qtResolvePath(iconPath));
        if (icon.isNull()) {
            qWarning("tray icon could not be loaded: %s", qPrintable(iconPath));
        }
    }
    if (icon.isNull() && window_) {
        const QIcon windowIcon = window_->windowIcon();
        icon = windowIcon.isNull() ? window_->style()->standardIcon(QStyle::SP_ComputerIcon) : windowIcon;
    }
    if (!icon.isNull()) tray_->setIcon(icon);

    // 托盘右键菜单：给了 menu 就交给 Qt 托管（右击时 Qt 自己弹，
    // 于是上面 Context 分支不再往 PHP 发事件）。菜单项点击照旧走 "menu" 事件。
    const Variant menuSpec = spec.get("menu");
    if (qtHasProp(spec, "menu")) {
        if (!trayMenu_) trayMenu_ = new QMenu(window_);
        trayMenu_->clear();
        buildMenu(trayMenu_, parseMenuItems(menuSpec), this);
        tray_->setContextMenu(trayMenu_);
    }

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

// ────────────────────────────── 窗口集合 / 几何 / 内省 ──────────────────────────────

void QtWindowBox::focusWindow() {
    if (!window_) return;
    if (window_->isMinimized()) window_->showNormal();
    window_->raise();
    window_->activateWindow();
}

void QtWindowBox::centerWindow() {
    if (!window_) return;
    QScreen *screen = QGuiApplication::screenAt(window_->frameGeometry().center());
    if (!screen) screen = QGuiApplication::primaryScreen();
    if (!screen) return;
    const QRect area = screen->availableGeometry();
    const QSize size = window_->frameGeometry().size();
    window_->move(area.x() + (area.width() - size.width()) / 2, area.y() + (area.height() - size.height()) / 2);
}

void QtWindowBox::setTransientParent(QtWindowBox *parent) {
    if (!window_) return;
    // setParent 会把窗口藏起来，必须再 show()；Qt::Window 保住它的顶层身份。
    window_->setParent(parent ? parent->window() : nullptr, Qt::Window);
    window_->show();
}

Array QtWindowBox::geometryRect() const {
    Array out;
    int x = 0, y = 0, w = 0, h = 0;
    if (window_) {
        const QPoint topLeft = window_->mapToGlobal(QPoint(0, 0));
        x = topLeft.x();
        y = topLeft.y();
        w = window_->width();
        h = window_->height();
    }
    out.set("x", Variant(static_cast<Int>(x)));
    out.set("y", Variant(static_cast<Int>(y)));
    out.set("width", Variant(static_cast<Int>(w)));
    out.set("height", Variant(static_cast<Int>(h)));
    return out;
}

void QtWindowBox::setGeometryRect(const Array &rect) {
    if (!window_) return;
    const Variant vx = rect.get("x");
    const Variant vy = rect.get("y");
    const Variant vw = rect.get("width");
    const Variant vh = rect.get("height");
    if (!vw.isUndef() || !vh.isUndef()) {
        const int w = vw.isUndef() || vw.isNull() ? window_->width() : static_cast<int>(vw.toInt());
        const int h = vh.isUndef() || vh.isNull() ? window_->height() : static_cast<int>(vh.toInt());
        if (w > 0 && h > 0) window_->resize(w, h);
    }
    if (!vx.isUndef() || !vy.isUndef()) {
        // geometryRect() 给的 x/y 是客户区左上角，move() 要的是窗口边框左上角。
        // 用两者之差做补偿，geometry → set_geometry 才能精确往返。
        const QPoint client = window_->mapToGlobal(QPoint(0, 0));
        const int dx = window_->x() - client.x();
        const int dy = window_->y() - client.y();
        const int x = vx.isUndef() || vx.isNull() ? client.x() : static_cast<int>(vx.toInt());
        const int y = vy.isUndef() || vy.isNull() ? client.y() : static_cast<int>(vy.toInt());
        window_->move(x + dx, y + dy);
    }
}

Array QtWindowBox::findWidget(const QString &id) const {
    QWidget *widget = widgets_.value(id);
    if (!widget || !window_) return {};
    const QString type = types_.value(id);
    Array out;
    out.set("id", toPhpString(id));
    out.set("type", toPhpString(type));
    out.set("value", qtWidgetValue(widget, type));
    out.set("visible", Variant(widget->isVisible()));
    out.set("enabled", Variant(widget->isEnabled()));
    Array geo;
    const QPoint origin = widget->mapTo(window_, QPoint(0, 0));
    geo.set("x", Variant(static_cast<Int>(origin.x())));
    geo.set("y", Variant(static_cast<Int>(origin.y())));
    geo.set("width", Variant(static_cast<Int>(widget->width())));
    geo.set("height", Variant(static_cast<Int>(widget->height())));
    out.set("geometry", geo);
    return out;
}

Array QtWindowBox::a11yTree(int maxDepth) const {
    Array root;
    if (!window_) return root;
    root.set("role", toPhpString(QStringLiteral("window")));
    root.set("id", toPhpString(id_));
    root.set("text", toPhpString(window_->windowTitle()));
    root.set("value", toPhpString(QString()));
    root.set("visible", Variant(window_->isVisible()));
    root.set("enabled", Variant(window_->isEnabled()));
    Array geo;
    geo.set("x", Variant(static_cast<Int>(0)));
    geo.set("y", Variant(static_cast<Int>(0)));
    geo.set("width", Variant(static_cast<Int>(window_->width())));
    geo.set("height", Variant(static_cast<Int>(window_->height())));
    root.set("geometry", geo);
    if (maxDepth > 0 && central_) {
        QHash<QWidget *, QString> reverse;
        for (auto it = widgets_.constBegin(); it != widgets_.constEnd(); ++it) reverse.insert(it.value(), it.key());
        Array children;
        appendA11yChildren(children, central_, maxDepth, window_, reverse, types_, nodeTitles_, nodeLabels_);
        if (children.count() > 0) root.set("children", children);
    }
    return root;
}

void QtWindowBox::showToast(const QString &message, int durationMs) {
    if (!central_) return;
    // 挂在 central_ 上但不进布局：QVBoxLayout 只管 addWidget 进去的项，
    // 游离子控件不受布局摆布，正好当浮层。
    auto *label = new QLabel(message, central_);
    label->setWordWrap(true);
    label->setAlignment(Qt::AlignCenter);
    label->setAttribute(Qt::WA_TransparentForMouseEvents);
    label->setStyleSheet(QStringLiteral("background-color: rgba(32,32,32,220); color: #ffffff;"
                                        "padding: 8px 16px; border-radius: 6px; font-size: 13px;"));
    const int maxWidth = qMax(160, central_->width() - 48);
    if (label->sizeHint().width() > maxWidth) label->setFixedWidth(maxWidth);
    label->adjustSize();
    label->move(qMax(0, (central_->width() - label->width()) / 2),
                qMax(0, central_->height() - label->height() - 32));
    label->show();
    label->raise();
    // 以 label 为 context：窗口先于计时器销毁时，定时器随之一并取消。
    QTimer::singleShot(qMax(200, durationMs), label, &QObject::deleteLater);
}

// ────────────────────────────── php_qt_* 包装 ──────────────────────────────

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

String php_qt_clipboard_read() {
    if (!qt_application) return {};
    const QString text = QGuiApplication::clipboard()->text();
    return toPhpString(text);
}

void php_qt_clipboard_write(String text) {
    if (!qt_application) return;
    QGuiApplication::clipboard()->setText(toQString(text));
}

// ────────────────────────── 窗口集合 / 几何 / 内省包装 ──────────────────────────

Array php_qt_window_list() {
    Array out;
    for (QtWindowBox *box : qt_windows) {
        if (box && box->window()) out.append(toPhpString(box->windowId()));
    }
    return out;
}

String php_qt_window_id(Variant box) { return toPhpString(boxFrom(box)->windowId()); }
void php_qt_window_focus(Variant box) { boxFrom(box)->focusWindow(); }
void php_qt_window_center(Variant box) { boxFrom(box)->centerWindow(); }

void php_qt_window_set_parent(Variant box, Variant parent) {
    QtWindowBox *parentBox = parent.isUndef() || parent.isNull() ? nullptr : parent.toBox<QtWindowBox>();
    boxFrom(box)->setTransientParent(parentBox);
}

Array php_qt_window_geometry(Variant box) { return boxFrom(box)->geometryRect(); }
void php_qt_window_set_geometry(Variant box, Array rect) { boxFrom(box)->setGeometryRect(rect); }
Array php_qt_window_find(Variant box, String id) { return boxFrom(box)->findWidget(toQString(id)); }
Array php_qt_window_a11y_tree(Variant box, Int maxDepth) {
    return boxFrom(box)->a11yTree(static_cast<int>(maxDepth));
}
void php_qt_window_toast(Variant box, String message, Int durationMs) {
    boxFrom(box)->showToast(toQString(message), static_cast<int>(durationMs));
}
