// TypePHP\Qt — qml 控件（QQuickWidget 嵌入现有控件树）。
//
// 编译期开关 QT_QML（照 qt_webview.cc 的 QT_WEBVIEW2 / QT_WEBVIEW_WK 模式）：
//
//   * 定义了 QT_QML → QQuickWidget 加载 .qml 组件；root 的信号按名连进
//     既有事件队列（type='qml'，value=信号名，payload 是 read 声明属性的快照）；
//     动态属性经 setProperty/property 读写（invokeMethod 按名只找槽/Q_INVOKABLE，
//     属性读写必须走 property 通道，F51 §51.4 实测）。
//   * 未定义 → 降级占位：控件退化成 QLabel（写清「QML 未启用 + 该 id + 怎么开」），
//     不静默白屏；available()=false、status() 报 error，动作与属性读回全部空实现。
//
// 为什么文件无条件进 sources：与 webview 同构——不定义 QT_QML 时普通应用
// （hello 保持 QML-free）只是多编译一个空 TU，链接、行为零影响；
// 全部对外 php_ 符号两种形态都存在，`qtphp lint` 与普通应用零影响。
//
// QML 内部结构由 .qml 文件自己组织（叶子语义，F51 D6）：节点上的 children
// 被静默忽略，不从 PHP 树里种 Qt Quick 节点。

#include "qt_common.h"

#include <QFrame>
#include <QLabel>
#include <QMetaMethod>
#include <QObject>
#include <QUrl>
#include <QVariant>

#include <functional>

#ifdef QT_QML
#include <QPushButton>
#include <QQmlError>
#include <QQmlEngine>
#include <QQuickItem>
#include <QQuickWidget>
#include <QSet>
#endif

// ────────────────────────────── 降级占位（QT_QML 未定义时） ──────────────────────────────

namespace {

/** 把 Variant 转成 QML 能用的 QVariant；标量直转，复杂类型退成字符串。 */
QVariant toQmlVariant(const Variant &value) {
    if (value.isUndef() || value.isNull()) return QVariant();
    if (value.isString()) return QString::fromUtf8(value.toCString());
    if (value.isBool()) return value.toBool();
    if (value.isInt()) return static_cast<int>(value.toInt());
    if (value.isFloat()) return static_cast<double>(value.toFloat());
    return toQString(value);
}

/** QVariant 读回 → Variant（边界只稳定承载标量，与 enqueue 的 value=QString 同口径）。
 * 用 QMetaType::typeId 判定类型，避开 Qt6 里 QVariant::Type 枚举的弃用。 */
Variant fromQmlVariant(const QVariant &value) {
    const int typeId = value.typeId();
    if (typeId == QMetaType::QString) return toPhpString(value.toString());
    if (typeId == QMetaType::Bool) return Variant(value.toBool());
    if (typeId == QMetaType::Int) return Variant(static_cast<Int>(value.toInt()));
    if (typeId == QMetaType::Double) return Variant(static_cast<Float>(value.toDouble()));
    if (value.isValid()) return toPhpString(value.toString());  // 枚举/自定义类型 → 字符串
    return {};
}

/** 把关联数组 Variant 转成 QStringList（键转字符串值列表）。 */
QStringList toKeyList(const Variant &value) {
    QStringList out;
    if (!value.isArray()) return out;
    const Array array = value.toArray();
    for (size_t i = 0; i < array.count(); ++i) out << toQString(array.get(i));
    return out;
}

/**
 * 降级控件：一块 QLabel，写清「QML 未启用、该控件的 id、怎么开」。
 *
 * 不静默的原因（F51 D3）：QML 加载失败的白屏 + 零诊断是最难查的一类缺陷；
 * 占位至少让应用与用户在界面上看到「这里本该有 qml 组件」。
 * 文案带固定前缀 "[QML 未启用]"，无头验收可以断言它。
 */
QWidget *qtCreateQmlPlaceholder(QtWindowBox *box, const QString &id) {
    Q_UNUSED(box);
    auto *label = new QLabel();
    label->setText(QStringLiteral(
        "[QML 未启用]\n控件 %1 需要构建时定义 QT_QML\n"
        "（QtQml/QtQuick/QtQuickWidgets）")
                       .arg(id));
    label->setAlignment(Qt::AlignCenter);
    label->setFrameShape(QFrame::StyledPanel);
    label->setWordWrap(true);
    return label;
}

}  // namespace

