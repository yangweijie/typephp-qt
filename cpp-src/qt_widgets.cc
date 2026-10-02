// TypePHP\Qt — 控件目录实现。
//
// 这里只有"造控件"和"把 PHP 数组里的属性刷到控件上"两件事，没有任何业务逻辑。
// 每个信号处理都只调 box->enqueue()，把"用户干了什么"交给 PHP 决定。

#include "qt_common.h"

#include <QTextOption>
#include <QTreeWidgetItemIterator>

#include <limits>

namespace {

QStringList toStringList(const Variant &value) {
    QStringList out;
    if (!value.isArray()) return out;
    const Array array = value.toArray();
    for (size_t i = 0; i < array.count(); ++i) out << toQString(array.get(i));
    return out;
}

QList<int> toIntList(const Variant &value) {
    QList<int> out;
    if (!value.isArray()) return out;
    const Array array = value.toArray();
    for (size_t i = 0; i < array.count(); ++i) out << static_cast<int>(array.get(i).toInt());
    return out;
}

/** items 既接受 ['a','b'] 也接受 [['id'=>..,'text'=>..], ...]。 */
void parseItems(const Variant &value, QStringList &texts, QStringList &ids) {
    if (!value.isArray()) return;
    const Array array = value.toArray();
    for (size_t i = 0; i < array.count(); ++i) {
        const Variant item = array.get(i);
        if (item.isArray()) {
            const Array a = item.toArray();
            texts << toQString(a.get("text"));
            ids << toQString(a.get("id"));
        } else {
            const QString text = toQString(item);
            texts << text;
            ids << text;
        }
    }
}

QSize parseSize(const Variant &value) {
    if (!value.isArray()) return {};
    const Array a = value.toArray();
    const int w = a.count() > 0 ? static_cast<int>(a.get(static_cast<size_t>(0)).toInt()) : 0;
    const int h = a.count() > 1 ? static_cast<int>(a.get(static_cast<size_t>(1)).toInt()) : 0;
    return QSize(w, h);
}

Qt::Orientation orientationOf(const QString &spec, Qt::Orientation fallback = Qt::Horizontal) {
    const QString s = spec.trimmed().toLower();
    if (s == QLatin1String("v") || s == QLatin1String("vertical")) return Qt::Vertical;
    if (s == QLatin1String("h") || s == QLatin1String("horizontal")) return Qt::Horizontal;
    return fallback;
}

void setReadOnly(QWidget *widget, bool readOnly) {
    if (auto *edit = qobject_cast<QLineEdit *>(widget)) edit->setReadOnly(readOnly);
    else if (auto *text = qobject_cast<QTextEdit *>(widget)) text->setReadOnly(readOnly);
    else if (auto *spin = qobject_cast<QSpinBox *>(widget)) spin->setReadOnly(readOnly);
    else if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) dspin->setReadOnly(readOnly);
}

void setPlaceholder(QWidget *widget, const QString &text) {
    if (auto *edit = qobject_cast<QLineEdit *>(widget)) edit->setPlaceholderText(text);
    else if (auto *text2 = qobject_cast<QTextEdit *>(widget)) text2->setPlaceholderText(text);
    else if (auto *combo = qobject_cast<QComboBox *>(widget)) combo->setPlaceholderText(text);
}

void setTextValue(QWidget *widget, const QString &text) {
    if (auto *label = qobject_cast<QLabel *>(widget)) {
        if (!label->pixmap().isNull()) label->setPixmap(QPixmap());
        label->setText(text);
    } else if (auto *button = qobject_cast<QPushButton *>(widget)) button->setText(text);
    else if (auto *edit = qobject_cast<QLineEdit *>(widget)) {
        if (edit->text() != text) edit->setText(text);
    } else if (auto *text2 = qobject_cast<QTextEdit *>(widget)) {
        if (text2->toPlainText() != text) text2->setPlainText(text);
    } else if (auto *check = qobject_cast<QCheckBox *>(widget)) check->setText(text);
    else if (auto *radio = qobject_cast<QRadioButton *>(widget)) radio->setText(text);
}

}  // namespace

// ────────────────────────────── 容器判定 ──────────────────────────────

bool qtIsContainer(const QString &type) {
    static const QSet<QString> containers = {
        QStringLiteral("vbox"), QStringLiteral("hbox"),   QStringLiteral("grid"),
        QStringLiteral("form"), QStringLiteral("group"),  QStringLiteral("frame"),
        QStringLiteral("scroll"), QStringLiteral("tabs"), QStringLiteral("tab"),
        QStringLiteral("stack"), QStringLiteral("page"),  QStringLiteral("split"),
    };
    return containers.contains(type);
}

QLayout *qtContainerLayout(QWidget *container) {
    if (!container) return nullptr;
    if (auto *scroll = qobject_cast<QScrollArea *>(container)) {
        return scroll->widget() ? scroll->widget()->layout() : nullptr;
    }
    return container->layout();
}

// ────────────────────────────── 控件工厂 ──────────────────────────────

