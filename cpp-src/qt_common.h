// TypePHP\Qt — 桥接共享头。
//
// 只放声明与 inline 工具函数；实现分在几个翻译单元里，用来验证 tpc 能编译
// cpp-src/ 下的多个源文件并正确链接：
//   qt_bridge.cc   应用/窗口/事件循环/渲染遍历/装饰与对话框
//   qt_widgets.cc  控件工厂与属性应用（控件目录）
//   qt_webview.cc  webview 的统一入口 + WebView2/QTextBrowser 后端
//   qt_webview_wk.mm  WKWebView 后端（仅 macOS，且仅在 QT_WEBVIEW_WK 下进 sources）
//
// 设计约束（与 php-src/qt.stub.php 一一对应）：
//   * 每个 PHP 函数 `qt_foo()` ⇄ C++ 符号 `php_qt_foo()`。
//   * 边界字符串一律 UTF-8。
//   * 信号处理只入队，绝不改状态。

#pragma once

#include "phpx.h"

#include <QAbstractItemView>
#include <QApplication>
#include <QCheckBox>
#include <QClipboard>
#include <QComboBox>
#include <QDir>
#include <QDoubleSpinBox>
#include <QEventLoop>
#include <QFileDialog>
#include <QFileInfo>
#include <QFont>
#include <QFormLayout>
#include <QFrame>
#include <QGridLayout>
#include <QGroupBox>
#include <QHBoxLayout>
#include <QHeaderView>
#include <QIcon>
#include <QLabel>
#include <QLineEdit>
#include <QListWidget>
#include <QMainWindow>
#include <QMenu>
#include <QMenuBar>
#include <QMessageBox>
#include <QPixmap>
#include <QProgressBar>
#include <QPushButton>
#include <QRadioButton>
#include <QScrollArea>
#include <QList>
#include <QSet>
#include <QSignalBlocker>
#include <QSlider>
#include <QSpacerItem>
#include <QSpinBox>
#include <QSplitter>
#include <QStackedWidget>
#include <QStatusBar>
#include <QStyle>
#include <QStyleFactory>
#include <QSystemTrayIcon>
#include <QTabWidget>
#include <QTableWidget>
#include <QTableWidgetItem>
#include <QTextEdit>
#include <QTimer>
#include <QTreeWidget>
#include <QTreeWidgetItem>
#include <QVBoxLayout>
#include <QWidget>

#include <deque>
#include <string>

using php::Array;
using php::Bool;
using php::Box;
using php::Float;
using php::Int;
using php::String;
using php::Variant;

#define QTBRIDGE_VERSION "0.1.0"

// ────────────────────────────── 转换工具（一律 UTF-8） ──────────────────────────────

inline QString toQString(const Variant &value) {
    if (value.isNull() || value.isUndef()) return {};
    if (value.isString()) return QString::fromUtf8(value.toCString());
    if (value.isBool()) return value.toBool() ? QStringLiteral("1") : QString();
    if (value.isInt() || value.isFloat()) {
        const std::string s = value.toStdString();
        return QString::fromUtf8(s.data(), static_cast<int>(s.size()));
    }
    return {};
}

inline String toPhpString(const QString &value) {
    const QByteArray utf8 = value.toUtf8();
    return String(utf8.constData(), static_cast<size_t>(utf8.size()));
}

/**
 * 把应用给的资源路径解析成绝对路径。
 *
 * 契约（README / 文档里承诺的）：**相对路径相对于可执行文件所在目录**，
 * 而不是进程 cwd —— 因为用户可能在仓库根、build/、或 dist/ 里启动，
 * 三处的 cwd 各不相同，按 cwd 解析会让同一句 'assets/icon.png' 时灵时不灵。
 *
 * 解析顺序：
 *   1. 空路径 → 原样返回（调用方据此走兜底逻辑）
 *   2. 绝对路径 → 归一化后直接用
 *   3. 相对路径 → 先试 exe 目录，再试 macOS bundle 的 Contents/Resources，最后试 cwd
 */