// ────────────────────────────── QT_QML 后端 ──────────────────────────────

#ifdef QT_QML

namespace {

/**
 * 进程级共享 QQmlEngine（懒加载，以 qApp 为 parent）。
 *
 * QQmlEngine 的类型注册/import 编译缓存按 engine 分家，每个控件各建一个会
 * 重复工作；照 qt_webview.cc 的「WebView2 环境单例」先例（:81-86）做成
 * 函数局部 static。以 qApp 为 parent：静态对象的析构可能晚于 QApplication，
 * 挂 parent 让它先于 app 全局收尾（qt_loop.cc:101-102 的 cleanup 幂等先例同源）。
 */
QQmlEngine &qmlEngine() {
    static QQmlEngine engine(qt_application);
    return engine;
}

/**
 * 信号中转：QML root 的无参信号 → 事件队列（type='qml'，value=信号名）。
 *
 * 连接链（本机 Qt 6.12.0 实测，/tmp/qmlhook/probe1-3）：
 *   * QObject::connect **没有**「QMetaMethod 信号 + functor」公开重载
 *     （qobject.h:212-228 逐条实查：QMetaMethod 版只有 4 参配对形式），
 *     所以接收方必须给一个 metaobject 里的真实槽；
 *   * 接收方用 **QPushButton::click()** —— Qt 自带的 moc 槽，调用即同步
 *     emit clicked()，指针版 connect 接 functor（不需要 moc）再把事件入队；
 *     按钮从不显示（hide()，防父窗口 show 时连带显示盖住 QML 内容），
 *     click() 内部的 repaint() 对隐藏控件是 no-op，零副作用；
 *     clicked 的 bool checked 实参在 functor 里丢弃（无参信号语义）。
 *   * 为什么不用手搭 metaobject：初版 QmlSignalForwarder 手工拼 QMetaObject
 *     字节表，connect 报「Incompatible sender/receiver arguments」——
 *     当时误诊为「Qt 6.12 不支持手工 metaobject」（旧注释），真因是**表拼错**：
 *     注释自称 d.data 头 14 uint、methodData=14，数组却只写 12 个头 uint，
 *     Qt 按 14 读方法条目，读到 name=0x14/argc=2 的错位值，签名校验必挂。
 *     手搭表还要求逐字段匹配所链 Qt 的 moc 布局（6.8 起 QtMocHelpers 换过
 *     一代），跨版本是雷区，整个删掉（教训 42）。
 *   * 为什么不用 QObject::deleteLater() 当接收槽：DeferredDelete 的投递
 *     受「等事件循环返回」语义抑制，probe2 实测 1.2s（23 次 deleteLater）
 *     只投出第 1 次 —— 拦得到第一次、拦不到后续。
 *   * 实测读数（probe3）：hits=23 == QML 侧触发 23，同步零丢失、首拍即达；
 *     同四元组连两次 hits=2×23 —— **Qt 对重复连接不去重**，靠 wired_ 挡。
 *   * 无参信号经 0 参槽中转；带参信号 v1 不支持（记 error 不静默，D4）。
 */

/**
 * QmlWidget：QQuickWidget 子类。root 对象、加载状态、错误列表、信号连接
 * 全收在控件自己身上（WebView2Widget 先例：成员不进 QtWindowBox，控件销毁一起走）。
 *
 * 关键行为（评审探针 probe2 实测钉死，F51 §51.7）：
 *   * setSource **同帧** rootObject() 同步可得（className 立刻是 QQuickRectangle_QML_0）
 *     → 立即接线；等 statusChanged(Ready) 再连 = 死等（offscreen 下 Ready 从不触发，
 *     实测 1.5s 恒 0 事件）。
 *   * 键应用顺序 source 先于 signals/props/read（applyProp 内固定序）。
 *   * root 为 null 时 connectSignal 直接返回、不消费签名 —— 下一帧 diff 自动重试，
 *     否则首帧错过接线且永不补。
 *   * 信号经 QPushButton::click() 真实 moc 槽同步中转（机制见 relay 注释）；
 *     带参信号 v1 不支持（记 error 不静默）。
 *   * offscreen 下组件 status 可能停在 loading 或跳到 ready（本机 6.12 实测
 *     ready、F51 探针 6.12.0_1 实测 loading），状态照记、供 qt_qml_status()
 *     信息性读出，**不进无头判据**。
 */
class QmlWidget : public QQuickWidget {
  public:
    QmlWidget(QtWindowBox *box, QString id)
        // 直接用「带 engine + parent 的构造函数」把共享 engine 交给 QQuickWidget：
        // setEngine 在 Qt6 不是 QQuickWidget 的公开 API（engine 只能在构造时定），
        // 共享 engine 的诉求（import 编译缓存、类型注册）照旧成立。
        // parent 用 box_->window()：控件销毁时 engine 的引用一起清。
        : QQuickWidget(&qmlEngine(), box->window()), box_(box), id_(std::move(id)) {
        // QQuickWidget 的画面在独立渲染器里、不贡献 sizeHint，
        // 不给 Expanding 外层布局会把它压成 0 高（与 WebView2Widget 同理由）。
        setSizePolicy(QSizePolicy::Expanding, QSizePolicy::Expanding);
        setResizeMode(QQuickWidget::SizeRootObjectToView);

        QObject::connect(this, &QQuickWidget::statusChanged, this, [this](QQuickWidget::Status status) {
            status_ = status == QQuickWidget::Ready
                         ? QStringLiteral("ready")
                         : (status == QQuickWidget::Error ? QStringLiteral("error")
                                                           : QStringLiteral("loading"));
        });

        // 组件（重）实例化会换 root 对象：reload 的时间线探针实测，setSource
        // 同帧创建的实例会在事件循环后续被 QQuickWidget 内部再实例化一次
        // （+20ms root 指针更换、props 回落默认值）——所以每次状态变化都作废
        // props 的 diff 签名，让 diff 把 props 重放到「当前最新」实例上。
        // props 写入不触发 statusChanged ⇒ 重放收敛，无循环。首次加载的
        // Loading/Ready 也走这里，作废一次未记录的签名是无害 no-op。
        QObject::connect(this, &QQuickWidget::statusChanged, this, [this](QQuickWidget::Status) {
            box_->forgetProps(id_, {QStringLiteral("props")});
        });

        // 加载失败 → 收集诊断，**不静默**（qt_qml_status 存在的理由，F51 D3）。
        // QQuickWidget 没有 errorOccurred 信号，错误经 errors()（QList<QQmlError>）
        // 读出；这里在 status 变 Error 时把列表快照进 errors_，保留给 status()。
        QObject::connect(this, &QQuickWidget::statusChanged, this, [this](QQuickWidget::Status status) {
            if (status != QQuickWidget::Error) return;
            // QQmlError 的 description 用 toString() 拼（没有单独的 message 读口）。
            for (const QQmlError &qerr : qmlErrors()) {
                Array error;
                error.set("line", Variant(static_cast<Int>(qerr.line())));
                error.set("message", toPhpString(qerr.toString()));
                errors_.append(error);
            }
        });
    }

