// TypePHP + Qt system-tray bridge template.
// Pairs with assets/templates/tray.stub.skeleton.php (same names, php_ prefix).
// See references/system-tray.md for why each piece is there.
//
// This file is the verified tray example: it builds and runs, and the tray icon
// is created successfully on Windows.

#include "phpx.h"

#include <QAction>
#include <QApplication>
#include <QCloseEvent>
#include <QCoreApplication>
#include <QEventLoop>
#include <QFileInfo>
#include <QFont>
#include <QHBoxLayout>
#include <QIcon>
#include <QLabel>
#include <QMainWindow>
#include <QMenu>
#include <QPainter>
#include <QPixmap>
#include <QPushButton>
#include <QStyleFactory>
#include <QSystemTrayIcon>
#include <QTimer>
#include <QVBoxLayout>
#include <QWidget>

#include <cstdio>
#include <deque>
#include <functional>

using php::Array;
using php::Bool;
using php::Box;
using php::String;
using php::Variant;

namespace {

// ---- 0. Qt application singleton (declared before the Box that uses it) ----

static int qt_argc = 1;
static char qt_program_name[] = "typephp-tray";
static char *qt_argv[] = {qt_program_name, nullptr};
static QApplication *qt_application = nullptr;

// ---- 1. Conversion helpers (always UTF-8) ---------------------------------

QString toQString(const Variant &value) {
    if (value.isNull() || value.isUndef()) return {};
    return QString::fromUtf8(value.toCString());
}

String toPhpString(const QString &value) {
    const QByteArray utf8 = value.toUtf8();
    return String(utf8.constData(), static_cast<size_t>(utf8.size()));
}

// ---- 2. A window that hides to the tray instead of closing ----------------

class TrayWindow final : public QMainWindow {
  public:
    std::function<void()> onHide;

  protected:
    void closeEvent(QCloseEvent *event) override {
        event->ignore();   // do NOT destroy the window; just hide it
        hide();
        if (onHide) onHide();
    }
};

// ---- 3. The Box ------------------------------------------------------------

class TrayAppBox final : public Box {
  public:
    explicit TrayAppBox(const QString &title, const QString &iconPath) {
        alive_ = true;

        window_ = new TrayWindow();
        window_->setWindowTitle(title);
        window_->resize(520, 300);
        window_->onHide = [this]() { enqueue("window_closed"); };

        auto *root = new QWidget();
        auto *layout = new QVBoxLayout(root);
        layout->setContentsMargins(24, 22, 24, 22);
        layout->setSpacing(12);

        auto *heading = new QLabel(title);
        heading->setStyleSheet("font-size: 19px; font-weight: 800;");
        layout->addWidget(heading);

        hint_ = new QLabel(QObject::tr("这个窗口可以关掉——程序会留在系统托盘里。"));
        hint_->setWordWrap(true);
        layout->addWidget(hint_);

        auto *row = new QHBoxLayout();
        auto *hideButton = new QPushButton(QObject::tr("隐藏到托盘"));
        hideButton->setStyleSheet(
            "QPushButton { background: #5965E8; color: #FFFFFF; border: 0;"
            " border-radius: 8px; padding: 9px 16px; font-weight: 700; }");
        auto *quitButton = new QPushButton(QObject::tr("退出程序"));
        quitButton->setStyleSheet(
            "QPushButton { background: transparent; color: #C46463;"
            " border: 1px solid #F0D8D8; border-radius: 8px; padding: 9px 16px; }");
        row->addWidget(hideButton);
        row->addWidget(quitButton);
        row->addStretch();
        layout->addLayout(row);
        layout->addStretch();

        window_->setCentralWidget(root);

        QObject::connect(hideButton, &QPushButton::clicked, window_, [this]() {
            window_->hide();
            enqueue("window_closed");
        });
        QObject::connect(quitButton, &QPushButton::clicked, window_, [this]() {
            enqueue("menu", {}, "quit");
        });

        // ---- the tray icon itself ----
        const QIcon icon = loadTrayIcon(iconPath);
        tray_ = new QSystemTrayIcon(icon);
        tray_->setToolTip(title);
        window_->setWindowIcon(icon);

        menu_ = new QMenu();
        addAction(menu_, QObject::tr("显示主窗口"), "show");
        addAction(menu_, QObject::tr("隐藏主窗口"), "hide");
        menu_->addSeparator();
        addAction(menu_, QObject::tr("发送一条通知"), "notify");
        menu_->addSeparator();
        addAction(menu_, QObject::tr("退出"), "quit");
        tray_->setContextMenu(menu_);

        QObject::connect(tray_, &QSystemTrayIcon::activated,
                         [this](QSystemTrayIcon::ActivationReason reason) {
            QString name = "Unknown";
            if (reason == QSystemTrayIcon::Trigger) name = "Trigger";
            else if (reason == QSystemTrayIcon::DoubleClick) name = "DoubleClick";
            else if (reason == QSystemTrayIcon::Context) name = "Context";
            else if (reason == QSystemTrayIcon::MiddleClick) name = "MiddleClick";
            enqueue("tray_activated", {}, name);
        });

        if (QSystemTrayIcon::isSystemTrayAvailable()) {
            tray_->show();
        } else {
            // No notification area (rare, but happens on stripped-down sessions
            // and on GNOME without an SNI extension). Say so loudly rather than
            // silently having no icon.
            fprintf(stderr, "tray: system tray is NOT available on this session\n");
        }

        window_->show();
    }

    bool isAlive() const { return alive_; }
    bool isWindowVisible() const { return window_ != nullptr && window_->isVisible(); }