QWidget *qtCreateWidget(QtWindowBox *box, const QString &type, const Variant &node) {
    const QString id = qtPropString(node, "id");
    QObject *ctx = box->window();

    // ── 容器 ──
    if (type == QLatin1String("vbox")) {
        auto *widget = new QWidget();
        new QVBoxLayout(widget);
        return widget;
    }
    if (type == QLatin1String("hbox")) {
        auto *widget = new QWidget();
        new QHBoxLayout(widget);
        return widget;
    }
    if (type == QLatin1String("grid")) {
        auto *widget = new QWidget();
        new QGridLayout(widget);
        return widget;
    }
    if (type == QLatin1String("form")) {
        auto *widget = new QWidget();
        new QFormLayout(widget);
        return widget;
    }
    if (type == QLatin1String("group")) {
        auto *group = new QGroupBox();
        new QVBoxLayout(group);
        // checkable group 勾掉时 Qt 会把内容置灰，状态变化要能上报
        QObject::connect(group, &QGroupBox::toggled, ctx, [box, id](bool on) {
            box->enqueue(QStringLiteral("toggle"), id, on ? QStringLiteral("1") : QStringLiteral("0"));
        });
        return group;
    }
    if (type == QLatin1String("frame")) {
        auto *frame = new QFrame();
        frame->setFrameShape(QFrame::StyledPanel);
        new QVBoxLayout(frame);
        return frame;
    }
    if (type == QLatin1String("scroll")) {
        auto *area = new QScrollArea();
        area->setWidgetResizable(true);
        auto *content = new QWidget();
        new QVBoxLayout(content);
        area->setWidget(content);
        return area;
    }
    if (type == QLatin1String("tabs")) {
        auto *tabs = new QTabWidget();
        QObject::connect(tabs, &QTabWidget::currentChanged, ctx, [box, id](int index) {
            Array payload;
            payload.set("index", Variant(static_cast<Int>(index)));
            box->enqueue(QStringLiteral("tab"), id, QString::number(index), payload);
        });
        // 标签页上的关闭按钮（closable => true）被点：只上报，是否真关由 PHP 决定
        // （状态驱动 —— 直接关掉会和下一帧的视图打架）。
        QObject::connect(tabs, &QTabWidget::tabCloseRequested, ctx, [box, id](int index) {
            Array payload;
            payload.set("index", Variant(static_cast<Int>(index)));
            box->enqueue(QStringLiteral("close"), id, QString::number(index), payload);
        });
        return tabs;
    }
    if (type == QLatin1String("tab") || type == QLatin1String("page")) {
        auto *page = new QWidget();
        new QVBoxLayout(page);
        return page;
    }
    if (type == QLatin1String("stack")) {
        auto *stack = new QStackedWidget();
        QObject::connect(stack, &QStackedWidget::currentChanged, ctx, [box, id](int index) {
            Array payload;
            payload.set("index", Variant(static_cast<Int>(index)));
            box->enqueue(QStringLiteral("tab"), id, QString::number(index), payload);
        });
        return stack;
    }
    if (type == QLatin1String("split")) {
        return new QSplitter(Qt::Horizontal);
    }
    if (type == QLatin1String("separator")) {
        auto *line = new QFrame();
        line->setFrameShape(QFrame::HLine);
        line->setFrameShadow(QFrame::Sunken);
        return line;
    }

    // ── 控件 ──
    if (type == QLatin1String("label")) {
        auto *label = new QLabel();
        label->setTextInteractionFlags(Qt::NoTextInteraction);
        return label;
    }
    if (type == QLatin1String("image")) {
        auto *label = new QLabel();
        label->setAlignment(Qt::AlignCenter);
        return label;
    }
    if (type == QLatin1String("link")) {
        auto *label = new QLabel();
        label->setCursor(Qt::PointingHandCursor);
        label->setTextInteractionFlags(Qt::LinksAccessibleByMouse);
        label->setOpenExternalLinks(false);
        QObject::connect(label, &QLabel::linkActivated, ctx, [box, id](const QString &href) {
            box->enqueue(QStringLiteral("click"), id, href);
        });
        return label;
    }
    if (type == QLatin1String("button")) {
        auto *button = new QPushButton();
        button->setCursor(Qt::PointingHandCursor);
        QObject::connect(button, &QPushButton::clicked, ctx,
                         [box, id]() { box->enqueue(QStringLiteral("click"), id); });
        // checkable 按钮（以及 group）还要发 toggle —— 否则 'checkable' => true
        // 只是个能按下去的装饰，用户拿不到状态变化。
        QObject::connect(button, &QPushButton::toggled, ctx, [box, id](bool on) {
            box->enqueue(QStringLiteral("toggle"), id, on ? QStringLiteral("1") : QStringLiteral("0"));
        });
        QObject::connect(button, &QPushButton::pressed, ctx,
                         [box, id]() { box->enqueue(QStringLiteral("press"), id); });
        QObject::connect(button, &QPushButton::released, ctx,
                         [box, id]() { box->enqueue(QStringLiteral("release"), id); });
        return button;
    }
    if (type == QLatin1String("lineedit")) {
        auto *edit = new QLineEdit();
        QObject::connect(edit, &QLineEdit::textChanged, ctx,
                         [box, id](const QString &text) { box->enqueue(QStringLiteral("change"), id, text); });
        QObject::connect(edit, &QLineEdit::returnPressed, ctx,
                         [box, id, edit]() { box->enqueue(QStringLiteral("submit"), id, edit->text()); });
        // 失焦（或回车）表示「这一格编辑完了」—— 表单场景常用它触发校验/保存，
        // 与每敲一个字都发的 change 区分开。
        QObject::connect(edit, &QLineEdit::editingFinished, ctx,
                         [box, id, edit]() { box->enqueue(QStringLiteral("commit"), id, edit->text()); });
        return edit;
    }
    if (type == QLatin1String("textedit")) {
        auto *edit = new QTextEdit();
        QObject::connect(edit, &QTextEdit::textChanged, ctx,
                         [box, id, edit]() { box->enqueue(QStringLiteral("change"), id, edit->toPlainText()); });
        return edit;
    }
    if (type == QLatin1String("spin")) {
        auto *spin = new QSpinBox();
        spin->setRange(std::numeric_limits<int>::min(), std::numeric_limits<int>::max());
        QObject::connect(spin, &QSpinBox::valueChanged, ctx, [box, id](int value) {
            box->enqueue(QStringLiteral("change"), id, QString::number(value));
        });
        return spin;
    }
    if (type == QLatin1String("doublespin")) {
        auto *spin = new QDoubleSpinBox();
        spin->setRange(-1.0e18, 1.0e18);
        QObject::connect(spin, &QDoubleSpinBox::valueChanged, ctx, [box, id](double value) {
            box->enqueue(QStringLiteral("change"), id, QString::number(value));
        });
        return spin;
    }
    if (type == QLatin1String("slider")) {
        auto *slider = new QSlider(Qt::Horizontal);
        slider->setRange(0, 100);
        QObject::connect(slider, &QSlider::valueChanged, ctx, [box, id](int value) {
            box->enqueue(QStringLiteral("change"), id, QString::number(value));
        });
        return slider;
    }
    if (type == QLatin1String("progress")) {
        auto *bar = new QProgressBar();
        bar->setRange(0, 100);
        bar->setTextVisible(true);
        return bar;
    }
    if (type == QLatin1String("checkbox")) {
        auto *check = new QCheckBox();
        QObject::connect(check, &QCheckBox::toggled, ctx, [box, id](bool on) {
            box->enqueue(QStringLiteral("toggle"), id, on ? QStringLiteral("1") : QStringLiteral("0"));
        });
        return check;
    }
    if (type == QLatin1String("radio")) {
        auto *radio = new QRadioButton();
        QObject::connect(radio, &QRadioButton::toggled, ctx, [box, id](bool on) {
            box->enqueue(QStringLiteral("toggle"), id, on ? QStringLiteral("1") : QStringLiteral("0"));
        });
        return radio;
    }
    if (type == QLatin1String("combo")) {
        auto *combo = new QComboBox();
        QObject::connect(combo, &QComboBox::currentIndexChanged, ctx, [box, id, combo](int index) {
            Array payload;
            payload.set("index", Variant(static_cast<Int>(index)));
            box->enqueue(QStringLiteral("change"), id, combo->currentText(), payload);
        });
        // 可编辑下拉（editable => true）里手打的文本也要上报，
        // 否则用户输的内容拿不到。
        QObject::connect(combo, &QComboBox::editTextChanged, ctx,
                         [box, id](const QString &text) { box->enqueue(QStringLiteral("change"), id, text); });
        return combo;
    }
    if (type == QLatin1String("list")) {
        auto *list = new QListWidget();
        QObject::connect(list, &QListWidget::itemSelectionChanged, ctx, [box, id, list]() {
            auto *item = list->currentItem();
            Array payload;
            payload.set("index", Variant(static_cast<Int>(list->currentRow())));
            box->enqueue(QStringLiteral("select"), id, item ? item->data(Qt::UserRole).toString() : QString(),
                         payload);
        });
        QObject::connect(list, &QListWidget::itemDoubleClicked, ctx, [box, id](QListWidgetItem *item) {
            box->enqueue(QStringLiteral("activate"), id, item->data(Qt::UserRole).toString());
        });
        // 单击：select 只在「选中项变化」时发；点同一项再点一次不会发 select，
        // 但 itemClicked 会发 —— 需要「每次点击都响应」的场景用它。
        QObject::connect(list, &QListWidget::itemClicked, ctx, [box, id](QListWidgetItem *item) {
            box->enqueue(QStringLiteral("itemClick"), id, item->data(Qt::UserRole).toString());
        });
        return list;
    }
    if (type == QLatin1String("table")) {
        auto *table = new QTableWidget(0, 1);
        table->setSelectionBehavior(QAbstractItemView::SelectRows);
        table->setSelectionMode(QAbstractItemView::SingleSelection);
        table->setEditTriggers(QAbstractItemView::NoEditTriggers);
        table->verticalHeader()->hide();
        table->horizontalHeader()->setStretchLastSection(true);
        QObject::connect(table, &QTableWidget::itemSelectionChanged, ctx, [box, id, table]() {
            const int row = table->currentRow();
            if (row < 0) return;
            auto *first = table->item(row, 0);
            if (!first) return;
            Array payload;
            payload.set("row", Variant(static_cast<Int>(row)));
            box->enqueue(QStringLiteral("select"), id, first->data(Qt::UserRole).toString(), payload);
        });
        QObject::connect(table, &QTableWidget::itemDoubleClicked, ctx, [box, id](QTableWidgetItem *item) {
            box->enqueue(QStringLiteral("activate"), id, item->data(Qt::UserRole).toString());
        });
        // 单元格被就地编辑：editable 表格要靠它把改动收回状态。
        // payload 带 row / col，value 是新文本。
        QObject::connect(table, &QTableWidget::cellChanged, ctx, [box, id, table](int row, int col) {
            auto *cell = table->item(row, col);
            Array payload;
            payload.set("row", Variant(static_cast<Int>(row)));
            payload.set("col", Variant(static_cast<Int>(col)));
            box->enqueue(QStringLiteral("cell"), id, cell ? cell->text() : QString(), payload);
        });
        return table;
    }
    if (type == QLatin1String("tree")) {
        auto *tree = new QTreeWidget();
        tree->setColumnCount(1);
        QObject::connect(tree, &QTreeWidget::itemSelectionChanged, ctx, [box, id, tree]() {
            auto *item = tree->currentItem();
            if (!item) return;
            box->enqueue(QStringLiteral("select"), id, item->data(0, Qt::UserRole).toString());
        });
        QObject::connect(tree, &QTreeWidget::itemDoubleClicked, ctx, [box, id](QTreeWidgetItem *item, int) {
            box->enqueue(QStringLiteral("activate"), id, item->data(0, Qt::UserRole).toString());
        });
        // 展开/折叠：懒加载子节点、记住展开状态都要靠它。
        // value 是节点 id，payload.expanded 给最终状态（省得自己判方向）。
        QObject::connect(tree, &QTreeWidget::itemExpanded, ctx, [box, id](QTreeWidgetItem *item) {
            Array payload;
            payload.set("expanded", Variant(true));
            box->enqueue(QStringLiteral("expand"), id, item->data(0, Qt::UserRole).toString(), payload);
        });
        QObject::connect(tree, &QTreeWidget::itemCollapsed, ctx, [box, id](QTreeWidgetItem *item) {
            Array payload;
            payload.set("expanded", Variant(false));
            box->enqueue(QStringLiteral("collapse"), id, item->data(0, Qt::UserRole).toString(), payload);
        });
        return tree;
    }
    return nullptr;  // spacer 与未知类型
}

