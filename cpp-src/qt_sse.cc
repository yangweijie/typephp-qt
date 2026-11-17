// TypePHP\Qt — SSE 流式桥接原语（AG-UI / text/event-stream）。
//
// 为什么独立成文件而不是并进 qt_loop.cc：网络原语（qt_http_request）是同步
// 阻塞、栈上 manager 活不过单次调用；SSE 的 manager/reply 必须**活过每次调用**，
// 每流一份状态（注册表 + 帧缓冲 + 解析状态机），混在生命周期 TU 里会把
// 「按窗口的清理」与「按流的清理」两个生命周期搅在一起。
//
// 生命周期取舍（方案 F58 §58.3 的六条缓冲依据）：流是**进程级资源，不挂窗口** ——
// 窗口 cleanup 会 events_.clear()（未交付帧静默丢失），且窗口队列没有流身份
// 表达不了「从这条流取至多 64 帧」。代价：readyRead 只在 Qt 事件泵期间触发
// （processEvents 的 16ms 嵌套循环），流消费方必须每帧泵一下；末窗 cleanup 后
// 存活流的数据永不到（无窗口 CLI 式用法 = v1 范围外）。

#include "qt_common.h"

#ifndef QTPHP_NO_NETWORK

#include <QUrl>
#include <map>
#include <memory>
#include <tuple>
#include <vector>

namespace {

/**
 * 一条 SSE 流的运行态。
 *
 * manager 是 QObject，reply 是 manager 的子对象（QObject 父子树）：
 * 析构 manager 即连带回收 reply，流表摘除后不留悬垂。
 * 因此 SseStream **不可移动**（manager 指针成员 + 不可拷的 QNAM 语义），
 * 注册表必须按 unique_ptr 原地存储（std::map 按值存储要求 MoveInsertable，
 * 评审轮修订①钉死；直接存结构体编不过）。
 */
struct SseStream {
    QNetworkAccessManager *manager = nullptr;
    QNetworkReply *reply = nullptr;
    bool finished = false;   // 服务端已关响应体（正常或失败收尾）
    bool wired = false;      // 同一 reply 的信号连接去重（Qt 对重复连接不去重，qt_qml.cc 先例）
    QByteArray buffer;       // 原始字节攒池：帧分帧前不按字节解码（网络包边界
                             // 可能从多字节 UTF-8 字符中间切块，攒完整帧才 fromUtf8）
    std::vector<std::tuple<QString, QString, QString, bool, bool>> frames;  // (data, event, id, hasEvent, hasId)
    int readCursor = 0;
    QString state = QStringLiteral("open");   // open / done / error
    int status = 0;
    QString error;