    void showWindow(bool show) {
        if (!window_) return;
        if (show) { window_->show(); window_->raise(); window_->activateWindow(); }
        else      { window_->hide(); }
    }

    void setStatus(const QString &text) {
        if (tray_) tray_->setToolTip(text);
        if (hint_) hint_->setText(text);
    }

    void showMessage(const QString &title, const QString &body) {
        if (tray_) tray_->showMessage(title, body, QSystemTrayIcon::Information, 4000);
    }

    void quit() { alive_ = false; }

    void processEvents() {
        if (!qt_application) return;
        QEventLoop loop;
        QTimer::singleShot(16, &loop, &QEventLoop::quit);
        loop.exec(QEventLoop::AllEvents);
    }

    Array pollEvent() {
        if (events_.empty()) return {};
        Array event = events_.front();
        events_.pop_front();
        return event;
    }

    bool snapshot(const QString &path) {
        if (!window_) return false;
        showWindow(true);
        qt_application->processEvents();
        return window_->grab().save(path, "PNG");
    }

    void cleanup() {
        if (tray_) { tray_->hide(); delete tray_; tray_ = nullptr; }
        if (menu_) { delete menu_; menu_ = nullptr; }
        if (window_) { delete window_; window_ = nullptr; }
        alive_ = false;
    }

  private:
    // A custom icon wins. A relative path resolves against the *executable's*
    // folder (not the process CWD), so shipping icon.png next to the exe just
    // works no matter where it is launched from. A path that fails to load is
    // reported and falls back — a silently blank tray icon is the worst
    // possible failure mode here, because nothing looks broken.
    static QIcon loadTrayIcon(const QString &path) {
        if (path.isEmpty()) return applicationIcon();

        QString resolved = path;
        if (QFileInfo(path).isRelative()) {
            resolved = QCoreApplication::applicationDirPath() + QLatin1Char('/') + path;
        }

        QIcon icon(resolved);
        if (icon.isNull()) {
            fprintf(stderr, "tray: could not load icon '%s', using the generated icon\n",
                    resolved.toUtf8().constData());
            return applicationIcon();
        }
        fprintf(stderr, "tray: tray icon loaded from '%s'\n", resolved.toUtf8().constData());
        return icon;
    }

    static QIcon applicationIcon() {
        QPixmap image(64, 64);
        image.fill(Qt::transparent);
        QPainter painter(&image);
        painter.setRenderHint(QPainter::Antialiasing);
        painter.setPen(Qt::NoPen);
        painter.setBrush(QColor("#5965E8"));
        painter.drawEllipse(4, 4, 56, 56);
        QFont font;
        font.setFamily("Arial");
        font.setPixelSize(34);
        font.setBold(true);
        painter.setFont(font);
        painter.setPen(Qt::white);
        painter.drawText(image.rect(), Qt::AlignCenter, "T");
        return QIcon(image);
    }

    void addAction(QMenu *menu, const QString &label, const QString &value) {
        QAction *action = menu->addAction(label);
        QObject::connect(action, &QAction::triggered, [this, value]() {
            enqueue("menu", {}, value);
        });
    }

    void enqueue(const QString &type, const QString &id = {}, const QString &value = {},
                 const Array &payload = {}) {
        Array event;
        event.set("type", toPhpString(type));
        if (!id.isEmpty()) event.set("id", toPhpString(id));
        if (!value.isEmpty()) event.set("value", toPhpString(value));
        if (payload.count() > 0) event.set("payload", payload);
        events_.push_back(event);
    }

    TrayWindow *window_ = nullptr;
    QSystemTrayIcon *tray_ = nullptr;
    QMenu *menu_ = nullptr;
    QLabel *hint_ = nullptr;
    bool alive_ = false;
    std::deque<Array> events_;
};

TrayAppBox *appBox(Variant box) { return box.toBox<TrayAppBox>(); }

}  // namespace

// ---- 4. Entry points -------------------------------------------------------

Variant php_tray_create(String title, String iconPath) {
    if (!qt_application) {
        qt_application = new QApplication(qt_argc, qt_argv);
        // Critical for a tray app: hiding/closing the last window must NOT quit.
        qt_application->setQuitOnLastWindowClosed(false);
        qt_application->setStyle(QStyleFactory::create("Fusion"));
        QFont font = qt_application->font();
#ifdef Q_OS_WIN
        font.setFamily("Microsoft YaHei UI");
#endif
        font.setPointSize(10);
        qt_application->setFont(font);
        qt_application->setStyleSheet("QWidget { background: #F6F7FB; color: #1C2035; }"
                                      "QMenu { background: #FFFFFF; border: 1px solid #E0E3ED; }");
    }
    return {new TrayAppBox(toQString(title), toQString(iconPath))};
}

Bool php_tray_is_alive(Variant box) { return appBox(box)->isAlive(); }
void php_tray_process_events(Variant box) { appBox(box)->processEvents(); }
Array php_tray_poll_event(Variant box) { return appBox(box)->pollEvent(); }
void php_tray_set_status(Variant box, String text) { appBox(box)->setStatus(toQString(text)); }
void php_tray_show_message(Variant box, String title, String body) {
    appBox(box)->showMessage(toQString(title), toQString(body));
}
void php_tray_show_window(Variant box, Bool show) { appBox(box)->showWindow(show); }
Bool php_tray_is_window_visible(Variant box) { return appBox(box)->isWindowVisible(); }
void php_tray_quit(Variant box) { appBox(box)->quit(); }
Bool php_tray_snapshot(Variant box, String path) { return appBox(box)->snapshot(toQString(path)); }
void php_tray_destroy(Variant box) { appBox(box)->cleanup(); }