// ────────────────────────────── 重型重建（需要整个节点） ──────────────────────────────

void qtRebuildTable(QtWindowBox *box, QTableWidget *table, const Variant &node) {
    Q_UNUSED(box);
    const QStringList columns = toStringList(qtField(node, "columns"));
    const Variant rowsValue = qtField(node, "rows");
    const QStringList rowIds = toStringList(qtField(node, "row_ids"));

    QSignalBlocker blocker(table);
    if (!columns.isEmpty()) {
        table->setColumnCount(columns.size());
        table->setHorizontalHeaderLabels(columns);
    }
    // 没给 rows 就不动内容：早先这里是无条件 setRowCount(0)，
    // 于是一次只改标题的补丁会把整张表连选中一起清空。
    if (!rowsValue.isArray()) return;

    // 记住选中行的**行 id**，重建后按 id 找回。选中是控件状态，重渲染不该丢；
    // 按索引记则会在插行后错位到别的行上。
    QString selectedId;
    const int previousRow = table->currentRow();
    if (previousRow >= 0 && table->item(previousRow, 0) != nullptr) {
        selectedId = table->item(previousRow, 0)->data(Qt::UserRole).toString();
    }

    const Array rows = rowsValue.toArray();

    // 没给列名时列数由最宽的一行决定（构造时是 1 列，窄于行会丢单元格）。
    if (columns.isEmpty()) {
        int widest = 0;
        for (size_t r = 0; r < rows.count(); ++r) {
            const Variant rowValue = rows.get(r);
            const int cells = rowValue.isArray() ? static_cast<int>(rowValue.toArray().count()) : 1;
            if (cells > widest) widest = cells;
        }
        if (widest > table->columnCount()) table->setColumnCount(widest);
    }

    table->setRowCount(static_cast<int>(rows.count()));
    for (size_t r = 0; r < rows.count(); ++r) {
        const Variant rowValue = rows.get(r);
        const Array cells = rowValue.isArray() ? rowValue.toArray() : Array();
        const QString rowId =
            r < static_cast<size_t>(rowIds.size()) ? rowIds.at(static_cast<int>(r)) : QString::number(r);
        for (size_t c = 0; c < cells.count(); ++c) {
            auto *item = new QTableWidgetItem(toQString(cells.get(c)));
            if (c == 0) item->setData(Qt::UserRole, rowId);
            table->setItem(static_cast<int>(r), static_cast<int>(c), item);
        }
    }

    if (selectedId.isEmpty()) return;
    for (int r = 0; r < table->rowCount(); ++r) {
        auto *first = table->item(r, 0);
        if (first && first->data(Qt::UserRole).toString() == selectedId) {
            table->selectRow(r);
            return;
        }
    }
}