    /**
     * 加载 .qml。相对路径经 qtResolvePath（相对 exe 目录，与 webview 的
     * url 解析同一套契约），带 scheme 的（file:// 等）原样用。
     * 基类 QQuickWidget::setSource(QUrl) 与成员 source_ 重名，
     * 这里用「基类限定调用 + 先存后设」拆开，避免成员遮蔽的歧义。
     */
    void loadSource(const QString &raw) {
        if (raw.trimmed().isEmpty()) return;
        source_ = raw;
        sourceSet_ = true;
        // 新 root 代次：已接线清单作废。不能只靠 root 指针变化判断 ——
        // delete 旧 root 后立即重建，分配器可能返回同一地址。
        wired_.clear();
        wiredRoot_ = nullptr;
        const int colon = raw.indexOf(QLatin1Char(':'));
        if (colon < 0 || colon > 1) {
            QQuickWidget::setSource(QUrl::fromLocalFile(qtResolvePath(raw)));
        } else {
            QQuickWidget::setSource(QUrl(raw));
        }
        if (status_.isEmpty()) status_ = QStringLiteral("loading");
        wireDeclaredSignals();  // 同帧接线（probe2 结论 2）
    }

    /**
     * 按名连一个 root 信号 → 事件队列（type='qml'，value=信号名，
     * payload=read 快照，F51 D4/D5）。连接机制见 relay 注释（QPushButton
     * click() 跳板；/tmp/qmlhook/probe1-3 实测）。
     *
     * 信号名不带参数表（如 "tick"）时 indexOfSignal 找不到（metaobject 存
     * 带签名的方法名 "tick()"），先试原样、再试补 "()"（probe 实测）。
     */
    void connectSignal(const QString &name) {
        QQuickItem *root = rootObject();
        if (!root) return;  // root 未落地：不记错、不消费（调用方靠 diff 重试）
        const QByteArray nameUtf8 = name.toUtf8();
        int index = root->metaObject()->indexOfSignal(nameUtf8);
        if (index < 0 && !name.contains(QLatin1Char('('))) {
            index = root->metaObject()->indexOfSignal(nameUtf8 + "()");
        }
        if (index < 0) {
            recordError(0, QStringLiteral("QML signal '%1' not found on root object").arg(name));
            return;
        }
        const QMetaMethod signal = root->metaObject()->method(index);
        if (signal.parameterCount() > 0) {
            recordError(0, QStringLiteral("QML signal '%1' has parameters; v1 wires argumentless signals only")
                            .arg(name));
            return;
        }
        // Qt 对重复连接**不去重**（probe3 实测：同四元组连两次，每拍触发 2 次），
        // 所以按「root 代次 + 信号名」自己挡：reload/重设 source 换 root 后
        // wired_ 由 loadSource 清空，允许在新 root 上重连。
        if (wiredRoot_ != root) {
            wired_.clear();
            wiredRoot_ = root;
        }
        if (wired_.contains(name)) return;
        // 跳板按钮按信号名复用（随控件生死）；连接挂在 (root, signal, relay,
        // clickSlot) 上 —— root 或 relay 任一销毁连接自动失效。
        auto it = relays_.find(name);
        if (it == relays_.end()) {
            auto *relay = new QPushButton(this);
            relay->setFocusPolicy(Qt::NoFocus);
            relay->hide();  // 防父窗口 show 时被连带显示，盖在 QML 内容上
            QObject::connect(relay, &QPushButton::clicked, this,
                             [this, name](bool) { emitEvent(name); });
            relays_.insert(name, relay);
            it = relays_.find(name);
        }
        QPushButton *relay = it.value();
        const QMetaMethod clickSlot =
            relay->metaObject()->method(relay->metaObject()->indexOfSlot("click()"));
        QMetaObject::Connection conn = QObject::connect(root, signal, relay, clickSlot);
        if (conn == QMetaObject::Connection()) {
            recordError(0, QStringLiteral("failed to connect QML signal '%1' to event queue").arg(name));
            return;
        }
        wired_.insert(name);
    }