    ~SseStream() { delete manager; }
};

using SseStreamPtr = std::unique_ptr<SseStream>;

std::map<int, std::unique_ptr<SseStream>> &sseRegistry() {
    // TU 级静态：流是进程级资源，与 QtWindowBox 解耦（不加窗口盒成员）。
    static std::map<int, std::unique_ptr<SseStream>> registry;
    return registry;
}

int &sseNextId() {
    static int next = 1;  // 与 FakeBridge 的 $sseSeq 同序（都从 1 起、不复用）
    return next;
}

/** optInt 在 qt_loop.cc 的匿名命名空间里（TU 私有），这里按 F8.1 的收参形态自备一份。 */
int qtSseOptInt(const Array &options, const char *key, int fallback = 0) {
    const Variant v = options.get(key);
    return v.isUndef() || v.isNull() ? fallback : static_cast<int>(v.toInt());
}

/** SSE 字段值：剥掉冒号后**至多一个**前导空格（协议规定只剥一个）。 */
QString qtSseFieldValue(const QString &line, int prefixLen) {
    QString value = line.mid(prefixLen);
    if (!value.isEmpty() && value.at(0) == QLatin1Char(' ')) value = value.mid(1);
    return value;
}

/** 解析一个已分帧的块（不含收尾空行）：多行 data: 按 \n 拼接，event/id 按协议记录。 */
void qtSseParseFrameBlock(SseStream &stream, const QByteArray &bytes) {
    const QString text = QString::fromUtf8(bytes);
    QStringList dataLines;
    QString event, id;
    for (const QString &raw : text.split(QLatin1Char('\n'))) {
        const QString line = raw.endsWith(QLatin1Char('\r')) ? raw.mid(0, raw.size() - 1) : raw;
        if (line.isEmpty()) continue;
        if (line.startsWith(QLatin1Char(':'))) continue;              // “: ” 注释行
        if (line.startsWith(QLatin1String("data:"))) {
            dataLines << qtSseFieldValue(line, 5);
        } else if (line == QLatin1String("data")) {
            dataLines << QString();
        } else if (line.startsWith(QLatin1String("event:"))) {
            event = qtSseFieldValue(line, 6);
        } else if (line.startsWith(QLatin1String("id:"))) {
            id = qtSseFieldValue(line, 3);
        }
        // retry: 与其他未知字段按协议忽略（AG-UI 的事件类型在 JSON 的 type 字段里，
        // 不用 SSE 的 event 字段 —— event/id 只是原样带出的参考字段）
    }
    if (dataLines.isEmpty() && event.isEmpty() && id.isEmpty()) return;  // 纯注释块不产帧
    stream.frames.emplace_back(dataLines.join(QLatin1Char('\n')), event, id, !event.isEmpty(), !id.isEmpty());
}

/** 把攒池里所有**完整**帧（空行分帧）解析进 frames；半帧留下等更多字节。 */
void qtSseDrainCompleteFrames(SseStream &stream) {
    for (;;) {
        const int lf = stream.buffer.indexOf("\n\n");
        const int crlf = stream.buffer.indexOf("\r\n\r\n");
        int boundary = -1;
        int consumed = 0;
        if (lf < 0) {
            boundary = crlf;
            consumed = 4;
        } else if (crlf < 0) {
            boundary = lf;
            consumed = 2;
        } else {
            boundary = (lf < crlf) ? lf : crlf;
            consumed = (lf < crlf) ? 2 : 4;
        }
        if (boundary < 0) break;  // 尚无完整帧，等下一次 readyRead
        qtSseParseFrameBlock(stream, stream.buffer.left(boundary));
        stream.buffer = stream.buffer.mid(boundary + consumed);
    }
}

/** 流收尾（服务端关响应体）：悬挂的最后一帧按 end-of-input 语义处理掉。 */
void qtSseFlushPendingFrame(SseStream &stream) {
    if (stream.buffer.trimmed().isEmpty()) {
        stream.buffer.clear();
        return;
    }
    qtSseParseFrameBlock(stream, stream.buffer);
    stream.buffer.clear();
}

}  // namespace

// ────────────────────────────── php_qt_sse_* 入口 ──────────────────────────────

Int php_qt_sse_open(Array spec) {
    // 两级守卫与 qt_http_request 同序：先 QApplication（QNAM 依赖它），再 url。
    if (!qt_application) return -1;
    const QString url = optString(spec, "url");
    if (url.isEmpty()) return -1;

    SseStreamPtr stream = std::make_unique<SseStream>();
    QNetworkRequest request{QUrl(url)};
    const QString method = optString(spec, "method", QStringLiteral("GET")).toUpper();
    const int timeoutMs = qtSseOptInt(spec, "timeout_ms", 0);
    if (timeoutMs > 0) {
        // 缺省 0 = 不设传输超时：SSE 是长命流（AG-UI 一个 run 可能跑几十秒），
        // 同步 http 的 15 秒缺省在这里会掐死流
        request.setTransferTimeout(timeoutMs);
    }
    const Variant headerSpec = spec.get("headers");
    if (headerSpec.isArray()) {
        const Array headers = headerSpec.toArray();
        for (auto it = headers.begin(); it != headers.end(); ++it) {
            request.setRawHeader(toQString(it.key()).toUtf8(), it.value().toCString());
        }
    }
    const QByteArray body = toQString(spec.get("body")).toUtf8();

    stream->manager = new QNetworkAccessManager(qt_application);
    QNetworkReply *reply = nullptr;
    if (method == QLatin1String("POST")) {
        reply = stream->manager->post(request, body);
    } else if (method == QLatin1String("PUT")) {
        reply = stream->manager->put(request, body);
    } else if (method == QLatin1String("DELETE")) {
        reply = stream->manager->deleteResource(request);
    } else if (method == QLatin1String("GET")) {
        reply = stream->manager->get(request);
    } else {
        // 其余动词（PATCH / OPTIONS / 自定义）走 custom 通道
        reply = stream->manager->sendCustomRequest(request, method.toUtf8(), body);
    }
    if (!reply) {
        // 防御分支：QNAM 对合法 QUrl 恒返回 reply（坏 URL 是异步报 InvalidUrl），
        // 这里只防未来 Qt 行为变化 —— 宁可 -1 也不留半截流
        delete stream->manager;
        return -1;
    }

    auto &registry = sseRegistry();
    const int id = sseNextId()++;
    registry.emplace(id, std::move(stream));  // map 没有 (key, value) 二参 insert，用 emplace 原地构造
    SseStream *st = registry.at(id).get();
    st->reply = reply;

    // lambda connect（Qt6 functor 重载，无 moc；指针版先例 qt_loop.cc:350，
    // lambda 版先例 qt_widgets.cc 三十余处）：context 挂 reply —— reply 销毁
    // 时连接自动拆除，且 close() 的 disconnect() 兜住「先关流后到信号」的窗口。
    if (!st->wired) {
        st->wired = true;
        QObject::connect(reply, &QNetworkReply::readyRead, reply, [st]() {
            // 信号处理只改流表状态，不碰任何窗口状态（队列契约的同源约束）
            st->buffer.append(st->reply->readAll());
            qtSseDrainCompleteFrames(*st);
        });
        QObject::connect(reply, &QNetworkReply::finished, reply, [st]() {
            st->finished = true;
            st->buffer.append(st->reply->readAll());
            qtSseFlushPendingFrame(*st);
            st->status = st->reply->attribute(QNetworkRequest::HttpStatusCodeAttribute).toInt();
            if (st->reply->error() != QNetworkReply::NoError) {
                // error = 「流存在但请求失败」；用户 close() 的 abort 不会走到这里
                //（close 先摘流表 + disconnect），误报不了
                st->state = QStringLiteral("error");
                st->error = st->reply->errorString();
            } else {
                st->state = QStringLiteral("done");
            }
        });
    }
    return static_cast<Int>(id);
}

