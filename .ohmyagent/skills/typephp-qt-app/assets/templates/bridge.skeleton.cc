// Minimal TypePHP ⇄ Qt Widgets bridge skeleton.
// Pair with php-src/<name>.stub.php (same function names, php_ prefix in C++).
// See references/bridge-pattern.md for the reasoning behind each part.

#include "phpx.h"

#include <QAbstractItemView>
#include <QApplication>
#include <QEventLoop>
#include <QFont>
#include <QHBoxLayout>
#include <QHeaderView>
#include <QLabel>
#include <QLineEdit>
#include <QMainWindow>
#include <QMessageBox>
#include <QPushButton>
#include <QSignalBlocker>
#include <QStyleFactory>
#include <QTableWidget>
#include <QTableWidgetItem>
#include <QTimer>
#include <QVBoxLayout>
#include <QWidget>

#include <deque>

using php::Array;
using php::Bool;
using php::Box;
using php::String;
using php::Variant;

namespace {

// ---- 0. Qt application singleton -------------------------------------------
// Declared before the Box because the Box's methods reference it.

static int qt_argc = 1;
static char qt_program_name[] = "typephp-app";
static char *qt_argv[] = {qt_program_name, nullptr};
static QApplication *qt_application = nullptr;

// ---- 1. Conversion helpers (always UTF-8) ----------------------------------

QString toQString(const Variant &value) {
    if (value.isNull() || value.isUndef()) return {};
    return QString::fromUtf8(value.toCString());
}

String toPhpString(const QString &value) {
    const QByteArray utf8 = value.toUtf8();
    return String(utf8.constData(), static_cast<size_t>(utf8.size()));
}

// ---- 2. Row view (PHP array -> plain C++ struct) ---------------------------

struct Row {
    QString id;
    QString title;
    QString status;
    QString statusLabel;
};

Row fromPhpRow(const Array &row) {
    Row r;
    r.id = toQString(row.get("id"));
    r.title = toQString(row.get("title"));
    r.status = toQString(row.get("status"));
    r.statusLabel = toQString(row.get("status_label"));
    return r;
}

// ---- 3. The Box: holds Qt pointers + the input event queue -----------------

class AppWindowBox final : public Box {
  public:
    explicit AppWindowBox(const QString &title) {
        window_ = new QMainWindow();
        window_->setWindowTitle(title);
        window_->resize(960, 620);

        auto *root = new QWidget();
        auto *layout = new QVBoxLayout(root);

        header_ = new QLabel(title);
        layout->addWidget(header_);

        search_ = new QLineEdit();
        search_->setPlaceholderText(QObject::tr("搜索…"));
        search_->setClearButtonEnabled(true);
        layout->addWidget(search_);

        table_ = new QTableWidget(0, 2);
        table_->setHorizontalHeaderLabels({QObject::tr("标题"), QObject::tr("状态")});
        table_->setSelectionBehavior(QAbstractItemView::SelectRows);
        table_->setSelectionMode(QAbstractItemView::SingleSelection);
        table_->setEditTriggers(QAbstractItemView::NoEditTriggers);
        table_->verticalHeader()->hide();
        layout->addWidget(table_, 1);

        auto *actions = new QHBoxLayout();
        advance_ = new QPushButton(QObject::tr("推进状态"));
        auto *remove = new QPushButton(QObject::tr("删除"));
        actions->addWidget(advance_);
        actions->addWidget(remove);
        actions->addStretch();
        layout->addLayout(actions);

        window_->setCentralWidget(root);

        // Every handler only ENQUEUES — it never mutates state directly.
        QObject::connect(search_, &QLineEdit::textChanged, window_,
                         [this](const QString &text) { enqueue("search", {}, text); });
        QObject::connect(table_, &QTableWidget::itemSelectionChanged, window_,
                         [this]() { enqueue("select", selectedId()); });
        QObject::connect(advance_, &QPushButton::clicked, window_, [this]() {
            const QString id = selectedId();
            if (!id.isEmpty()) enqueue("advance", id);
        });
        QObject::connect(remove, &QPushButton::clicked, window_, [this]() {
            const QString id = selectedId();
            if (!id.isEmpty()
                && QMessageBox::question(window_, QObject::tr("删除"),
                                         QObject::tr("确定删除这一项吗？")) == QMessageBox::Yes) {
                enqueue("delete", id);
            }
        });
        QObject::connect(window_, &QObject::destroyed, [this]() { window_ = nullptr; });

        window_->show();
    }

