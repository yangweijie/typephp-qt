// TypePHP\Qt — webview 控件。
//
// 两种后端，编译期二选一：
//
//   * WebView2（Windows，需 third_party/webview2）—— 完整 Chromium（Edge 内核）：
//     支持 JS、现代 CSS、SPA。运行时由系统提供（Win10/11 随 Edge 自带）。
//   * QTextBrowser（其余平台 / 未启用 SDK）—— QtWidgets 自带，零新依赖：
//     支持 HTML 子集、图片、链接，**不支持 JS**。
//
// 对外只暴露一个控件类型 "webview"，应用侧不关心后端 ——
// 两者实现同一组属性（url / html / zoom）与事件（link / loaded / title）。
// 后端名与 JS 能力可由 PHP 查询（见 qt_window_webview_backend / _supports_js）。

#include "qt_common.h"

#include <QTextBrowser>
#include <QUrl>

#ifdef QT_WEBVIEW2
#include <windows.h>
#include <wrl.h>
#include <WebView2.h>
using Microsoft::WRL::Callback;
using Microsoft::WRL::ComPtr;
#endif

// ────────────────────────────── 后端标识 ──────────────────────────────

#ifdef QT_WEBVIEW2
static const char *kWebViewBackend = "webview2";
#else
static const char *kWebViewBackend = "textbrowser";
#endif

const char *qtWebViewBackend() { return kWebViewBackend; }

/** 后端是否支持 JavaScript —— 应用据此决定要不要降级提示。 */
bool qtWebViewSupportsJs() {
#ifdef QT_WEBVIEW2
    return true;
#else
    return false;
#endif
}

// ────────────────────────────── QTextBrowser 后端 ──────────────────────────────

/**
 * QTextBrowser 后端：非 Windows 平台的实现，也是 WebView2 不可用时的兜底。
 *
 * 行为与 WebView2 后端对齐：
 *   - 点链接只上报（发 link 事件，value = href），跳不跳由 PHP 决定（状态驱动）
 *   - 外部链接不自己打开系统浏览器，交给应用决定
 */
QWidget *qtCreateWebViewTextBrowser(QtWindowBox *box, const QString &id) {
    auto *view = new QTextBrowser();
    view->setOpenExternalLinks(false);
    view->setOpenLinks(false);
    view->setReadOnly(true);

    QObject::connect(view, &QTextBrowser::anchorClicked, box->window(), [box, id](const QUrl &url) {
        box->enqueue(QStringLiteral("link"), id, url.toString());
    });

    return view;
}

// ────────────────────────────── WebView2 后端 ──────────────────────────────

#ifdef QT_WEBVIEW2

namespace {

/**
 * WebView2 的「环境」由用户数据目录唯一确定，且**整个进程只该建一次** ——
 * 每个 webview 各建一个会拉起多组浏览器进程。这里做成懒加载单例：
 * 第一个 webview 触发创建，后续控件复用。
 */
struct WebView2Host {
    ComPtr<ICoreWebView2Environment> env;
    bool ready = false;
    bool failed = false;
    QString userDataDir;
};

WebView2Host &hostState() {
    static WebView2Host host;
    return host;
}

/** WebView2 运行时是否可用（缺了就该提示用户装，而不是白屏）。 */
bool webView2RuntimeAvailable() {
    LPWSTR version = nullptr;
    const HRESULT hr = GetAvailableCoreWebView2BrowserVersionString(nullptr, &version);
    const bool ok = SUCCEEDED(hr) && version != nullptr;
    if (version) CoTaskMemFree(version);
    return ok;
}

/** 用户数据目录放在 exe 同级，避免污染用户的浏览器 profile。 */
QString webView2UserDataDir() {
    const QString base = QCoreApplication::applicationDirPath() + QStringLiteral("/.webview2");
    QDir().mkpath(base);
    return base;
}

/** 把 CoTaskMem 宽字符串取成 QString（WebView2 的字符串都由调用方释放）。 */
QString takeCoTaskMemString(LPWSTR raw) {
    if (!raw) return QString();
    const QString out = QString::fromWCharArray(raw);
    CoTaskMemFree(raw);
    return out;
}

/** QRect → RECT（WebView2 用 Win32 RECT 定位）。 */
RECT toWinRect(const QRect &r) {
    RECT out;
    out.left = r.left();
    out.top = r.top();
    out.right = r.right();
    out.bottom = r.bottom();
    return out;
}

}  // namespace

