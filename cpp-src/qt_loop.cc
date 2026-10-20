// TypePHP\Qt — 应用/窗口生命周期、事件循环、事件队列、定时器、网络原语。
//
// 渲染遍历在 qt_render.cc，窗口装饰与对话框在 qt_decor.cc。

#include "qt_common.h"

#include <QAbstractAnimation>
#include <QElapsedTimer>
#include <QGuiApplication>
#include <QScreen>
#include <QUrl>

namespace {

int optInt(const Array &options, const char *key, int fallback = 0) {
    const Variant v = options.get(key);
    return v.isUndef() || v.isNull() ? fallback : static_cast<int>(v.toInt());
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

    // win_N：进程内递增序号，与 FakeBridge 的窗口 id 保持一致。
    static int serial = 0;
    id_ = QStringLiteral("win_%1").arg(++serial);

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
    if (!iconPath.isEmpty()) window_->setWindowIcon(QIcon(qtResolvePath(iconPath)));

    const QString stylesheet = optString(options, "stylesheet");
    if (!stylesheet.isEmpty()) window_->setStyleSheet(stylesheet);

    if (optBool(options, "centered")) {
        const QRect screen = QGuiApplication::primaryScreen()->availableGeometry();
        window_->move((screen.width() - window_->width()) / 2, (screen.height() - window_->height()) / 2);
    }

    QObject::connect(window_, &QMainWindow::destroyed, window_, [this]() { window_ = nullptr; });
    QObject::connect(window_, &QObject::destroyed, window_, [this]() { central_ = nullptr; rootLayout_ = nullptr; });

    window_->show();

    qt_windows.append(this);
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
    // 必须幂等：~QtWindowBox 与 php_qt_window_destroy 都会走到这里。
    qt_windows.removeAll(this);
    if (tray_) {
        tray_->hide();
        delete tray_;
        tray_ = nullptr;
    }
    if (trayMenu_) {
        // 先摘掉引用再删：托盘已删，但菜单还挂在 window_ 下，
        // 不摘干净会留下一个指向已销毁对象的 contextMenu 指针。
        delete trayMenu_;
        trayMenu_ = nullptr;
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

/**
 * 抓一帧窗口像素存成 PNG。为了让同一份代码每次抓出同一张图（sha256 能当基线），
 * grab 前把三类「与代码无关」的瞬态按掉，抓完再还原，不影响正在运行的应用：
 *   * 焦点：cocoa 上窗口何时被激活、Qt 是否已自动把焦点落到第一个可聚焦子控件，
 *     取决于激活事件与 grab 的先后。有焦点就多一圈蓝色描边和一根会闪的光标。
 *     必须**清掉**而不是重设 —— 重设会把两种起点分别留成两种产物，清掉才收敛成一种。
 *   * hover：Fusion 的 mouseOver 读的就是 WA_UnderMouse，不清就取决于宿主鼠标停在哪。
 *   * 瞬态动画：上面那句 clearFocus() 自己就会启动 QLineEdit 清除按钮的淡出，
 *     于是 grab 抓到动画中途（实测只泵 3 帧时 8 次跑出 4 种哈希，差异全在
 *     `(622,66)-(636,80)` 那一格）。把还在跑的动画直接推到终点值，帧就不再取决于墙钟相位。
 */
bool QtWindowBox::snapshot(const QString &path) {
    if (!window_) return false;
    // 这里**故意只泵一遍**，不要加"等布局收敛"的循环。
    // 试过：把排队的 LayoutRequest 用 sendPostedEvents(…, LayoutRequest) + activate() 抽干，
    // 听起来正好治"拍到收敛前的一帧"。A/B 实测否掉了它 ——
    // 现状 8 次截图 8 个相同哈希；加了那个循环变成 6/2 两个哈希。
    // 原因：那一帧里到底有没有排着 LayoutRequest 取决于时序，于是"收敛"变成了抛硬币，
    // 而**可复现**是这条截图基线唯一重要的性质（headless.md 整篇都在护它）。
    // 布局问题的正确仪器是几何量测（minimumSizeHint vs viewport），不是多等一会儿的截图。
    qt_application->processEvents();
    QWidget *focused = window_->focusWidget();
    if (focused) focused->clearFocus();
    const QList<QWidget *> widgets = window_->findChildren<QWidget *>() << window_;
    for (QWidget *w : widgets) w->setAttribute(Qt::WA_UnderMouse, false);
    const QList<QAbstractAnimation *> animations = window_->findChildren<QAbstractAnimation *>();
    for (QAbstractAnimation *anim : animations) {
        if (anim->state() == QAbstractAnimation::Stopped) continue;
        // jumpToEnd() 只有 QPropertyAnimation 有；QAbstractAnimation 上的通用写法是
        // 把当前时间推到终点，让动画自己把终值同步发出去（QTimeLine 的 currentValue 连着淡入淡出）。
        const int total = anim->totalDuration();
        if (total > 0) {
            anim->setCurrentTime(total);
            anim->stop();
        }
    }
    QPixmap shot = window_->grab();
    if (focused) focused->setFocus();
    return shot.save(path, "PNG");
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

// ────────────────────────────── 事件入队 ──────────────────────────────

void QtWindowBox::enqueue(const QString &type, const QString &id, const QString &value, const Array &payload) {
    Array event;
    event.set("type", toPhpString(type));
    if (!id.isEmpty()) event.set("id", toPhpString(id));
    if (!value.isEmpty()) event.set("value", toPhpString(value));
    if (payload.count() > 0) event.set("payload", payload);
    events_.push_back(event);
}

void QtWindowBox::sendEvent(const Array &event) {
    // 不能走 enqueue()：那个会把 value 压成 QString，丢失结构化 payload。
    events_.push_back(event);
}

Array QtWindowBox::drainEvents(int max) {
    Array out;
    if (max <= 0) return out;
    for (int taken = 0; taken < max && !events_.empty(); ++taken) {
        out.append(events_.front());
        events_.pop_front();
    }
    return out;
}

// ────────────────────────────── php_qt_* 包装 ──────────────────────────────

String php_qt_bridge_version() { return String(QTBRIDGE_VERSION); }

String php_qt_webview_backend() { return String(qtWebViewBackend()); }

Bool php_qt_webview_supports_js() { return qtWebViewSupportsJs(); }

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
        if (!iconPath.isEmpty()) qt_application->setWindowIcon(QIcon(qtResolvePath(iconPath)));
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

void php_qt_window_set_timer(Variant box, String id, Int intervalMs) {
    boxFrom(box)->setTimer(toQString(id), static_cast<int>(intervalMs));
}

void php_qt_window_send_event(Variant box, Array event) { boxFrom(box)->sendEvent(event); }
Array php_qt_window_poll_events(Variant box, Int max) {
    return boxFrom(box)->drainEvents(static_cast<int>(max));
}

// ────────────────────────────── 网络原语 ──────────────────────────────

Array php_qt_http_request(Array spec) {
    Array out;
    out.set("status", Variant(static_cast<Int>(0)));
    out.set("headers", Array());
    out.set("body", toPhpString(QString()));
    // QNetworkAccessManager 依赖 QApplication，先于一切网络对象判断。
    if (!qt_application) {
        out.set("error", toPhpString(QStringLiteral("qt_http_request: QApplication 尚未创建，请先调用 qt_app_create()")));
        return out;
    }
    const QString url = optString(spec, "url");
    if (url.isEmpty()) {
        out.set("error", toPhpString(QStringLiteral("qt_http_request: url 不能为空")));
        return out;
    }
    const QString method = optString(spec, "method", QStringLiteral("GET")).toUpper();
    const int timeoutMs = optInt(spec, "timeout_ms", 15000);
    const bool followRedirects = optBool(spec, "follow_redirects", true);

    QNetworkAccessManager manager;
    QNetworkRequest request{QUrl(url)};
    request.setAttribute(QNetworkRequest::RedirectPolicyAttribute,
                         followRedirects ? QNetworkRequest::NoLessSafeRedirectPolicy
                                         : QNetworkRequest::ManualRedirectPolicy);
    request.setTransferTimeout(timeoutMs > 0 ? timeoutMs : 15000);

    const Variant headerSpec = spec.get("headers");
    if (headerSpec.isArray()) {
        const Array headers = headerSpec.toArray();
        for (auto it = headers.begin(); it != headers.end(); ++it) {
            request.setRawHeader(toQString(it.key()).toUtf8(), it.value().toCString());
        }
    }

    const QByteArray body = toQString(spec.get("body")).toUtf8();
    QNetworkReply *reply = nullptr;
    if (method == "POST") {
        reply = manager.post(request, body);
    } else if (method == "PUT") {
        reply = manager.put(request, body);
    } else if (method == "DELETE") {
        reply = manager.deleteResource(request);
    } else if (method == "PATCH") {
        reply = manager.sendCustomRequest(request, QByteArrayLiteral("PATCH"), body);
    } else {
        reply = manager.get(request);
    }
    if (!reply) {
        out.set("error", toPhpString(QStringLiteral("qt_http_request: 无法发起请求")));
        return out;
    }

    QEventLoop loop;
    QTimer timer;
    timer.setSingleShot(true);
    QObject::connect(reply, &QNetworkReply::finished, &loop, &QEventLoop::quit);
    QObject::connect(&timer, &QTimer::timeout, &loop, &QEventLoop::quit);
    timer.start(timeoutMs > 0 ? timeoutMs : 15000);
    loop.exec();
    if (timer.isActive()) {
        timer.stop();
    } else {
        // 超时分支：先让 reply 收尾，再读它的错误；否则 finished 可能还没到。
        reply->abort();
    }

    const QVariant statusAttr = reply->attribute(QNetworkRequest::HttpStatusCodeAttribute);
    Array headers;
    for (const auto &pair : reply->rawHeaderPairs()) {
        headers.set(toPhpString(QString::fromUtf8(pair.first)), toPhpString(QString::fromUtf8(pair.second)));
    }
    QString error;
    if (reply->error() != QNetworkReply::NoError) error = reply->errorString();

    out.set("status", Variant(static_cast<Int>(statusAttr.isValid() ? statusAttr.toInt() : 0)));
    out.set("headers", headers);
    out.set("body", toPhpString(QString::fromUtf8(reply->readAll())));
    out.set("error", toPhpString(error));
    reply->deleteLater();
    return out;
}