void qtRebuildTree(QtWindowBox *box, QTreeWidget *tree, const Variant &node) {
    const QStringList headers = toStringList(qtField(node, "headers"));
    const Variant nodesValue = qtField(node, "nodes");
    QSignalBlocker blocker(tree);
    if (!headers.isEmpty()) tree->setHeaderLabels(headers);
    if (!nodesValue.isArray()) return;  // 同表格：没给结构就别清空

    const QString selectedId =
        tree->currentItem() ? tree->currentItem()->data(0, Qt::UserRole).toString() : QString();

    tree->clear();

    // 递归建节点；lambda 里需要自引用，故用 std::function。
    std::function<void(QTreeWidgetItem *, const Variant &)> append = [&](QTreeWidgetItem *parent,
                                                                        const Variant &nodes) {
        if (!nodes.isArray()) return;
        const Array array = nodes.toArray();
        for (size_t i = 0; i < array.count(); ++i) {
            const Array spec = array.get(i).toArray();
            const QString text = toQString(spec.get("text"));
            const QString nodeId = toQString(spec.get("id"));
            auto *item = parent ? new QTreeWidgetItem(parent) : new QTreeWidgetItem(tree);
            item->setText(0, text);
            item->setData(0, Qt::UserRole, nodeId.isEmpty() ? text : nodeId);
            append(item, spec.get("children"));
            if (qtHasProp(Variant(spec), "expanded")) item->setExpanded(spec.get("expanded").toBool());
        }
    };
    append(nullptr, nodesValue);

    if (selectedId.isEmpty()) return;
    QTreeWidgetItemIterator it(tree);
    while (*it) {
        if ((*it)->data(0, Qt::UserRole).toString() == selectedId) {
            tree->setCurrentItem(*it);
            return;
        }
        ++it;
    }
    Q_UNUSED(box);
}

