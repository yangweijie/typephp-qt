// QML 时钟组件（qmlclock 示例）。
//
// 根 Rectangle 暴露一个属性 timeText 与一个无参信号 tick：
// 内部 Timer 每 1 秒把 timeText 写成当前时间并 emit tick。
// PHP 侧（src/main.php）通过 qml 控件的 signals/read/props 键与之对话：
//   * signals: ["tick"]  → 每次触发事件 type='qml'、value='tick'、payload 是 read 快照
//   * read:    ["timeText", "speed"]  → 事件 payload 与 value() 读回的键
//   * props:   写根的动态属性（setProperty 通道）
import QtQuick 2.6

Rectangle {
    id: root
    width: 280
    height: 120
    color: "#101828"

    property string timeText: "--:--:--"
    property int speed: 1
    // 供 PHP 侧 props 写入 / value() 读回演示（sizeFactor 默认 1，
    // 读回时与 speed 区分开，避免和 view() 每帧写回的 speed 同值混淆）。
    property real sizeFactor: 1.0

    signal tick()

    Text {
        text: root.timeText
        anchors.centerIn: parent
        font.pixelSize: 48
        font.family: "Menlo"
        color: "#4fd1c5"
    }

    Timer {
        interval: 1000
        repeat: true
        running: true
        onTriggered: {
            root.timeText = new Date().toTimeString().substring(0, 8);
            root.tick();
        }
    }
}