inline QString qtResolvePath(const QString &path) {
    if (path.isEmpty()) return path;
    const QString normalized = QDir::fromNativeSeparators(path);
    if (QFileInfo(normalized).isAbsolute()) return normalized;

    const QString exeDir = QCoreApplication::applicationDirPath();
    const QString fromExe = QDir::fromNativeSeparators(exeDir + QLatin1Char('/') + normalized);
    if (QFileInfo::exists(fromExe)) return fromExe;

    // macOS bundle：可执行文件在 Contents/MacOS，而 `qtphp package` 把 assets 放进
    // Contents/Resources —— 不查这一层的话，打包产物里的相对资源路径全部静默失效
    // （托盘图标丢，而窗口像素不受影响，所以 `--shot` 基线抓不到）。
    // 只认这个确切的目录形状，免得 Linux/Windows 上项目根恰好有个 Resources/ 就被抢先命中。
    QDir contentsDir(exeDir);
    if (contentsDir.cdUp() && contentsDir.dirName() == QLatin1String("Contents")) {
        const QString fromBundle = QDir::fromNativeSeparators(
            contentsDir.filePath(QStringLiteral("Resources/") + normalized));
        if (QFileInfo::exists(fromBundle)) return fromBundle;
    }

    // 回退到 cwd：`qtphp run` 不 chdir，用户可能从项目目录启动
    const QString fromCwd = QDir::fromNativeSeparators(
        QDir::currentPath() + QLatin1Char('/') + normalized);
    if (QFileInfo::exists(fromCwd)) return fromCwd;

    // 都不存在时给 exe 目录版本：路径拼得对、只是文件缺失，报错信息更有指向性
    return fromExe;
}

/**
 * 把 webview 的 `url` 属性解析成 QUrl。
 *
 * 与 qtResolvePath 的区别：webview 的 url 既可能是**远程地址**（http/https/file/data…），
 * 也可能是**本地文件**（相对或绝对路径）。判定顺序：
 *   1. 有 scheme 的（http://、https://、file://、data:、about:）→ 直接用
 *   2. 否则当本地路径 → 走 qtResolvePath（相对 exe 目录解析）→ 转成 file:// URL
 *
 * 这样 `'assets/help.html'` 与 `'https://example.com'` 都能直接写。
 */
inline QUrl qtResolveUrl(const QString &raw) {    const QString trimmed = raw.trimmed();
    if (trimmed.isEmpty()) return QUrl();

    // 带 scheme 的一律当 URL；`C:/x` 这种单字母盘符不算 scheme
    const int colon = trimmed.indexOf(QLatin1Char(':'));
    if (colon > 1) return QUrl(trimmed);
    if (colon == 1 && !trimmed.at(0).isLetter()) return QUrl(trimmed);

    // 无 scheme → 本地文件
    return QUrl::fromLocalFile(qtResolvePath(trimmed));
}

/** 取一个"签名"字符串。数组递归展开，用于跨调用比对属性是否变化。 */
inline QString qtSignature(const Variant &value) {
    if (value.isUndef() || value.isNull()) return QStringLiteral("\x01");
    if (value.isArray()) {
        const Array array = value.toArray();
        QString out = QStringLiteral("[");
        for (size_t i = 0; i < array.count(); ++i) {
            out += qtSignature(array.get(i));
            out += QChar(0x1f);
        }
        return out + QLatin1Char(']');
    }
    if (value.isBool()) return value.toBool() ? QStringLiteral("1") : QStringLiteral("0");
    return toQString(value);
}

inline Variant qtField(const Variant &node, const char *key) {
    return node.toArray().get(key);
}

inline bool qtHasProp(const Variant &node, const char *key) {
    const Variant v = node.toArray().get(key);
    return !(v.isUndef() || v.isNull());
}