void qtAppendTableRows(QTableWidget *table, const Variant &rows, const Variant &rowIds) {
    if (!rows.isArray()) return;
    const Array list = rows.toArray();
    const QStringList ids = rowIds.isArray() ? toStringList(rowIds) : QStringList();

    QSignalBlocker blocker(table);
    for (size_t i = 0; i < list.count(); ++i) {
        const Variant rowValue = list.get(i);
        const Array cells = rowValue.isArray() ? rowValue.toArray() : Array();
        const int r = table->rowCount();
        const int cols = static_cast<int>(cells.count());
        // 追加的行可能比声明的列更宽（列名没给的情况），和整表重建一样放宽列数。
        if (cols > table->columnCount()) table->setColumnCount(cols);
        table->insertRow(r);
        const QString rowId =
            i < static_cast<size_t>(ids.size()) ? ids.at(static_cast<int>(i)) : QString::number(r);
        for (size_t c = 0; c < cells.count(); ++c) {
            auto *item = new QTableWidgetItem(toQString(cells.get(c)));
            if (c == 0) item->setData(Qt::UserRole, rowId);
            table->setItem(r, static_cast<int>(c), item);
        }
    }
}

void qtClearContent(QWidget *widget, const QString &type) {
    QSignalBlocker blocker(widget);
    if (type == QLatin1String("table")) {
        if (auto *table = qobject_cast<QTableWidget *>(widget)) table->setRowCount(0);
        return;
    }
    if (type == QLatin1String("tree")) {
        if (auto *tree = qobject_cast<QTreeWidget *>(widget)) tree->clear();
        return;
    }
    if (type == QLatin1String("list")) {
        if (auto *list = qobject_cast<QListWidget *>(widget)) list->clear();
        return;
    }
    if (type == QLatin1String("combo")) {
        if (auto *combo = qobject_cast<QComboBox *>(widget)) combo->clear();
        return;
    }
    setTextValue(widget, QString());
}

// ────────────────────────────── 属性应用 ──────────────────────────────