/**
 * WebView2 后端。
 *
 * 比 QTextBrowser 复杂：控件本身同步建得出来，但「环境 → 控制器 → webview」
 * 三级 COM 对象是**异步回调**产出的。所以：
 *   1. 先返回一个占位 QWidget（框架的 diff 需要一个稳定指针）
 *   2. 回调完成后把真正的 webview 贴进占位控件的布局
 *   3. 就绪前到达的 url/html/zoom 记进 pending，就绪后补发
 *
 * 应用侧看不出异步：属性照常设，只是稍晚生效。
 */
class WebView2Widget : public QWidget {
  public:
    WebView2Widget(QtWindowBox *box, QString id)
        : QWidget(), box_(box), id_(std::move(id)) {
        // WebView2 的画面是它自己的子窗口，不参与 Qt 的 sizeHint 计算。
        // 不设 Expanding 的话，占位标签一删、内层布局空了，sizeHint 归零，
        // 外层布局就会把整个控件压成 0 高（实测：694x16 → 694x0）。
        setSizePolicy(QSizePolicy::Expanding, QSizePolicy::Expanding);
        // 最小高度不能是 1：窗口内容比窗口高时，Qt 会把可压缩的项压到最小，
        // WebView 就被挤成一条缝（实测 694x16，画面完全看不见）。
        // 给一个像样的下限，宁可让窗口滚动，也别让 webview 消失。
        setMinimumHeight(120);

        auto *layout = new QVBoxLayout(this);
        layout->setContentsMargins(0, 0, 0, 0);
        placeholder_ = new QLabel(QStringLiteral("WebView2 初始化中…"), this);
        placeholder_->setAlignment(Qt::AlignCenter);
        placeholder_->setWordWrap(true);
        layout->addWidget(placeholder_);

        if (!webView2RuntimeAvailable()) {
            showUnavailable();
            return;
        }
        initAsync();
    }

    void setUrl(const QString &url) {
        if (!webview_) {
            pendingUrl_ = url;
            pendingHtml_.clear();
            return;
        }
        webview_->Navigate(qtResolveUrl(url).toString().toStdWString().c_str());
    }

    void setHtml(const QString &html) {
        if (!webview_) {
            pendingHtml_ = html;
            pendingUrl_.clear();
            return;
        }
        webview_->NavigateToString(html.toStdWString().c_str());
    }

    void setZoom(double factor) {
        if (webview_ && controller_) controller_->put_ZoomFactor(factor);
        else pendingZoom_ = factor;
    }

    void reload() {
        if (webview_) webview_->Reload();
    }

    void goBack() {
        if (webview_) webview_->GoBack();
    }

    void goForward() {
        if (webview_) webview_->GoForward();
    }

  private:
    /** WebView2 不参与 Qt 布局，尺寸要手动同步到宿主控件大小。 */
    RECT webView2Bounds() const {
        return toWinRect(QRect(0, 0, width(), height()));
    }

    void showUnavailable() {
        if (!placeholder_) return;
        placeholder_->setText(QStringLiteral(
            "未检测到 WebView2 运行时。\n\n"
            "Windows 10/11 通常随 Edge 自带；缺失时请安装\n"
            "“Microsoft Edge WebView2 Runtime”（微软官网免费下载）。"));
    }