/**
 * 表格/树的**结构字段**：它们不能走逐属性应用，必须由整表/整树重建处理。
 *
 * 注意 `rows` 在这里有歧义 —— QTextEdit 的 `rows` 是可视行数（普通属性），
 * QTableWidget 的 `rows` 是数据（结构）。所以判定要看类型，见 qtIsStructuralKey()。
 */
inline bool qtIsStructuralKey(const QString &type, const QString &key) {
    if (type == QLatin1String("table")) {
        return key == QLatin1String("columns") || key == QLatin1String("rows") || key == QLatin1String("row_ids");
    }
    if (type == QLatin1String("tree")) {
        return key == QLatin1String("headers") || key == QLatin1String("nodes");
    }
    return false;
}

/** 该类型的结构字段清单（顺序稳定，用于算签名）。 */
inline QStringList qtStructuralKeys(const QString &type) {
    if (type == QLatin1String("table")) return {QStringLiteral("columns"), QStringLiteral("rows"), QStringLiteral("row_ids")};
    if (type == QLatin1String("tree")) return {QStringLiteral("headers"), QStringLiteral("nodes")};
    return {};
}

inline int qtPropInt(const Variant &node, const char *key, int fallback = 0) {
    const Variant v = node.toArray().get(key);
    return v.isUndef() || v.isNull() ? fallback : static_cast<int>(v.toInt());
}

inline bool qtPropBool(const Variant &node, const char *key, bool fallback = false) {
    const Variant v = node.toArray().get(key);
    return v.isUndef() || v.isNull() ? fallback : v.toBool();
}

inline double qtPropDouble(const Variant &node, const char *key, double fallback = 0.0) {
    const Variant v = node.toArray().get(key);
    return v.isUndef() || v.isNull() ? fallback : v.toFloat();
}

inline QString qtPropString(const Variant &node, const char *key, const QString &fallback = {}) {
    const Variant v = node.toArray().get(key);
    return v.isUndef() || v.isNull() ? fallback : toQString(v);
}

/** 把 "center" / "left|vcenter" 之类的对齐串翻译成 Qt 标志。 */
inline Qt::Alignment qtAlignOf(const QString &spec) {
    Qt::Alignment align;
    const QStringList parts = spec.split(QLatin1Char('|'), Qt::SkipEmptyParts);
    for (const QString &raw : parts) {
        const QString p = raw.trimmed().toLower();
        if (p == QLatin1String("left")) align |= Qt::AlignLeft;
        else if (p == QLatin1String("right")) align |= Qt::AlignRight;
        else if (p == QLatin1String("hcenter") || p == QLatin1String("center")) align |= Qt::AlignHCenter;
        else if (p == QLatin1String("top")) align |= Qt::AlignTop;
        else if (p == QLatin1String("bottom")) align |= Qt::AlignBottom;
        else if (p == QLatin1String("vcenter")) align |= Qt::AlignVCenter;
        else if (p == QLatin1String("justify")) align |= Qt::AlignJustify;
    }
    return align;
}

// ────────────────────────────── 前向声明 ──────────────────────────────

class QtWindowBox;

/** 按 type 造一个控件；返回 nullptr 表示类型未知。 */
QWidget *qtCreateWidget(QtWindowBox *box, const QString &type, const Variant &node);

/** 把节点上的一个属性应用到控件上。未知 key 静默忽略。 */
void qtApplyProp(QtWindowBox *box, QWidget *widget, const QString &type, const QString &key, const Variant &value);

/** 该 type 是否是容器（有自己的子布局/子页）。 */
bool qtIsContainer(const QString &type);

// ── webview（后端按编译期开关三选一）──
//   QT_WEBVIEW2   Windows + vendor 的 SDK  → cpp-src/qt_webview.cc 的 WebView2Widget
//   QT_WEBVIEW_WK macOS + 系统 WebKit      → cpp-src/qt_webview_wk.mm
//   都不定义                               → cpp-src/qt_webview.cc 的 QTextBrowser