    void setSignals(const QStringList &names) {
        declaredSignals_ = names;
        wireDeclaredSignals();
    }

    /** root 的动态属性写（QQuickItem 的动态 metaobject 支持，probe2 实测）。
     *  参数 name 是 QString，QObject::setProperty 只收 const char*，
     *  用 toUtf8().constData() 转。 */
    void setProp(const QString &name, const QVariant &value) {
        if (auto *root = rootObject()) {
            const QByteArray utf8 = name.toUtf8();
            root->setProperty(utf8.constData(), value);
        }
    }

    QVariant getProp(const QString &name) const {
        // QQuickItem 的头在两种形态下都可用（QT_QML 下是完整类型，
        // 未定义时是前置声明），property() 在两种形态下都能调。
        const QQuickItem *root = rootObject();
        if (!root) return QVariant();
        const QByteArray utf8 = name.toUtf8();
        return root->property(utf8.constData());
    }

    void setReadKeys(const QStringList &names) { readKeys_ = names; }

    QStringList readKeysList() const { return readKeys_; }

    bool hasRoot() const { return rootObject() != nullptr; }

    /**
     * 按键应用一个 qml 属性（source/signals/props/read），供 qtQmlApplyProp 调用。
     * 在 public 段 —— qtQmlApplyProp 在 namespace 作用域里调用它。
     *
     * 返回 true = 已生效（调用方记 diff 签名）；false = root 尚未落地、
     * **别记签名**，下一帧 diff 自动重试（signals 在 source 未落地时应用，
     * 否则首帧错过接线且永不补 —— F51 §51.7 探针结论 2 的配套规则 ②）。
     * 键的调用顺序由调用方保证：source 先于 signals/props/read。
     */
    bool applyKey(const QString &key, const Variant &value) {
        if (key == QLatin1String("source")) {
            loadSource(toQString(value));
            return true;
        }
        if (key == QLatin1String("signals")) {
            setSignals(toKeyList(value));
            return hasRoot();
        }
        if (key == QLatin1String("props")) {
            // root 未落地时必须返回 false（签名不记、diff 自动重试）：
            // 早先无条件 return true，首帧 root 还没建就把签名记了，props
            // 静默丢失且永不重试——qmlclock 的 sizeFactor 读回 1.0（QML 默认）
            // 而树里写的是 1.25，正是被这条缺陷固化出来的错误判据（F53 §3）。
            if (!hasRoot()) return false;
            const Array props = value.toArray();
            for (auto it = props.begin(); it != props.end(); ++it) {
                setProp(toQString(it.key()), toQmlVariant(it.value()));
            }
            return true;
        }
        if (key == QLatin1String("read")) {
            setReadKeys(toKeyList(value));
            return true;
        }
        return false;  // 未知键：由调用方（qtApplyProp）走 strict 告警路径
    }

