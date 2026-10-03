// TypePHP\Qt — webview 的 macOS 后端：系统 WebKit（WKWebView）。
//
// 为什么不装 Homebrew 的 Qt WebView 模块：那个模块在 macOS 上本来就用系统 WebView
// （官方文档：「On macOS, the system web view is used in the same manner as iOS」），
// 而 brew 的打包把它和 QtWebEngine 绑在一起（GB 级）。直接链系统
// /System/Library/Frameworks/WebKit.framework ⇒ 零安装、完整 JS、包体不变大。
//
// 与另两个后端同一组属性（url / html / zoom）与事件（link / loaded / title / navigating）。
// 只在 macOS 上、且 project yml 里定义了 QT_WEBVIEW_WK 时才参与编译。

#include "qt_common.h"

#import <Cocoa/Cocoa.h>
#import <WebKit/WebKit.h>

class WKWebViewWidget;

namespace {

NSString *toNSString(const QString &s) { return s.toNSString(); }

QString fromNSString(NSString *_Nullable s) {
    return s ? QString::fromNSString(s) : QString();
}

}  // namespace

/**
 * WKWebView 的 delegate 必须是 NSObject，而宿主控件是 QWidget（C++ 类）。
 *
 * 两边只能靠指针连起来：handler 持有宿主（宿主拥有 handler，故该指针始终有效），
 * 宿主持有 handler 让 WebKit 那个 weak 的 navigationDelegate 不至于悬空。
 * 事件一律转成宿主的 report* 成员函数 —— ObjC 的 ivar 在 C++ 侧不可直接取，
 * 所以这里刻意不走「handler 上挂回调」的写法。
 */
@interface QtWKHandler : NSObject <WKNavigationDelegate> {
@public
    WKWebViewWidget *widget_;
}
- (instancetype)initWithWidget:(WKWebViewWidget *)widget;
@end

/**
 * WKWebView 后端控件。
 *
 * 关键点是「什么时候挂」：WKWebView 是**原生子 NSView**，要挂到 Qt 给这个控件的
 * NSView 上，而那个 NSView 只在控件成为 native 之后才存在 —— `winId()` 会强制这一点。
 * 所以挂载推迟到首次 showEvent（此时几何已定、平台窗口已建），之前到达的
 * url / html / zoom 记进 pending，挂上后补发。应用侧看不出这个先后。
 */
class WKWebViewWidget : public QWidget {
  public:
    WKWebViewWidget(QtWindowBox *box, QString id) : QWidget(), box_(box), id_(std::move(id)) {
        // 网页画面由 WebKit 自己出，对 Qt 的 sizeHint 毫无贡献；不给下限就会被外层布局压成 0 高
        // （WebView2 后端实测过同一现象：694x16 → 694x0）。
        setSizePolicy(QSizePolicy::Expanding, QSizePolicy::Expanding);
        setMinimumHeight(120);
    }

    ~WKWebViewWidget() override {
        if (web_ && titleObserved_) [web_ removeObserver:handler_ forKeyPath:@"title"];
        titleObserved_ = false;
        web_.navigationDelegate = nil;
    }

    // ── 供 QtWKHandler 回调（事件语义与另两个后端逐字对齐）──

    void reportLink(const QString &url) { box_->enqueue(QStringLiteral("link"), id_, url); }

    void reportNavigating(const QString &url) { box_->enqueue(QStringLiteral("navigating"), id_, url); }

    void reportLoaded(const QString &url, bool ok) {
        Array payload;
        payload.set("success", Variant(ok));
        box_->enqueue(QStringLiteral("loaded"), id_, url, payload);
    }

    void reportTitle(const QString &title) { box_->enqueue(QStringLiteral("title"), id_, title); }

    void setUrl(const QString &url) {
        if (!web_) {
            pendingHtml_.clear();
            pendingUrl_ = url;
            return;
        }
        const QUrl resolved = qtResolveUrl(url);
        if (!resolved.isValid()) return;
        NSURL *nsUrl = [NSURL URLWithString:toNSString(resolved.toString())];
        if (!nsUrl) return;
        [web_ loadRequest:[NSURLRequest requestWithURL:nsUrl]];
    }

    void setHtml(const QString &html) {
        if (!web_) {
            pendingUrl_.clear();
            pendingHtml_ = html;
            return;
        }
        [web_ loadHTMLString:toNSString(html) baseURL:nil];
    }

    void setZoom(double factor) {
        if (web_) web_.pageZoom = factor;
        else pendingZoom_ = factor;
    }

  protected:
    void showEvent(QShowEvent *event) override {
        QWidget::showEvent(event);
        if (!web_) attach();
        else syncFrame();
    }

    void resizeEvent(QResizeEvent *event) override {
        QWidget::resizeEvent(event);
        syncFrame();
    }

  private:
    NSView *hostView() const {
        // winId() 会强制本控件建出自己的平台窗口（原生 NSView）。
        return (__bridge NSView *)(void *)winId();
    }