/** 创建 webview 控件。 */
QWidget *qtCreateWebView(QtWindowBox *box, const QString &id);

/** 当前编译进去的后端名："webview2" / "wkwebview" / "textbrowser"。 */
const char *qtWebViewBackend();

/** 该后端是否支持 JavaScript（只有 QTextBrowser 不支持）。 */
bool qtWebViewSupportsJs();

/** 把 url / html / zoom 应用到 webview 控件（后端差异封装在两个实现文件里）。 */
void qtWebViewApplyProp(QWidget *widget, const QString &key, const Variant &value);

/**
 * webview 的命令式动作：reload / goBack / goForward。
 *
 * 与属性分开是因为它们不是状态 —— 没有「当前是否正在重载」这种属性可写，
 * 只是一次性动作，所以走 qt_window_patch 的 call 通道。
 * QTextBrowser 后端没有导航栈，静默忽略（与未知属性一致）。
 */
void qtWebViewCall(QWidget *widget, const QString &method);

/** WKWebView 后端的对应实现，只在 QT_WEBVIEW_WK 下编译进链接（见 qt_webview_wk.mm）。 */
QWidget *qtCreateWebViewWK(QtWindowBox *box, const QString &id);
void qtWebViewApplyPropWK(QWidget *widget, const QString &key, const Variant &value);
void qtWebViewCallWK(QWidget *widget, const QString &method);

/** 读取控件当前值，供 qt_window_widget_value() 使用。 */
Variant qtWidgetValue(QWidget *widget, const QString &type);

/** 容器里承载子控件的布局；tabs/stack/split/scroll 返回 nullptr（它们自己管）。 */
QLayout *qtContainerLayout(QWidget *container);

/** 整表重建（列/行/行 id），需要在节点上下文里调用。 */
void qtRebuildTable(QtWindowBox *box, QTableWidget *table, const Variant &node);

/** 往表格尾部追加行（不清空、不动已有行的选中）。`rowIds` 可为 undef。 */
void qtAppendTableRows(QTableWidget *table, const Variant &rows, const Variant &rowIds);

/** 清空控件内容：表格去行、树/列表/下拉去条目、文本类置空。 */
void qtClearContent(QWidget *widget, const QString &type);

/** 整树重建（nodes），需要在节点上下文里调用。 */
void qtRebuildTree(QtWindowBox *box, QTreeWidget *tree, const Variant &node);

// ────────────────────────────── 应用单例 ──────────────────────────────

inline int qt_argc = 1;
inline char qt_program_name[] = "typephp-qt-app";
inline char *qt_argv[] = {qt_program_name, nullptr};
inline QApplication *qt_application = nullptr;

// ────────────────────────────── 子槽位 ──────────────────────────────

/** 一个子节点在父容器里的落位，用于判断布局是否需要重建。 */
struct ChildSlot {
    QWidget *widget = nullptr;   // nullptr 表示 spacer
    int row = 0;
    int col = 0;
    int rowSpan = 1;
    int colSpan = 1;
    int grow = 0;
    int spacerSize = 0;
    Qt::Orientation spacerOrient = Qt::Horizontal;

    bool operator==(const ChildSlot &o) const {
        return widget == o.widget && row == o.row && col == o.col && rowSpan == o.rowSpan
               && colSpan == o.colSpan && grow == o.grow && spacerSize == o.spacerSize
               && spacerOrient == o.spacerOrient;
    }
};

// ────────────────────────────── 窗口 Box ──────────────────────────────

/**
 * PHP 手里那个 mixed 就是这个 Box。它持有整棵 Qt 控件树、id→控件映射，
 * 以及待 PHP 取走的事件队列。
 *
 * 继承 php::Box 才能以 PHP resource 的形式交给 PHP 侧持有；PHP 释放时
 * 由 Box::destroy() 析构，进而删掉整棵 Qt 控件树。
 */