    /**
     * reload：重新加载 source。setSource 同 URL 会被 QQuickWidget 判成
     * no-op（内部比较），所以先 destroy 旧 root 再加载。
     *
     * 注意：delete rootObject() 会触发 QQuickWidget 内部的 reset 路径，
     * 把 root 清成空 —— 下一次 setSource 会重新 createRoot。
     * 挂在 root 上的连接（context=root 的 QMetaMethod 连接）随 root 析构失效；
     * 错误列表保留，reload 前后都可通过 qt_qml_status 读。
     */
    void reload() {
        if (!sourceSet_) return;
        // 新 root 是白板：props 的 diff 签名还记着「已应用」，不作废的话下一帧
        // 不会重写，新 root 静默回落 QML 默认值（实测：树里 speed=2、根里读回 1
        // ——F46「属性应用早于结构落地」家族的 reload 变体，正控制先红后修）。
        // signals 已由 loadSource 的 wired_ 代次清空自行重连；read 无状态。
        box_->forgetProps(id_, {QStringLiteral("props")});
        // delete rootObject() 触发 QQuickWidget 内部的 reset 路径
        // （QQuickWidget::setSource 内部检测 rootObject()==null 时重建 root）。
        if (QQuickItem *oldRoot = rootObject()) {
            delete oldRoot;
        }
        loadSource(source_);
    }

    QString statusName() const { return status_.isEmpty() ? QStringLiteral("none") : status_; }
    /** 读组件错误列表（与 qt_qml_status 的对外读口对应）。 */
    QList<Array> errorList() const { return errors_; }