void qtApplyProp(QtWindowBox *box, QWidget *widget, const QString &type, const QString &key, const Variant &value) {
    Q_UNUSED(box);
    if (!widget) return;

    // ── 通用 ──
    if (key == QLatin1String("visible")) { widget->setVisible(value.toBool()); return; }
    if (key == QLatin1String("enabled")) { widget->setEnabled(value.toBool()); return; }
    if (key == QLatin1String("tooltip")) { widget->setToolTip(toQString(value)); return; }
    if (key == QLatin1String("style")) { widget->setStyleSheet(toQString(value)); return; }
    if (key == QLatin1String("size")) {
        const QSize size = parseSize(value);
        if (size.width() > 0 && size.height() > 0) widget->setFixedSize(size);
        else if (size.width() > 0) widget->setFixedWidth(size.width());
        else if (size.height() > 0) widget->setFixedHeight(size.height());
        return;
    }
    if (key == QLatin1String("min_size")) {
        const QSize size = parseSize(value);
        if (size.width() > 0) widget->setMinimumWidth(size.width());
        if (size.height() > 0) widget->setMinimumHeight(size.height());
        return;
    }
    if (key == QLatin1String("max_size")) {
        const QSize size = parseSize(value);
        if (size.width() > 0) widget->setMaximumWidth(size.width());
        if (size.height() > 0) widget->setMaximumHeight(size.height());
        return;
    }
    if (key == QLatin1String("align")) {
        const Qt::Alignment align = qtAlignOf(toQString(value));
        if (auto *label = qobject_cast<QLabel *>(widget)) label->setAlignment(align);
        else if (auto *edit = qobject_cast<QLineEdit *>(widget)) edit->setAlignment(align);
        else if (auto *text = qobject_cast<QTextEdit *>(widget)) text->setAlignment(align);
        return;
    }

    // ── 容器 ──
    if (key == QLatin1String("spacing") || key == QLatin1String("margin")) {
        // 布局可能挂在控件自己身上（vbox/hbox/grid/form 都是一个 QWidget + 一个 layout），
        // 也可能挂在它内部那个容器控件上（group/frame/scroll 的 layout 在子控件上）。
        QLayout *layout = widget->layout();
        if (!layout) return;

        if (key == QLatin1String("spacing")) {
            layout->setSpacing(static_cast<int>(value.toInt()));
        } else {
            // margin 支持两种写法：单个 int（四边同值），或 [上, 右, 下, 左] 四元组
            if (value.isArray()) {
                const Array margins = value.toArray();
                const int n = static_cast<int>(margins.count());
                const int top = n > 0 ? static_cast<int>(margins.get(0).toInt()) : 0;
                const int right = n > 1 ? static_cast<int>(margins.get(1).toInt()) : top;
                const int bottom = n > 2 ? static_cast<int>(margins.get(2).toInt()) : top;
                const int left = n > 3 ? static_cast<int>(margins.get(3).toInt()) : right;
                layout->setContentsMargins(left, top, right, bottom);
            } else {
                const int m = static_cast<int>(value.toInt());
                layout->setContentsMargins(m, m, m, m);
            }
        }
        return;
    }
    if (key == QLatin1String("title")) {
        if (auto *group = qobject_cast<QGroupBox *>(widget)) { group->setTitle(toQString(value)); return; }
        if (auto *tabs = qobject_cast<QTabWidget *>(widget->parentWidget())) {
            const int index = tabs->indexOf(widget);
            if (index >= 0) tabs->setTabText(index, toQString(value));
        }
        return;
    }
    if (key == QLatin1String("checkable")) {
        if (auto *group = qobject_cast<QGroupBox *>(widget)) group->setCheckable(value.toBool());
        else if (auto *button = qobject_cast<QPushButton *>(widget)) button->setCheckable(value.toBool());
        return;
    }
    if (key == QLatin1String("checked")) {
        if (auto *group = qobject_cast<QGroupBox *>(widget)) group->setChecked(value.toBool());
        else if (auto *check = qobject_cast<QCheckBox *>(widget)) check->setChecked(value.toBool());
        else if (auto *radio = qobject_cast<QRadioButton *>(widget)) radio->setChecked(value.toBool());
        else if (auto *button = qobject_cast<QPushButton *>(widget)) button->setChecked(value.toBool());
        return;
    }
    if (key == QLatin1String("current")) {
        if (auto *tabs = qobject_cast<QTabWidget *>(widget)) { tabs->setCurrentIndex(static_cast<int>(value.toInt())); return; }
        if (auto *stack = qobject_cast<QStackedWidget *>(widget)) { stack->setCurrentIndex(static_cast<int>(value.toInt())); return; }
        if (auto *combo = qobject_cast<QComboBox *>(widget)) {
            if (value.isInt()) combo->setCurrentIndex(static_cast<int>(value.toInt()));
            else combo->setCurrentIndex(combo->findText(toQString(value)));
            return;
        }
        if (auto *list = qobject_cast<QListWidget *>(widget)) {
            if (value.isInt()) list->setCurrentRow(static_cast<int>(value.toInt()));
            return;
        }
        if (auto *table = qobject_cast<QTableWidget *>(widget)) {
            const QString wanted = toQString(value);
            for (int r = 0; r < table->rowCount(); ++r) {
                if (table->item(r, 0) && table->item(r, 0)->data(Qt::UserRole).toString() == wanted) {
                    table->selectRow(r);
                    return;
                }
            }
            return;
        }
        if (auto *tree = qobject_cast<QTreeWidget *>(widget)) {
            const QString wanted = toQString(value);
            QTreeWidgetItemIterator it(tree);
            while (*it) {
                if ((*it)->data(0, Qt::UserRole).toString() == wanted) { tree->setCurrentItem(*it); return; }
                ++it;
            }
            return;
        }
        return;
    }
    if (key == QLatin1String("closable")) {
        if (auto *tabs = qobject_cast<QTabWidget *>(widget)) tabs->setTabsClosable(value.toBool());
        return;
    }
    if (key == QLatin1String("tab_position")) {
        if (auto *tabs = qobject_cast<QTabWidget *>(widget)) {
            const QString pos = toQString(value).toLower();
            if (pos == QLatin1String("bottom")) tabs->setTabPosition(QTabWidget::South);
            else if (pos == QLatin1String("left")) tabs->setTabPosition(QTabWidget::West);
            else if (pos == QLatin1String("right")) tabs->setTabPosition(QTabWidget::East);
            else tabs->setTabPosition(QTabWidget::North);
        }
        return;
    }
    if (key == QLatin1String("orientation")) {
        const Qt::Orientation orientation = orientationOf(toQString(value));
        if (auto *splitter = qobject_cast<QSplitter *>(widget)) { splitter->setOrientation(orientation); return; }
        if (auto *slider = qobject_cast<QSlider *>(widget)) { slider->setOrientation(orientation); return; }
        if (auto *bar = qobject_cast<QProgressBar *>(widget)) { bar->setOrientation(orientation); return; }
        if (type == QLatin1String("separator")) {
            if (auto *line = qobject_cast<QFrame *>(widget)) {
                line->setFrameShape(orientation == Qt::Vertical ? QFrame::VLine : QFrame::HLine);
            }
        }
        return;
    }
    if (key == QLatin1String("sizes")) {
        if (auto *splitter = qobject_cast<QSplitter *>(widget)) {
            const QList<int> sizes = toIntList(value);
            if (!sizes.isEmpty()) splitter->setSizes(sizes);
        }
        return;
    }
    if (key == QLatin1String("handle_width")) {
        if (auto *splitter = qobject_cast<QSplitter *>(widget)) {
            splitter->setHandleWidth(static_cast<int>(value.toInt()));
        }
        return;
    }
    if (key == QLatin1String("frame") && type == QLatin1String("frame")) {
        if (auto *frame = qobject_cast<QFrame *>(widget)) {
            frame->setFrameShape(value.toBool() ? QFrame::StyledPanel : QFrame::NoFrame);
        }
        return;
    }

    // ── 文本类 ──
    if (key == QLatin1String("text")) { setTextValue(widget, toQString(value)); return; }
    if (key == QLatin1String("href")) {
        if (auto *label = qobject_cast<QLabel *>(widget)) {
            const QString text = label->text();
            label->setText(QStringLiteral("<a href=\"%1\">%2</a>")
                               .arg(toQString(value), text.isEmpty() ? toQString(value) : text));
        }
        return;
    }
    if (key == QLatin1String("path")) {
        if (auto *label = qobject_cast<QLabel *>(widget)) {
            const QPixmap pixmap(qtResolvePath(toQString(value)));
            if (!pixmap.isNull()) label->setPixmap(pixmap);
        }
        return;
    }
    if (key == QLatin1String("scaled_size")) {
        if (auto *label = qobject_cast<QLabel *>(widget)) {
            const QSize target = parseSize(value);
            if (!label->pixmap().isNull() && target.isValid()) {
                label->setPixmap(label->pixmap().scaled(target, Qt::KeepAspectRatio, Qt::SmoothTransformation));
            }
        }
        return;
    }
    if (key == QLatin1String("wrap")) {
        if (auto *label = qobject_cast<QLabel *>(widget)) { label->setWordWrap(value.toBool()); return; }
        if (auto *text = qobject_cast<QTextEdit *>(widget)) {
            text->setWordWrapMode(value.toBool() ? QTextOption::WordWrap : QTextOption::NoWrap);
        }
        return;
    }
    if (key == QLatin1String("selectable")) {
        if (auto *label = qobject_cast<QLabel *>(widget)) {
            label->setTextInteractionFlags(value.toBool() ? Qt::TextSelectableByMouse : Qt::NoTextInteraction);
        }
        return;
    }
    if (key == QLatin1String("bold")) {
        QFont font = widget->font();
        font.setBold(value.toBool());
        widget->setFont(font);
        return;
    }
    if (key == QLatin1String("font_size")) {
        QFont font = widget->font();
        font.setPointSizeF(value.toFloat());
        widget->setFont(font);
        return;
    }

    // ── 按钮 ──
    if (key == QLatin1String("icon")) {
        if (auto *button = qobject_cast<QPushButton *>(widget)) button->setIcon(QIcon(qtResolvePath(toQString(value))));
        return;
    }
    if (key == QLatin1String("flat")) {
        if (auto *button = qobject_cast<QPushButton *>(widget)) button->setFlat(value.toBool());
        return;
    }
    if (key == QLatin1String("default")) {
        if (auto *button = qobject_cast<QPushButton *>(widget)) button->setDefault(value.toBool());
        return;
    }

    // ── 输入类 ──
    if (key == QLatin1String("placeholder")) { setPlaceholder(widget, toQString(value)); return; }
    if (key == QLatin1String("readonly")) { setReadOnly(widget, value.toBool()); return; }
    if (key == QLatin1String("password")) {
        if (auto *edit = qobject_cast<QLineEdit *>(widget)) {
            edit->setEchoMode(value.toBool() ? QLineEdit::Password : QLineEdit::Normal);
        }
        return;
    }
    if (key == QLatin1String("clear_button")) {
        if (auto *edit = qobject_cast<QLineEdit *>(widget)) edit->setClearButtonEnabled(value.toBool());
        return;
    }
    if (key == QLatin1String("max_length")) {
        if (auto *edit = qobject_cast<QLineEdit *>(widget)) edit->setMaxLength(static_cast<int>(value.toInt()));
        return;
    }
    if (key == QLatin1String("rows")) {
        if (auto *text = qobject_cast<QTextEdit *>(widget)) {
            text->setFixedHeight(widget->fontMetrics().lineSpacing() * static_cast<int>(value.toInt()) + 16);
        }
        return;
    }

    // ── 数值类 ──
    if (key == QLatin1String("value")) {
        if (auto *bar = qobject_cast<QProgressBar *>(widget)) { bar->setValue(static_cast<int>(value.toInt())); return; }
        if (auto *slider = qobject_cast<QSlider *>(widget)) { slider->setValue(static_cast<int>(value.toInt())); return; }
        if (auto *spin = qobject_cast<QSpinBox *>(widget)) { spin->setValue(static_cast<int>(value.toInt())); return; }
        if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) { dspin->setValue(value.toFloat()); return; }
        return;
    }
    if (key == QLatin1String("min")) {
        if (auto *bar = qobject_cast<QProgressBar *>(widget)) { bar->setMinimum(static_cast<int>(value.toInt())); return; }
        if (auto *slider = qobject_cast<QSlider *>(widget)) { slider->setMinimum(static_cast<int>(value.toInt())); return; }
        if (auto *spin = qobject_cast<QSpinBox *>(widget)) { spin->setMinimum(static_cast<int>(value.toInt())); return; }
        if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) { dspin->setMinimum(value.toFloat()); return; }
        return;
    }
    if (key == QLatin1String("max")) {
        if (auto *bar = qobject_cast<QProgressBar *>(widget)) { bar->setMaximum(static_cast<int>(value.toInt())); return; }
        if (auto *slider = qobject_cast<QSlider *>(widget)) { slider->setMaximum(static_cast<int>(value.toInt())); return; }
        if (auto *spin = qobject_cast<QSpinBox *>(widget)) { spin->setMaximum(static_cast<int>(value.toInt())); return; }
        if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) { dspin->setMaximum(value.toFloat()); return; }
        return;
    }
    if (key == QLatin1String("step")) {
        if (auto *spin = qobject_cast<QSpinBox *>(widget)) spin->setSingleStep(static_cast<int>(value.toInt()));
        else if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) dspin->setSingleStep(value.toFloat());
        return;
    }
    if (key == QLatin1String("decimals")) {
        if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) dspin->setDecimals(static_cast<int>(value.toInt()));
        return;
    }
    if (key == QLatin1String("prefix")) {
        if (auto *spin = qobject_cast<QSpinBox *>(widget)) spin->setPrefix(toQString(value));
        else if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) dspin->setPrefix(toQString(value));
        return;
    }
    if (key == QLatin1String("suffix")) {
        if (auto *spin = qobject_cast<QSpinBox *>(widget)) spin->setSuffix(toQString(value));
        else if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) dspin->setSuffix(toQString(value));
        return;
    }
    if (key == QLatin1String("text_visible")) {
        if (auto *bar = qobject_cast<QProgressBar *>(widget)) bar->setTextVisible(value.toBool());
        return;
    }
    if (key == QLatin1String("format")) {
        if (auto *bar = qobject_cast<QProgressBar *>(widget)) bar->setFormat(toQString(value));
        return;
    }
    if (key == QLatin1String("ticks")) {
        if (auto *slider = qobject_cast<QSlider *>(widget)) {
            slider->setTickPosition(value.toBool() ? QSlider::TicksBelow : QSlider::NoTicks);
        }
        return;
    }
    if (key == QLatin1String("tristate")) {
        if (auto *check = qobject_cast<QCheckBox *>(widget)) check->setTristate(value.toBool());
        return;
    }

    // ── combo / list ──
    if (key == QLatin1String("items")) {
        QStringList texts;
        QStringList ids;
        parseItems(value, texts, ids);
        if (auto *combo = qobject_cast<QComboBox *>(widget)) {
            combo->clear();
            for (int i = 0; i < texts.size(); ++i) combo->addItem(texts.at(i), ids.at(i));
            return;
        }
        if (auto *list = qobject_cast<QListWidget *>(widget)) {
            list->clear();
            for (int i = 0; i < texts.size(); ++i) {
                auto *item = new QListWidgetItem(texts.at(i), list);
                item->setData(Qt::UserRole, ids.at(i));
            }
            return;
        }
        return;
    }
    if (key == QLatin1String("editable")) {
        if (auto *combo = qobject_cast<QComboBox *>(widget)) { combo->setEditable(value.toBool()); return; }
        // 表格默认 NoEditTriggers（只读），要让 cellChanged 有意义就必须能编辑。
        if (auto *table = qobject_cast<QTableWidget *>(widget)) {
            table->setEditTriggers(value.toBool() ? QAbstractItemView::DoubleClicked
                                                  : QAbstractItemView::NoEditTriggers);
            return;
        }
        return;
    }
    if (key == QLatin1String("multi")) {
        if (auto *list = qobject_cast<QListWidget *>(widget)) {
            list->setSelectionMode(value.toBool() ? QAbstractItemView::ExtendedSelection
                                                  : QAbstractItemView::SingleSelection);
        }
        return;
    }
    if (key == QLatin1String("headers_visible")) {
        if (auto *table = qobject_cast<QTableWidget *>(widget)) table->horizontalHeader()->setVisible(value.toBool());
        return;
    }
    if (key == QLatin1String("stretch_last")) {
        if (auto *table = qobject_cast<QTableWidget *>(widget)) {
            table->horizontalHeader()->setStretchLastSection(value.toBool());
        }
        return;
    }
    if (key == QLatin1String("select_mode")) {
        const QString mode = toQString(value).toLower();
        QAbstractItemView::SelectionMode selection = QAbstractItemView::SingleSelection;
        if (mode == QLatin1String("multi") || mode == QLatin1String("extended")) {
            selection = QAbstractItemView::ExtendedSelection;
        } else if (mode == QLatin1String("none")) {
            selection = QAbstractItemView::NoSelection;
        }
        if (auto *table = qobject_cast<QTableWidget *>(widget)) table->setSelectionMode(selection);
        else if (auto *list = qobject_cast<QListWidget *>(widget)) list->setSelectionMode(selection);
        else if (auto *tree = qobject_cast<QTreeWidget *>(widget)) tree->setSelectionMode(selection);
        return;
    }

    // columns/rows/row_ids/headers/nodes/expanded 由 Box 在节点上下文里处理。
}