class QtWindowBox : public Box {
  public:
    QtWindowBox(const QString &title, const Array &options);
    ~QtWindowBox() override;

    bool isOpen() const;
    void processEvents();
    Array pollEvent();
    void close();
    void cleanup();
    bool snapshot(const QString &path);

    void render(const Array &tree);
    void patch(const Array &ops);
    Variant widgetValue(const QString &id);
    void setTimer(const QString &id, int intervalMs);

    void setTitle(const QString &title);
    void setMenu(const Array &items);
    void setStatus(const Array &segments);
    void setTray(const Array &spec);
    void notify(const QString &title, const QString &message);

    QString messageBox(const Array &spec);
    Array fileDialog(const Array &spec);
    QString directoryDialog(const Array &spec);

    void showError(const QString &message);

    /** 所有信号处理都只调它。 */
    void enqueue(const QString &type, const QString &id = {}, const QString &value = {}, const Array &payload = {});

    QMainWindow *window() const { return window_; }
    QWidget *central() const { return central_; }
    QVBoxLayout *rootLayout() const { return rootLayout_; }
    QString typeById(const QString &id) const { return types_.value(id); }

  private:
    QWidget *buildNode(const Variant &node, QWidget *parentWidget, const QString &parentType,
                       QList<ChildSlot> &siblings, const QString &path);
    QWidget *ensureWidget(const Variant &node, const QString &type, const QString &id);
    void applyNodeProps(QWidget *widget, const QString &type, const QString &id, const Variant &node);
    /**
     * tabs/stack 的 `current` 必须**延后**到子页建好之后再应用。
     *
     * buildNode 的顺序是 ensureWidget → applyNodeProps → 递归子节点，
     * 所以 applyNodeProps 里应用 current 时 QTabWidget 还是空的 ——
     * setCurrentIndex(1) 会被 Qt 钳回 0，首帧的声明式切页静默失效。
     * 这里在 syncChildren 之后补一次，语义仍是「只在变化时应用」，
     * 免得每帧覆盖用户的手动切页。
     */
    void applyDeferredCurrent(QWidget *widget, const QString &type, const QString &id, const Variant &node);
    /** 表格/树的结构字段是否变了（变了才整表/整树重建）。 */
    bool structuralChanged(const QString &id, const Variant &node, const QString &type);
    /** 摘掉这些键的签名，让下一次 render 必然重新应用（命令式 call 改过控件后用）。 */
    void forgetProps(const QString &id, const QStringList &keys);
    void syncChildren(QWidget *container, const QString &type, const QList<ChildSlot> &desired);
    void forgetSubtree(QWidget *root);
    void removeStale();

    QMainWindow *window_ = nullptr;
    QWidget *central_ = nullptr;
    QVBoxLayout *rootLayout_ = nullptr;

    QHash<QString, QWidget *> widgets_;                     // id -> 控件
    QHash<QString, QString> types_;                         // id -> 节点类型
    QHash<QString, QHash<QString, QString>> propSigs_;      // id -> key -> 上次签名
    QHash<QWidget *, QList<ChildSlot>> slots_;              // 容器 -> 上一次的子槽位
    QHash<QWidget *, QString> nodeTitles_;                  // 控件 -> 节点 title（tab 页签用）
    QHash<QWidget *, QString> nodeLabels_;                  // 控件 -> 节点 label（form 行标用）
    QHash<QString, QTimer *> timers_;                       // 定时器 id -> QTimer
    QList<QWidget *> statusWidgets_;                         // 状态栏上我们自己加的标签
    QSystemTrayIcon *tray_ = nullptr;
    QMenu *trayMenu_ = nullptr;                             // 托盘右键菜单（没设过就是 nullptr）
    QSet<QString> seen_;                                    // 本帧见过的 id
    std::deque<Array> events_;
};