    /** 取基类 QQuickWidget 的组件错误列表（QList<QQmlError>）。
     *  成员 errors_（QList<Array>）遮蔽了它，要访问基类版本必须显式限定。 */
    QList<QQmlError> qmlErrors() const { return QQuickWidget::errors(); }

  private:
    void recordError(int line, const QString &message) {
        Array error;
        error.set("line", Variant(static_cast<Int>(line)));
        error.set("message", toPhpString(message));
        errors_.append(error);
        status_ = QStringLiteral("error");
        if (qtStrictMode()) {
            qWarning("[qt] qml: id '%s': %s", qPrintable(id_), qPrintable(message));
        }
    }

    void wireDeclaredSignals() {
        for (const QString &name : declaredSignals_) {
            connectSignal(name);
        }
    }

    /** 信号触发：入队 qml 事件，payload 是 read 声明属性的**当前快照**（D4）。
     *  键名是 QString，Array::set 只收 const char*，用 toUtf8().constData() 转。 */
    void emitEvent(const QString &signalName) {
        Array payload;
        for (const QString &key : readKeys_) {
            const QByteArray keyUtf8 = key.toUtf8();
            payload.set(keyUtf8.constData(), fromQmlVariant(getProp(key)));
        }
        box_->enqueue(QStringLiteral("qml"), id_, signalName, payload);
    }

    QtWindowBox *box_ = nullptr;
    QString id_;
    QString source_;
    bool sourceSet_ = false;
    QString status_;
    QStringList declaredSignals_;
    QStringList readKeys_;
    QHash<QString, QPushButton *> relays_;  // 每个信号名一个跳板按钮（随控件生死）
    QQuickItem *wiredRoot_ = nullptr;       // 已接线信号所属的 root 代次（reload 换 root 后强制重连）
    QSet<QString> wired_;                   // 当前 root 上已接线的信号名（Qt 不去重重复连接，probe3）
    QList<Array> errors_;  // 与基类 QQuickWidget::errors() 重名：成员优先，
                           // 基类版本（QList<QQmlError>）在 statusChanged 槽里显式调用。

    friend bool qtQmlApplyProp(QWidget *, const QString &, const Variant &);
};

}  // namespace

/** qml 控件的读回值：没有「当前值」单语义，value() 返回 read 属性快照。 */
Variant qtQmlWidgetValueImpl(QWidget *widget) {
    auto *qml = dynamic_cast<QmlWidget *>(widget);
    if (!qml) return {};
    Array out;
    for (const QString &key : qml->readKeysList()) {
        const QByteArray keyUtf8 = key.toUtf8();
        out.set(keyUtf8.constData(), fromQmlVariant(qml->getProp(key)));
    }
    return out;
}

/** qml 的命令式动作（目前只有 reload）。 */
void qtQmlCallImpl(QWidget *widget, const QString &method) {
    auto *qml = dynamic_cast<QmlWidget *>(widget);
    if (!qml) return;
    if (method == QLatin1String("reload")) qml->reload();
}

/** qt_qml_status() 的 C++ 侧：读组件状态与错误列表；非 qml 控件返回 {}。 */
Array qtQmlStatusImpl(QWidget *widget) {
    auto *qml = dynamic_cast<QmlWidget *>(widget);
    if (!qml) return {};
    Array out;
    out.set("status", toPhpString(qml->statusName()));
    Array errors;
    const QList<Array> raw = qml->errorList();
    for (size_t i = 0; i < raw.size(); ++i) errors.set(static_cast<Int>(i), raw.at(i));
    out.set("errors", errors);
    return out;
}

#endif  // QT_QML

// ────────────────────────────── 统一入口（对外符号两种形态都存在） ──────────────────────────────

/**
 * 创建 qml 控件。QT_QML 下是 QQuickWidget；未定义时是**可诊断占位**
 * （Label 写清缺什么）—— 不静默白屏（D3）。
 */
QWidget *qtCreateQml(QtWindowBox *box, const QString &id) {
#ifdef QT_QML
    return new QmlWidget(box, id);
#else
    return qtCreateQmlPlaceholder(box, id);
#endif
}