Array php_qt_sse_read(Int stream, Int max) {
    Array out;
    auto &registry = sseRegistry();
    const auto it = registry.find(static_cast<int>(stream));
    if (it == registry.end() || static_cast<int>(max) <= 0) return out;
    SseStream &st = *it->second;
    const int end = std::min(st.readCursor + static_cast<int>(max), static_cast<int>(st.frames.size()));
    for (int i = st.readCursor; i < end; ++i) {
        Array frame;
        frame.set("data", toPhpString(std::get<0>(st.frames[i])));
        // event / id 仅当帧里出现过才带键（与 stub docblock 契约一致）
        if (std::get<3>(st.frames[i])) frame.set("event", toPhpString(std::get<1>(st.frames[i])));
        if (std::get<4>(st.frames[i])) frame.set("id", toPhpString(std::get<2>(st.frames[i])));
        out.set(static_cast<Int>(i - st.readCursor), frame);
    }
    st.readCursor = end;  // 游标前进：重复 read 不重发
    return out;
}

Array php_qt_sse_status(Int stream) {
    Array out;
    const auto &registry = sseRegistry();
    const auto it = registry.find(static_cast<int>(stream));
    if (it == registry.end()) {
        // unknown = 流 id 不存在或已关闭（含 -1：open 失败没有流，不得报 error）
        out.set("state", toPhpString(QStringLiteral("unknown")));
        out.set("status", Variant(static_cast<Int>(0)));
        out.set("error", toPhpString(QString()));
        return out;
    }
    const SseStream &st = *it->second;
    out.set("state", toPhpString(st.state));
    out.set("status", Variant(static_cast<Int>(st.status)));
    out.set("error", toPhpString(st.error));
    return out;
}

void php_qt_sse_close(Int stream) {
    auto &registry = sseRegistry();
    const auto it = registry.find(static_cast<int>(stream));
    if (it == registry.end()) return;  // 幂等：未知 / 已关无副作用
    SseStream *st = it->second.get();
    if (st->reply) {
        if (!st->finished) st->reply->abort();
        st->reply->disconnect();  // 摘掉 readyRead/finished lambda，摘流后不悬垂
    }
    registry.erase(it);  // ~SseStream 删 manager，连带回收 reply
}

#else

// 没链 Qt Network 的构建仍要满足 PHP 侧的调用契约：保留同一组签名（符号无条件
// 存在保 lint），调用时给结构化错误而不是链接期缺符号（qt_http_request 桩先例）。
// 状态词表与实现分支钉死一致（第二轮评审④）：unknown = 无流存在，error 只留给
// 「流存在但请求失败」—— 无网络构建下流永远不存在，故恒 unknown。

Int php_qt_sse_open(Array spec) {
    (void)spec;
    return -1;
}

Array php_qt_sse_read(Int stream, Int max) {
    (void)stream;
    (void)max;
    return {};
}

Array php_qt_sse_status(Int stream) {
    (void)stream;
    Array out;
    out.set("state", toPhpString(QStringLiteral("unknown")));
    out.set("status", Variant(static_cast<Int>(0)));
    out.set("error", toPhpString(QStringLiteral(
        "qt_sse_status: 本次构建未编入 Qt Network（project.yml 去掉 QTPHP_NO_NETWORK 并链上 Qt6Network）")));
    return out;
}

void php_qt_sse_close(Int stream) {
    (void)stream;
}

#endif