    void initAsync() {
        auto *host = &hostState();

        auto onEnv = [this](HRESULT hr, ICoreWebView2Environment *env) {
            if (FAILED(hr) || !env) { showUnavailable(); return; }
            hostState().env = env;
            hostState().ready = true;

            env->CreateCoreWebView2Controller(
                reinterpret_cast<HWND>(winId()),
                Callback<ICoreWebView2CreateCoreWebView2ControllerCompletedHandler>(
                    [this](HRESULT hr2, ICoreWebView2Controller *ctrl) -> HRESULT {
                        if (FAILED(hr2) || !ctrl) { showUnavailable(); return S_OK; }
                        controller_ = ctrl;
                        controller_->get_CoreWebView2(&webview_);
                        if (!webview_) { showUnavailable(); return S_OK; }

                        controller_->put_Bounds(webView2Bounds());
                        controller_->put_IsVisible(TRUE);
                        

                        if (placeholder_) {
                            placeholder_->hide();
                            placeholder_->deleteLater();
                            placeholder_ = nullptr;
                        }
                        wireEvents();
                        flushPending();
                        return S_OK;
                    })
                    .Get());
        };

        if (host->ready && host->env) { onEnv(S_OK, host->env.Get()); return; }
        if (host->failed) { showUnavailable(); return; }

        host->userDataDir = webView2UserDataDir();
        const HRESULT hr = CreateCoreWebView2EnvironmentWithOptions(
            nullptr, host->userDataDir.toStdWString().c_str(), nullptr,
            Callback<ICoreWebView2CreateCoreWebView2EnvironmentCompletedHandler>(
                [onEnv](HRESULT hr2, ICoreWebView2Environment *env) -> HRESULT {
                    onEnv(hr2, env);
                    return S_OK;
                })
                .Get());
        if (FAILED(hr)) { host->failed = true; showUnavailable(); }
    }

    void wireEvents() {
        EventRegistrationToken tok{};

        // 导航完成 → loaded（value = 最终 URL，payload.success 表示成功与否）
        webview_->add_NavigationCompleted(
            Callback<ICoreWebView2NavigationCompletedEventHandler>(
                [this](ICoreWebView2 *sender, ICoreWebView2NavigationCompletedEventArgs *args) -> HRESULT {
                    BOOL ok = FALSE;
                    args->get_IsSuccess(&ok);
                    LPWSTR uri = nullptr;
                    sender->get_Source(&uri);
                    Array payload;
                    payload.set("success", Variant(ok != FALSE));
                    box_->enqueue(QStringLiteral("loaded"), id_,
                                  QString::fromWCharArray(L""), payload);
                    return S_OK;
                })
                .Get(),
            &tok);

        // 文档标题变化 → title（应用可用它更新窗口标题）
        EventRegistrationToken tok2{};
        webview_->add_DocumentTitleChanged(
            Callback<ICoreWebView2DocumentTitleChangedEventHandler>(
                [this](ICoreWebView2 *sender, IUnknown *) -> HRESULT {
                    LPWSTR title = nullptr;
                    sender->get_DocumentTitle(&title);
                    box_->enqueue(QStringLiteral("title"), id_,
                                  takeCoTaskMemString(title));
                    return S_OK;
                })
                .Get(),
            &tok2);

        // target=_blank 之类的新窗口请求：拦下并上报，不在应用内乱弹窗
        EventRegistrationToken tok3{};
        webview_->add_NewWindowRequested(
            Callback<ICoreWebView2NewWindowRequestedEventHandler>(
                [this](ICoreWebView2 *, ICoreWebView2NewWindowRequestedEventArgs *args) -> HRESULT {
                    LPWSTR uri = nullptr;
                    args->get_Uri(&uri);
                    args->put_Handled(TRUE);
                    box_->enqueue(QStringLiteral("link"), id_,
                                  takeCoTaskMemString(uri));
                    return S_OK;
                })
                .Get(),
            &tok3);

        // 导航开始 → 可用来禁用/启用「后退」按钮
        EventRegistrationToken tok4{};
        webview_->add_NavigationStarting(
            Callback<ICoreWebView2NavigationStartingEventHandler>(
                [this](ICoreWebView2 *sender, ICoreWebView2NavigationStartingEventArgs *) -> HRESULT {
                    LPWSTR uri = nullptr;
                    sender->get_Source(&uri);
                    box_->enqueue(QStringLiteral("navigating"), id_,
                                  takeCoTaskMemString(uri));
                    return S_OK;
                })
                .Get(),
            &tok4);
    }