// ────────────────────────────── 取值 ──────────────────────────────

Variant qtWidgetValue(QWidget *widget, const QString &type) {
    Q_UNUSED(type);
    if (!widget) return {};
    if (auto *edit = qobject_cast<QLineEdit *>(widget)) return toPhpString(edit->text());
    if (auto *text = qobject_cast<QTextEdit *>(widget)) return toPhpString(text->toPlainText());
    if (auto *check = qobject_cast<QCheckBox *>(widget)) return Variant(check->isChecked());
    if (auto *radio = qobject_cast<QRadioButton *>(widget)) return Variant(radio->isChecked());
    if (auto *spin = qobject_cast<QSpinBox *>(widget)) return Variant(static_cast<Int>(spin->value()));
    if (auto *dspin = qobject_cast<QDoubleSpinBox *>(widget)) return Variant(dspin->value());
    if (auto *slider = qobject_cast<QSlider *>(widget)) return Variant(static_cast<Int>(slider->value()));
    if (auto *bar = qobject_cast<QProgressBar *>(widget)) return Variant(static_cast<Int>(bar->value()));
    if (auto *combo = qobject_cast<QComboBox *>(widget)) {
        Array out;
        out.set("index", Variant(static_cast<Int>(combo->currentIndex())));
        out.set("text", toPhpString(combo->currentText()));
        out.set("value", toPhpString(combo->currentData().toString()));
        return out;
    }
    if (auto *list = qobject_cast<QListWidget *>(widget)) {
        auto *item = list->currentItem();
        Array out;
        out.set("index", Variant(static_cast<Int>(list->currentRow())));
        out.set("value", toPhpString(item ? item->data(Qt::UserRole).toString() : QString()));
        out.set("text", toPhpString(item ? item->text() : QString()));
        return out;
    }
    if (auto *table = qobject_cast<QTableWidget *>(widget)) {
        const int row = table->currentRow();
        Array out;
        out.set("row", Variant(static_cast<Int>(row)));
        out.set("value", toPhpString(row >= 0 && table->item(row, 0)
                                         ? table->item(row, 0)->data(Qt::UserRole).toString()
                                         : QString()));
        return out;
    }
    if (auto *tree = qobject_cast<QTreeWidget *>(widget)) {
        auto *item = tree->currentItem();
        return toPhpString(item ? item->data(0, Qt::UserRole).toString() : QString());
    }
    if (auto *tabs = qobject_cast<QTabWidget *>(widget)) return Variant(static_cast<Int>(tabs->currentIndex()));
    if (auto *stack = qobject_cast<QStackedWidget *>(widget)) return Variant(static_cast<Int>(stack->currentIndex()));
    if (auto *label = qobject_cast<QLabel *>(widget)) return toPhpString(label->text());
    return {};
}