/** qml 控件的读回值（read 属性快照；无 read 声明时为空对象）。 */
Variant qtQmlWidgetValue(QWidget *widget) {
#ifdef QT_QML
    return qtQmlWidgetValueImpl(widget);
#else
    Q_UNUSED(widget);
    return {};
#endif
}

/**
 * 把 source / signals / props / read 应用到 qml 控件（type='qml' 专用）。
 *
 * 返回 true = 已生效（调用方记 diff 签名）；false = root 尚未落地、**别记签名**，
 * 下一帧 diff 自动重试（QT_QML 下见 QmlWidget::applyKey 的注释；
 * 未定义时恒返回 true，占位没有需要重试的状态，strict 下打警告）。
 * 非 qml 控件不会走到这里（调用方按 type 分派）。
 */
bool qtQmlApplyProp(QtWindowBox *box, QWidget *widget, const QString &key, const Variant &value) {
    Q_UNUSED(box);
#ifdef QT_QML
    auto *qml = dynamic_cast<QmlWidget *>(widget);
    if (!qml) return true;
    return qml->applyKey(key, value);
#else
    Q_UNUSED(widget);
    Q_UNUSED(key);
    Q_UNUSED(value);
    if (qtStrictMode()) {
        qWarning("[qt] qml: QT_QML not enabled at build time, property ignored");
    }
    return true;
#endif
}

/** qml 的命令式动作（reload）。QT_QML 未定义时静默忽略（strict 告警）。 */
void qtQmlCall(QWidget *widget, const QString &method) {
#ifdef QT_QML
    qtQmlCallImpl(widget, method);
#else
    Q_UNUSED(method);
    if (qtStrictMode()) {
        qWarning("[qt] qml: QT_QML not defined at build time, call '%s' ignored",
                 qPrintable(method));
    }
#endif
}

/**
 * 读组件状态（'none'|'loading'|'ready'|'error' + errors 列表）；
 * 非 qml 控件返回 {}。QT_QML 未定义时恒报 error + 一条「未启用」诊断 ——
 * 这是 D3 的错误通道，不是「没启用」的静默状态。
 */
Array qtQmlStatus(QWidget *widget) {
#ifdef QT_QML
    return qtQmlStatusImpl(widget);
#else
    Q_UNUSED(widget);
    Array out;
    out.set("status", toPhpString(QStringLiteral("error")));
    Array errors;
    Array error;
    error.set("line", Variant(static_cast<Int>(0)));
    error.set("message", toPhpString(QStringLiteral("QT_QML not defined at build time")));
    errors.set(static_cast<Int>(0), error);
    out.set("errors", errors);
    return out;
#endif
}

/**
 * 按窗口 + 控件 id 读 qml 状态（php_qt_qml_status 的桥接）：
 * QtWindowBox 不对外暴露 id→widget 表（只有类型表），
 * 所以走 widgetById() 取指针 + typeById() 判类型；
 * 找不到 / 非 qml 类型 → 返回 {}（与 php_qt_window_find 同语义）。
 */
Array qtQmlStatusByBox(QtWindowBox *box, const QString &id) {
    if (!box) return {};
    QWidget *widget = box->widgetById(id);
    if (!widget) return {};
    if (box->typeById(id) != QLatin1String("qml")) return {};
    return qtQmlStatus(widget);
}

// ────────────────────────────── php_qt_* 包装 ──────────────────────────────

/** 编译时是否启用了 QML —— 应用据此走降级分支（对应 stub 的 qt_qml_available）。 */
Bool php_qt_qml_available() {
#ifdef QT_QML
    return true;
#else
    return false;
#endif
}

/**
 * 读一个 qml 控件的组件状态（对应 stub 的 qt_qml_status）：
 * ['status'=>…, 'errors'=>…]；控件不存在或不是 qml 类型时返回 {}。
 */
Array php_qt_qml_status(Variant box, String id) {
    return qtQmlStatusByBox(boxFrom(box), toQString(id));
}