    void flushPending() {
        if (!webview_) return;
        if (!pendingHtml_.isEmpty()) {
            const QString html = pendingHtml_;
            pendingHtml_.clear();
            webview_->NavigateToString(html.toStdWString().c_str());
        } else if (!pendingUrl_.isEmpty()) {
            const QString url = pendingUrl_;
            pendingUrl_.clear();
            webview_->Navigate(qtResolveUrl(url).toString().toStdWString().c_str());
        }
        if (pendingZoom_ > 0 && controller_) {
            controller_->put_ZoomFactor(pendingZoom_);
            pendingZoom_ = 0;
        }
    }

    void resizeEvent(QResizeEvent *event) override {
        QWidget::resizeEvent(event);
        // WebView2 不参与 Qt 布局，尺寸要手动同步
        if (controller_) controller_->put_Bounds(webView2Bounds());
    }

    /**
     * 给布局一个像样的默认尺寸。
     *
     * WebView2 的画面是独立的子窗口，对 Qt 的 sizeHint 毫无贡献；占位标签删掉后
     * 内层布局为空，sizeHint 归零，外层布局就会把它压成 0 高（实测 694x16 → 694x0）。
     * 返回一个合理的默认值，`grow` 才能把它撑开。
     */
    QSize sizeHint() const override { return QSize(480, 320); }
    QSize minimumSizeHint() const override { return QSize(80, 60); }

    QtWindowBox *box_ = nullptr;
    QString id_;
    QLabel *placeholder_ = nullptr;
    ComPtr<ICoreWebView2Controller> controller_;
    ComPtr<ICoreWebView2> webview_;
    QString pendingUrl_;
    QString pendingHtml_;
    double pendingZoom_ = 0;
};

#endif  // QT_WEBVIEW2

// ────────────────────────────── 统一入口 ──────────────────────────────

QWidget *qtCreateWebView(QtWindowBox *box, const QString &id) {
#ifdef QT_WEBVIEW2
    return new WebView2Widget(box, id);
#else
    return qtCreateWebViewTextBrowser(box, id);
#endif
}

/**
 * 把 url / html / zoom 应用到 webview 控件上。
 *
 * 两个后端的能力不同，但都接受同一组属性：
 *   - url  两边都支持（WebView2 走 Navigate，QTextBrowser 走 setSource）
 *   - html  两边都支持
 *   - zoom 只有 WebView2 支持（QTextBrowser 没有缩放因子），静默忽略
 */
void qtWebViewApplyProp(QWidget *widget, const QString &key, const Variant &value) {
    if (key == QLatin1String("zoom")) {
#ifdef QT_WEBVIEW2
        if (auto *wv = dynamic_cast<WebView2Widget *>(widget)) wv->setZoom(static_cast<double>(value.toFloat()));
#endif
        return;  // QTextBrowser 无缩放，静默忽略（与未知属性一致）
    }

#ifdef QT_WEBVIEW2
    if (auto *wv = dynamic_cast<WebView2Widget *>(widget)) {
        if (key == QLatin1String("url")) wv->setUrl(toQString(value));
        else wv->setHtml(toQString(value));
        return;
    }
#endif

    if (auto *tb = qobject_cast<QTextBrowser *>(widget)) {
        if (key == QLatin1String("url")) {
            tb->setSource(qtResolveUrl(toQString(value)));
        } else {
            tb->setHtml(toQString(value));
        }
    }
}