    void attach() {
        // 只有 cocoa 下 `winId()` 才是 NSView。offscreen 无头平台给的是一个非 ObjC 的指针，
        // 拿它发消息直接段错误（实测 `QT_QPA_PLATFORM=offscreen --shot` rc=139）；
        // 此时控件保持空白 —— 与「原生子 view 不进 grab()」的产物一致，帧仍是确定的。
        if (QGuiApplication::platformName() != QLatin1String("cocoa")) return;

        NSView *parent = hostView();
        web_ = [[WKWebView alloc] initWithFrame:parent.bounds
                                  configuration:[[WKWebViewConfiguration alloc] init]];
        web_.autoresizingMask = NSViewWidthSizable | NSViewHeightSizable;

        handler_ = [[QtWKHandler alloc] initWithWidget:this];
        web_.navigationDelegate = handler_;

        [parent addSubview:web_];
        // title 没有 delegate 回调，只能 KVO
        [web_ addObserver:handler_ forKeyPath:@"title" options:NSKeyValueObservingOptionNew context:nil];
        titleObserved_ = true;

        flushPending();
    }

    /** 挂上之后补发挂载前到达的属性（顺序与 WebView2 后端一致：html 优先于 url）。 */
    void flushPending() {
        if (!web_) return;
        if (!pendingHtml_.isEmpty()) {
            const QString html = pendingHtml_;
            pendingHtml_.clear();
            setHtml(html);
        } else if (!pendingUrl_.isEmpty()) {
            const QString url = pendingUrl_;
            pendingUrl_.clear();
            setUrl(url);
        }
        if (pendingZoom_ > 0) {
            web_.pageZoom = pendingZoom_;
            pendingZoom_ = 0;
        }
    }

    /**
     * 把网页尺寸贴回宿主。
     *
     * `autoresizingMask` 只在宿主 NSView 的 bounds 变化之后才生效，而 Qt 何时同步
     * 原生视图几何不由我们决定；两者都做，帧就一定跟得上（幂等，代价是一次比较）。
     */
    void syncFrame() {
        if (!web_) return;
        const NSSize size = hostView().bounds.size;
        const CGRect f = web_.frame;
        if (qRound(f.size.width) != qRound(size.width) || qRound(f.size.height) != qRound(size.height)) {
            web_.frame = NSMakeRect(0, 0, size.width, size.height);
        }
    }

    QSize sizeHint() const override { return QSize(480, 320); }
    QSize minimumSizeHint() const override { return QSize(80, 60); }

    QtWindowBox *box_ = nullptr;
    QString id_;
    WKWebView *web_ = nil;
    QtWKHandler *handler_ = nil;
    bool titleObserved_ = false;
    QString pendingUrl_;
    QString pendingHtml_;
    double pendingZoom_ = 0;
};

@implementation QtWKHandler

- (instancetype)initWithWidget:(WKWebViewWidget *)widget {
    if ((self = [super init])) widget_ = widget;
    return self;
}

// 文档里点链接：只上报 link 并拦下，跳不跳由 PHP 决定 ——
// 与 QTextBrowser 的 anchorClicked、WebView2 的 NewWindowRequested 同一约定。
- (void)webView:(WKWebView *)webView
    decidePolicyForNavigationAction:(WKNavigationAction *)action
                    decisionHandler:(void (^)(WKNavigationActionPolicy))decisionHandler {
    const QString target = fromNSString(action.request.URL.absoluteString);
    if (action.navigationType == WKNavigationTypeLinkActivated) {
        widget_->reportLink(target);
        decisionHandler(WKNavigationActionPolicyCancel);
        return;
    }
    // 非用户点击的导航（loadHTMLString / loadRequest / 页内跳转）→ navigating。
    // 取的是**目标** URL，不是导航开始前的源。
    widget_->reportNavigating(target);
    decisionHandler(WKNavigationActionPolicyAllow);
}

- (void)webView:(WKWebView *)webView didFinishNavigation:(WKNavigation *)navigation {
    widget_->reportLoaded(fromNSString(webView.URL.absoluteString), true);
}

- (void)webView:(WKWebView *)webView didFailNavigation:(WKNavigation *)navigation withError:(NSError *)error {
    widget_->reportLoaded(fromNSString(webView.URL.absoluteString), false);
}

- (void)webView:(WKWebView *)webView didFailProvisionalNavigation:(WKNavigation *)navigation
                                                       withError:(NSError *)error {
    widget_->reportLoaded(fromNSString(webView.URL.absoluteString), false);
}

- (void)observeValueForKeyPath:(NSString *)keyPath
                      ofObject:(id)object
                        change:(NSDictionary<NSKeyValueChangeKey, id> *)change
                       context:(void *)context {
    if ([keyPath isEqualToString:@"title"]) {
        id newValue = change[NSKeyValueChangeNewKey];
        if ([newValue isKindOfClass:NSString.class]) {
            widget_->reportTitle(fromNSString((NSString *)newValue));
        }
        return;
    }
    [super observeValueForKeyPath:keyPath ofObject:object change:change context:context];
}

@end

// ────────────────────────────── 对外入口 ──────────────────────────────

QWidget *qtCreateWebViewWK(QtWindowBox *box, const QString &id) { return new WKWebViewWidget(box, id); }

void qtWebViewApplyPropWK(QWidget *widget, const QString &key, const Variant &value) {
    auto *view = dynamic_cast<WKWebViewWidget *>(widget);
    if (!view) return;
    if (key == QLatin1String("url")) view->setUrl(toQString(value));
    else if (key == QLatin1String("html")) view->setHtml(toQString(value));
    else if (key == QLatin1String("zoom")) view->setZoom(static_cast<double>(value.toFloat()));
}
