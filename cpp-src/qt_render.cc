// TypePHP\Qt — 渲染遍历（diff）、patch、布局同步。
//
// 渲染策略：按节点 id 做差异更新。
//   * 新 id → 创建控件
//   * 已有 id → 只刷签名变化的属性（propSigs_ 记上次签名）
//   * 消失的 id → 销毁控件
// 因此输入框光标、表格选中、滚动位置在重渲染后保持。

#include "qt_common.h"

#include <QAbstractScrollArea>
#include <QElapsedTimer>
#include <QPointer>
#include <QScrollArea>
#include <QScrollBar>
#include <QSizePolicy>
#include <QSpacerItem>

namespace {

/** 判断 widget 是否是 root 的后代（含自身）。 */
bool isDescendantOf(QWidget *widget, QWidget *root) {
    if (!widget || !root) return false;
    for (QWidget *w = widget; w != nullptr; w = w->parentWidget()) {
        if (w == root) return true;
    }
    return false;
}

}  // namespace

// ────────────────────────────── 渲染遍历 ──────────────────────────────

void QtWindowBox::render(const Array &tree) {
    // QT_DEBUG_TIMING=1：把每帧的遍历耗时打到 stderr。
    // 大表格卡顿时第一件要确认的事是「慢在 PHP 构造树还是慢在 C++ 应用差异」，
    // 没有这个数就只能靠猜；默认关闭，因为每帧一次 stderr 写本身也要钱。
    static const bool timing = qEnvironmentVariableIsSet("QT_DEBUG_TIMING");
    QElapsedTimer timer;
    if (timing) timer.start();

    seen_.clear();
    QList<ChildSlot> siblings;
    buildNode(Variant(tree), central_, QStringLiteral("window"), siblings, QStringLiteral("0"));
    syncChildren(central_, QStringLiteral("window"), siblings);
    removeStale();

    if (timing) {
        qInfo("[qt] render %lld ms (%d ids)", static_cast<long long>(timer.elapsed()), widgets_.size());
    }
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
    //
    // 但「结构路径」只解决了帧内稳定，没解决**跨帧稳定**：前面的兄弟数量一变
    // （多一个站点就多一行），后面那个无 id 节点的路径就变，于是它每帧被重建、
    // 旧控件从布局里脱落 —— 症状是"页面越长越容易看到空白/闪烁"，而不是崩溃，
    // 所以很难归因。结论：**动态容器里的孩子必须给显式 id**（应用侧的尾巴 stretch
    // 就是这么处理的）；这里不静默兜底，是因为兜底也救不了控件内部状态（焦点、
    // 选中项、滚动位置）—— 那些才是被丢掉的东西。
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

    // 重型重建：必须在 applyNodeProps **之前**。
    // 顺序反过来的话，节点上的 current 先选中一行，紧接着 setRowCount(0)/clear()
    // 就把选中抹了 —— 「表格选中重渲染后保留」这条承诺会整个失效。
    if ((type == QLatin1String("table") || type == QLatin1String("tree"))
        && structuralChanged(id, spec, type)) {
        if (auto *table = qobject_cast<QTableWidget *>(widget)) qtRebuildTable(this, table, spec);
        else if (auto *tree = qobject_cast<QTreeWidget *>(widget)) qtRebuildTree(this, tree, spec);
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

    // tabs/stack 的 current 只能在子页建好之后应用 —— applyNodeProps 那一步
    // QTabWidget 还是空的，setCurrentIndex(1) 会被钳回 0。
    applyDeferredCurrent(widget, type, id, spec);

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
    QHash<QString, quint64> &sigs = propSigs_[id];

    for (auto it = spec.begin(); it != spec.end(); ++it) {
        const QString key = toQString(it.key());
        if (key == QLatin1String("id") || key == QLatin1String("type") || key == QLatin1String("children")) {
            continue;
        }
        if (qtIsStructuralKey(type, key)) {
            continue;  // 由 structuralChanged()/重建逻辑处理
        }
        // tabs/stack 的 current 延后到子页建好之后（见 applyDeferredCurrent）。
        // 这里跳过，是为了不让签名在「还没生效」的时候就被记下来 ——
        // 否则下一帧签名未变、补应用也被跳过，切页永远停在 0。
        if (key == QLatin1String("current")
            && (type == QLatin1String("tabs") || type == QLatin1String("stack"))) {
            continue;
        }
        const Variant value = it.value();
        const quint64 sig = qtSignature(value);
        if (sigs.value(key) == sig) continue;  // 没变，跳过
        sigs.insert(key, sig);
        qtApplyProp(this, widget, type, key, value);
    }
}

void QtWindowBox::applyDeferredCurrent(QWidget *widget, const QString &type, const QString &id,
                                       const Variant &node) {
    if (!widget) return;
    if (type != QLatin1String("tabs") && type != QLatin1String("stack")) return;

    const Array spec = node.toArray();
    const Variant value = spec.get("current");
    if (value.isUndef() || value.isNull()) return;

    QHash<QString, quint64> &sigs = propSigs_[id];
    const quint64 sig = qtSignature(value);
    if (sigs.value(QStringLiteral("current")) == sig) return;  // 没变，别覆盖用户的手动切页
    sigs.insert(QStringLiteral("current"), sig);
    qtApplyProp(this, widget, type, QStringLiteral("current"), value);
}

/**
 * 表格/树的结构字段是否变了（变了才值得整表重建，并把新签名记进 propSigs_）。
 *
 * 结构签名与普通属性签名共用 propSigs_[id]：applyNodeProps() 会跳过结构字段，
 * 所以两边不会互相覆盖。
 */
bool QtWindowBox::structuralChanged(const QString &id, const Variant &node, const QString &type) {
    const Array spec = node.toArray();
    QHash<QString, quint64> &sigs = propSigs_[id];
    bool changed = false;
    for (const QString &key : qtStructuralKeys(type)) {
        const QByteArray name = key.toUtf8();
        const quint64 sig = qtSignature(spec.get(name.constData()));
        if (sigs.value(key) == sig) continue;
        sigs.insert(key, sig);
        changed = true;
    }
    return changed;
}

void QtWindowBox::forgetProps(const QString &id, const QStringList &keys) {
    auto it = propSigs_.find(id);
    if (it == propSigs_.end()) return;
    for (const QString &key : keys) it.value().remove(key);
}

void QtWindowBox::syncChildren(QWidget *container, const QString &type, const QList<ChildSlot> &desired) {
    if (!container) return;
    const QList<ChildSlot> &previous = slots_[container];
    if (previous == desired) return;  // 没变，不动布局

    // 把一条槽位落进容器。全量重建和追加快路径共用，免得两处逻辑漂移。
    auto addSlot = [&](const ChildSlot &slot) {
        if (!slot.widget) {
            if (slot.spacerSize <= 0) return;
            if (auto *layout = qtContainerLayout(container)) {
                auto *box = qobject_cast<QBoxLayout *>(layout);
                const bool vertical = box
                    && (box->direction() == QBoxLayout::TopToBottom
                        || box->direction() == QBoxLayout::BottomToTop);
                if (box && slot.spacerSize <= 1) {
                    // 文档写的是 `spacer(1)` = 弹性、吃掉剩余空间，那实现就必须是 stretch 项。
                    // 以前它落到 `QSpacerItem(size,0,Fixed,Minimum)` + `addItem`（不带 stretch），
                    // 于是两个方向上都"什么都不占"，说好的"把邻居推开"根本没发生 ——
                    // 应用侧只能改用 `label('', {grow:1})`，而那条还必须有显式 id。
                    box->addStretch(slot.spacerSize);
                } else if (vertical) {
                    // 固定间隔要跟着盒子方向：竖向盒子里宽度固定、高度只到 Minimum 等于没占位。
                    layout->addItem(new QSpacerItem(0, slot.spacerSize, QSizePolicy::Minimum, QSizePolicy::Fixed));
                } else {
                    layout->addItem(new QSpacerItem(slot.spacerSize, 0, QSizePolicy::Fixed, QSizePolicy::Minimum));
                }
            }
            return;
        }
        QWidget *child = slot.widget;

        if (auto *tabs = qobject_cast<QTabWidget *>(container)) {
            tabs->addTab(child, nodeTitles_.value(child, QStringLiteral("页")));
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
    };

    // 滚动页的内容面板尾巴有一条 stretch（见函数末尾），追加会落到 stretch 之后，
    // 所以这类容器只能走全量重建。
    QWidget *viewport = container->parentWidget();
    QScrollArea *scroll = viewport ? qobject_cast<QScrollArea *>(viewport->parentWidget()) : nullptr;

    // 追加型快路径：旧槽位恰好是新槽位的前缀时，只 append 多出来的那几个。
    // 日志、聊天、消息流这类"只往尾巴加"的界面每帧都会命中这里；走全量重建的话
    // 所有已有控件都要 setParent(nullptr) 再塞回去 —— 滚动条被布局压回 0、
    // 正在播的动画重启、输入框失焦，表现为"每来一条新消息页面就跳回顶部"。
    if (!scroll && desired.size() > previous.size()) {
        bool prefix = true;
        for (int i = 0; i < previous.size(); ++i) {
            if (!(previous.at(i) == desired.at(i))) {
                prefix = false;
                break;
            }
        }
        if (prefix) {
            for (int i = previous.size(); i < desired.size(); ++i) addSlot(desired.at(i));
            slots_[container] = desired;
            return;
        }
    }

    // 全量重建会把控件摘出再塞回，期间布局收缩到 0 会让滚动条范围塌陷、
    // 值被钳成 0；焦点也会因为控件短暂变成顶层窗口而丢失。
    // 这两样都是"重渲染后用户位置还在"的一部分，所以先存后恢复。
    QWidget *focused = QApplication::focusWidget();
    QList<QPair<QPointer<QScrollBar>, int>> scrollState;
    for (QScrollBar *bar : container->findChildren<QScrollBar *>()) {
        scrollState.append({QPointer<QScrollBar>(bar), bar->value()});
    }

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

    for (const ChildSlot &slot : desired) addSlot(slot);

    // 滚动页的竖向 slack 必须有个去处：scroll 的内容面板是 `setWidgetResizable(true)`，
    // 内容高度**至少等于视口**，而这一层的孩子 grow 全为 0 时 QBoxLayout 会把多出来的高度
    // 平均分给每一个 —— 窗口一放大，卡片之间就长出大片空白（下游把它当成排版缺陷查过一轮）。
    // 尾巴上加一条 stretch 把 slack 全吃掉，等价于每个应用自己手写 `label('', {grow:1})`，
    // 但这条不该每个应用都被提醒一遍。只对 scroll 的内容面板做：普通 vbox 里"孩子被拉高"
    // 有时正是应用要的填充。
    if (scroll && !desired.isEmpty()) {
        if (auto *box = qobject_cast<QBoxLayout *>(qtContainerLayout(container))) box->addStretch(1);
    }

    for (const auto &entry : scrollState) {
        if (entry.first) entry.first->setValue(entry.second);
    }
    if (focused && isDescendantOf(focused, container)) focused->setFocus();

    slots_[container] = desired;
}

void QtWindowBox::forgetSubtree(QWidget *root) {
    if (!root) return;
    // 边遍历边删：单趟扫描即可，不必先收集 id 再回头逐条查三张表。
    for (auto it = widgets_.begin(); it != widgets_.end();) {
        if (it.value() == root || isDescendantOf(it.value(), root)) {
            types_.remove(it.key());
            propSigs_.remove(it.key());
            it = widgets_.erase(it);
        } else {
            ++it;
        }
    }
    slots_.remove(root);
    nodeTitles_.remove(root);
    nodeLabels_.remove(root);
}

void QtWindowBox::removeStale() {
    for (auto it = widgets_.begin(); it != widgets_.end();) {
        if (seen_.contains(it.key())) {
            ++it;
            continue;
        }
        QWidget *widget = it.value();
        types_.remove(it.key());
        propSigs_.remove(it.key());
        slots_.remove(widget);
        nodeTitles_.remove(widget);
        nodeLabels_.remove(widget);
        it = widgets_.erase(it);
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
        if (!op.isArray()) {
            if (qtStrictMode()) qWarning("[qt] patch: op #%d is not an array, ignored", static_cast<int>(i));
            continue;
        }
        const Array spec = op.toArray();
        const QString kind = toQString(spec.get("op"));
        const QString id = toQString(spec.get("id"));
        QWidget *widget = widgets_.value(id);
        if (!widget) {
            if (qtStrictMode()) qWarning("[qt] patch: unknown id '%s', ignored", qPrintable(id));
            continue;
        }

        if (kind == QLatin1String("set")) {
            const Variant props = spec.get("props");
            if (!props.isArray()) {
                if (qtStrictMode()) qWarning("[qt] patch: 'props' of '%s' is not an array, ignored", qPrintable(id));
                continue;
            }
            const Array propArray = props.toArray();
            const QString type = types_.value(id);

            // 结构字段先重建，再应用其余属性 —— 与 render 路径同一套顺序。
            // 结构数据在 ops 的 `props` 里，不在 op 本身，早先传 spec 等于把表清空。
            if ((type == QLatin1String("table") || type == QLatin1String("tree"))
                && structuralChanged(id, props, type)) {
                if (auto *table = qobject_cast<QTableWidget *>(widget)) qtRebuildTable(this, table, props);
                else if (auto *tree = qobject_cast<QTreeWidget *>(widget)) qtRebuildTree(this, tree, props);
            }

            QHash<QString, quint64> &sigs = propSigs_[id];
            for (auto it = propArray.begin(); it != propArray.end(); ++it) {
                const QString key = toQString(it.key());
                if (qtIsStructuralKey(type, key)) continue;
                const Variant value = it.value();
                const quint64 sig = qtSignature(value);
                if (sigs.value(key) == sig) continue;
                sigs.insert(key, sig);
                qtApplyProp(this, widget, type, key, value);
            }
        } else if (kind == QLatin1String("call")) {
            const QString method = toQString(spec.get("method"));
            const Variant argsValue = spec.get("args");
            const Array args = argsValue.isArray() ? argsValue.toArray() : Array();
            const QString type = types_.value(id);

            // 命令式旁路：改完之后把对应键的签名摘掉，下一次 render 必然以树为准重新同步。
            // 不摘就会出事后「签名说没变、控件其实变了」——表格被清空却没人重建它。
            if (method == QLatin1String("appendRows")) {
                if (auto *table = qobject_cast<QTableWidget *>(widget)) {
                    qtAppendTableRows(table, args.get(0), args.get(1));
                    forgetProps(id, qtStructuralKeys(type));
                }
            } else if (method == QLatin1String("clear")) {
                qtClearContent(widget, type);
                QStringList keys = qtStructuralKeys(type);
                keys << QStringLiteral("items") << QStringLiteral("text") << QStringLiteral("value");
                forgetProps(id, keys);
            } else if (method == QLatin1String("setText")) {
                qtApplyProp(this, widget, type, QStringLiteral("text"), args.get(0));
                forgetProps(id, {QStringLiteral("text")});
            } else if (method == QLatin1String("setValue")) {
                if (qobject_cast<QLineEdit *>(widget) || qobject_cast<QTextEdit *>(widget)) {
                    qtApplyProp(this, widget, type, QStringLiteral("text"), args.get(0));
                    forgetProps(id, {QStringLiteral("text"), QStringLiteral("value")});
                } else {
                    qtApplyProp(this, widget, type, QStringLiteral("value"), args.get(0));
                    forgetProps(id, {QStringLiteral("value")});
                }
            } else if (method == QLatin1String("select")) {
                // 与声明式的 `current` 同一套语义（table/tree 按 id，list/tabs 按索引）。
                qtApplyProp(this, widget, type, QStringLiteral("current"), args.get(0));
                forgetProps(id, {QStringLiteral("current")});
            } else if (method == QLatin1String("focus")) {
                widget->setFocus();
            } else if (type == QLatin1String("webview")
                       && (method == QLatin1String("reload")
                           || method == QLatin1String("goBack")
                           || method == QLatin1String("goForward"))) {
                // webview 的三个命令式动作。它们不改属性，所以不必 forgetProps。
                // QTextBrowser 后端没有导航栈，qtWebViewCall 内部静默忽略。
                qtWebViewCall(widget, method);
            } else if (qtStrictMode()) {
                qWarning("[qt] patch: unknown method '%s' on '%s' (%s), ignored",
                         qPrintable(method), qPrintable(id), qPrintable(type));
            }
        } else if (qtStrictMode()) {
            qWarning("[qt] patch: unknown op '%s', ignored", qPrintable(kind));
        }
    }
}
