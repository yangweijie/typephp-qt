// TypePHP\Qt — 桥接共享头。
//
// 只放声明与 inline 工具函数；实现分在两个翻译单元里，用来验证 tpc 能编译
// cpp-src/ 下的多个 .cc 并正确链接：
//   qt_bridge.cc   应用/窗口/事件循环/渲染遍历/装饰与对话框
//   qt_widgets.cc  控件工厂与属性应用（控件目录）
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

/** 读取控件当前值，供 qt_window_widget_value() 使用。 */
Variant qtWidgetValue(QWidget *widget, const QString &type);

/** 容器里承载子控件的布局；tabs/stack/split/scroll 返回 nullptr（它们自己管）。 */
QLayout *qtContainerLayout(QWidget *container);

/** 整表重建（列/行/行 id），需要在节点上下文里调用。 */
void qtRebuildTable(QtWindowBox *box, QTableWidget *table, const Variant &node);

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
    QSet<QString> seen_;                                    // 本帧见过的 id
    std::deque<Array> events_;
};