    bool isOpen() const { return window_ != nullptr && window_->isVisible(); }

    void processEvents() {
        if (!qt_application) return;
        QEventLoop loop;
        QTimer::singleShot(16, &loop, &QEventLoop::quit);   // pump ~1 frame, then return to PHP
        loop.exec(QEventLoop::AllEvents);
    }

    Array pollEvent() {
        if (events_.empty()) return {};
        Array event = events_.front();
        events_.pop_front();
        return event;
    }

    void setView(const Array &rows, const Array &metrics, const QString &selected) {
        header_->setText(QObject::tr("共 %1 项").arg(metrics.get("total").toInt()));

        QSignalBlocker block(table_);          // stop selection-changed from firing mid-rebuild
        rows_.clear();
        table_->setRowCount(static_cast<int>(rows.count()));
        int selectedRow = -1;
        for (size_t i = 0; i < rows.count(); ++i) {
            const Row row = fromPhpRow(rows.get(i).toArray());
            rows_.insert(row.id, row);
            const int r = static_cast<int>(i);
            auto *title = new QTableWidgetItem(row.title);
            title->setData(Qt::UserRole, row.id);     // stash the id on the item
            table_->setItem(r, 0, title);
            table_->setItem(r, 1, new QTableWidgetItem(row.statusLabel));
            if (row.id == selected) selectedRow = r;
        }
        if (selectedRow < 0 && rows.count() > 0) selectedRow = 0;
        if (selectedRow >= 0) table_->selectRow(selectedRow);
    }

    void showError(const QString &message) {
        QMessageBox::warning(window_, QObject::tr("错误"), message);
    }

    bool snapshot(const QString &path) {
        qt_application->processEvents();
        return window_ && window_->grab().save(path, "PNG");
    }

    void cleanup() {
        if (window_) { delete window_; window_ = nullptr; }
    }

  private:
    QString selectedId() const {
        const int row = table_->currentRow();
        if (row < 0 || !table_->item(row, 0)) return {};
        return table_->item(row, 0)->data(Qt::UserRole).toString();
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

    QMainWindow *window_ = nullptr;
    QLabel *header_ = nullptr;
    QLineEdit *search_ = nullptr;
    QTableWidget *table_ = nullptr;
    QPushButton *advance_ = nullptr;
    QHash<QString, Row> rows_;
    std::deque<Array> events_;
};

AppWindowBox *windowBox(Variant box) { return box.toBox<AppWindowBox>(); }

}  // namespace

// ---- 4. Entry points: one line each, mirroring the stub --------------------
// QApplication itself is created lazily, exactly once, inside <APP>_create.

Variant php_<APP>_create(String title) {
    if (!qt_application) {
        qt_application = new QApplication(qt_argc, qt_argv);
        qt_application->setStyle(QStyleFactory::create("Fusion"));
        QFont font = qt_application->font();
#ifdef Q_OS_WIN
        font.setFamily("Microsoft YaHei UI");
#endif
        font.setPointSize(10);
        qt_application->setFont(font);
        qt_application->setStyleSheet(R"QSS(
            QWidget { background: #F6F7FB; color: #1C2035; }
            QPushButton { background: #5965E8; color: #FFFFFF; border: 0; border-radius: 8px; padding: 8px 14px; font-weight: 700; }
            QPushButton:hover { background: #4854D8; }
            QLineEdit { background: #FFFFFF; border: 1px solid #E0E3ED; border-radius: 7px; padding: 7px 10px; }
            QTableWidget { background: #FFFFFF; border: 1px solid #E8EAF2; border-radius: 10px; }
        )QSS");
    }
    return {new AppWindowBox(toQString(title))};
}

Bool php_<APP>_is_open(Variant box) { return windowBox(box)->isOpen(); }
void php_<APP>_process_events(Variant box) { windowBox(box)->processEvents(); }
Array php_<APP>_poll_event(Variant box) { return windowBox(box)->pollEvent(); }
void php_<APP>_set_view(Variant box, Array rows, Array metrics, String selected) {
    windowBox(box)->setView(rows, metrics, toQString(selected));
}
void php_<APP>_show_error(Variant box, String message) { windowBox(box)->showError(toQString(message)); }
Bool php_<APP>_snapshot(Variant box, String path) { return windowBox(box)->snapshot(toQString(path)); }
void php_<APP>_destroy(Variant box) { windowBox(box)->cleanup(); }
