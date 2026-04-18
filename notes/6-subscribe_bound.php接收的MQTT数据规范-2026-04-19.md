# `subscribe_bound.php` 接收的 MQTT 数据规范

**文档日期**：2026-04-19  
**适用范围**：SmartFrameCloud 云平台设备首次注册上行消息  
**对应服务端脚本**：`backend/mqtt/subscribe_bound.php`

## 1. 文档目的

本文档用于约定 ESP32-S3 设备向云平台上行 MQTT 消息时，`subscribe_bound.php` 当前版本所接受的 topic 规则、payload 规则、服务端处理逻辑以及与云端下发动态绑定码消息的区分方式。

这份文档是**基于当前代码已经实现的接收器行为**整理出来的实际规范，不是未来预案。

## 2. 当前功能定位

`subscribe_bound.php` 的职责是：

- 长期订阅 MQTT 主题 `device/+/bound`
- 接收设备首次联网后的“新设备注册上行”消息
- 从 topic 中提取设备唯一 ID
- 校验 payload 是否符合规定 JSON 结构
- 将设备写入 `devices` 表
- 对已存在设备刷新在线状态和最后在线时间

当前版本**不会**做这些事：

- 不生成动态绑定码
- 不校验用户绑定关系
- 不处理设备名称、Wi-Fi 信息、固件信息等业务字段
- 不根据 payload 修改 `devices` 其他字段

换句话说，当前它只是一个“新设备注册入库监听器”。

## 3. 订阅主题

服务端订阅的 topic filter 是：

```text
device/+/bound
```

也就是接收三段式主题：

```text
device/<DEVICE_UID>/bound
```

## 4. Topic 规范

## 4.1 标准格式

设备注册上行必须发布到以下格式的 topic：

```text
device/<DEVICE_UID>/bound
```

例如：

```text
device/AC12EF34AB56CD78/bound
```

## 4.2 DEVICE_UID 规范

`DEVICE_UID` 必须满足：

- 长度固定为 16 个字符
- 只能使用十六进制字符：`0-9`、`A-F`、`a-f`
- 推荐设备端统一使用**全大写**

合法示例：

```text
AC12EF34AB56CD78
ac12ef34ab56cd78
00112233AABBCCDD
```

非法示例：

```text
AC12EF34AB56CD7
AC12EF34AB56CD789
AC12EF34AB56CDG8
AC12-EF34-AB56-CD78
```

## 4.3 大小写规则

当前服务端的处理规则是：

- topic 中允许大写或小写十六进制
- 入库前统一转成**全大写**

例如：

```text
device/ac12ef34ab56cd78/bound
```

和：

```text
device/AC12EF34AB56CD78/bound
```

会被视为同一台设备，最终数据库里写成：

```text
AC12EF34AB56CD78
```

## 5. Payload 规范

## 5.1 强制 JSON 结构

当前版本的 `subscribe_bound.php` 对 payload **有强制结构要求**。

只有符合下面 JSON 结构的消息才会被视为合法“新设备注册上行”：

```json
{
  "event": "reg_new_device",
  "device_uid": "AC12EF34AB56CD78",
  "timestamp": 1776530000
}
```

也就是说，以下条件缺一不可：

- 必须是合法 JSON
- `event` 必须等于 `reg_new_device`
- `device_uid` 必须存在，且必须与 topic 中的 `DEVICE_UID` 一致
- `timestamp` 必须存在，且必须是数字

只要不满足以上任一条件，消息就会被直接忽略，不会入库。

## 5.2 当前推荐 payload

当前推荐 payload 就是标准注册格式：

```json
{
  "event": "reg_new_device",
  "device_uid": "AC12EF34AB56CD78",
  "timestamp": 1776530000
}
```

字段说明：

- `event`：事件类型，当前固定为 `reg_new_device`
- `device_uid`：设备 16 字符唯一 ID
- `timestamp`：Unix 时间戳

## 5.3 Topic 与 payload 不一致时

当前服务端行为：

- 如果 payload 中的 `device_uid` 与 topic 中的 `DEVICE_UID` 不一致
- 整条消息会被忽略
- 不会入库

示例：

```text
topic:   device/AC12EF34AB56CD78/bound
payload: {"event":"reg_new_device","device_uid":"FFFFFFFFFFFFFFFF","timestamp":1776530000}
```

结果：

- 该消息无效
- 服务端忽略

## 5.4 无效 payload 示例

以下 payload 当前都会被忽略：

### 空字符串

```text
(empty payload)
```

### 普通文本

```text
online
```

### 缺少 event

```json
{
  "device_uid": "AC12EF34AB56CD78",
  "timestamp": 1776530000
}
```

### event 错误

```json
{
  "event": "bound",
  "device_uid": "AC12EF34AB56CD78",
  "timestamp": 1776530000
}
```

### 缺少 timestamp

```json
{
  "event": "reg_new_device",
  "device_uid": "AC12EF34AB56CD78"
}
```

## 6. 与云端下发动态绑定码的区分

当前系统里，云端也会向同一个 topic 发送下行消息：

```text
device/<DEVICE_UID>/bound
```

它的 payload 类似：

```json
{
  "event": "dyn_bound_code",
  "device_uid": "AC12EF34AB56CD78",
  "dyn_bound_code": "483726",
  "expires_in": 300,
  "timestamp": 1776530000
}
```

为了避免“回环”或误把云端下行当成设备注册上行，当前 `subscribe_bound.php` 的处理规则是：

- 如果 `event = dyn_bound_code`
- 直接视为云端下行消息
- 记录忽略日志
- 不入库

因此：

- 设备注册上行只认 `event = reg_new_device`
- 云端下发绑定码只认 `event = dyn_bound_code`

## 7. QoS 与 Retain 建议

## 7.1 QoS

当前建议设备端使用：

```text
QoS 1
```

原因：

- 新设备注册消息比普通心跳更重要
- 当前 subscriber 已实现 `PUBACK`

## 7.2 Retain

当前**不建议**设备端对 `device/<DEVICE_UID>/bound` 使用 retained message。

原因：

- retained message 会在 subscriber 重连后重复收到旧注册消息
- 当前 subscriber 只是做“注册写库”，保留旧消息意义不大
- 容易造成日志误导

建议：

```text
retain = false
```

## 8. 服务端入库规则

当服务端收到合法 topic 且 payload 结构合法后，当前逻辑会执行：

### 若设备不存在

插入新记录：

- `device_uid` = topic 中的设备 ID（统一转大写）
- `user_id` = `NULL`
- `status` = `1`
- `last_online_at` = `NOW()`

### 若设备已存在

更新已有记录：

- `status` = `1`
- `last_online_at` = `NOW()`

当前**不会**改这些字段：

- `user_id`
- `name`
- `description`
- `bound_at`
- `dyn_bound_code`
- `current_image_id`

## 9. 当前数据库影响

对应表：`devices`

当前 subscriber 会影响这些列：

- `device_uid`
- `status`
- `last_online_at`

其中：

- 新设备插入时：`user_id = NULL`
- `dyn_bound_code` 当前阶段不写

## 10. 设备端标准发布示例

主题：

```text
device/AC12EF34AB56CD78/bound
```

payload：

```json
{
  "event": "reg_new_device",
  "device_uid": "AC12EF34AB56CD78",
  "timestamp": 1776530000
}
```

MQTT 参数建议：

- `QoS = 1`
- `retain = false`

## 11. ESP32-S3 侧建议

设备端发布这个消息的时机，建议放在：

### 时机 A：设备刚连上 MQTT broker 后，且当前尚未绑定用户

优点：

- 最早让云端知道设备已上线
- 当前 subscriber 可以立即写库

### 时机 B：设备完成 Wi-Fi 配网并成功接入云端后

优点：

- 更符合“待绑定设备首次注册”的语义

如果你的设备未来还会有“在线心跳” topic，那么建议分工如下：

- `device/<DEVICE_UID>/bound`：首次注册上云时发一次 `reg_new_device`
- `device/<DEVICE_UID>/msg` 或 `status`：后续心跳持续发

## 12. 当前版本的边界

目前这份规范对应的代码还有这些边界：

- 没有鉴权签名机制
- 没有校验 `timestamp` 是否过期
- 没有写固件版本等业务字段
- 没有处理动态绑定码逻辑
- 没有“设备已绑定后禁止再次注册”的更严格控制

因此你应该把它理解为：

- 第一阶段：设备首次注册入库通道

而不是：

- 完整的设备绑定协议

## 13. 最终推荐规范

如果你现在就要给 ESP32-S3 固件端一个可以直接照着做的版本，请按下面这份：

### Topic

```text
device/<16位全大写十六进制DEVICE_UID>/bound
```

### Payload

```json
{
  "event": "reg_new_device",
  "device_uid": "<16位全大写十六进制DEVICE_UID>",
  "timestamp": 1776530000
}
```

### MQTT 参数

- QoS: `1`
- retain: `false`

### 服务端当前行为

- 只接受 `event = reg_new_device` 的合法 JSON
- 以 topic 和 payload 一致的 `DEVICE_UID` 为准
- 自动写入或更新 `devices`
- `user_id = NULL` 表示设备已联网但尚未绑定到用户

## 14. 与当前代码对应关系

对应文件：

- `backend/mqtt/subscribe_bound.php`
- `backend/config/database.php`

当前核心逻辑是：

1. 订阅 `device/+/bound`
2. 解析 topic
3. 验证 topic 是否符合 `device/<16位十六进制>/bound`
4. 忽略 `event = dyn_bound_code` 的云端下行消息
5. 校验 payload 是否为合法 `reg_new_device` JSON
6. 将 `device_uid` 转大写
7. 更新 `devices.status = 1`
8. 更新 `devices.last_online_at = NOW()`

## 15. 结论

当前 `subscribe_bound.php` 对设备上行 MQTT 的真正要求是：

- topic 必须正确
- `DEVICE_UID` 必须是合法 16 位十六进制
- payload 必须是合法 JSON
- `event` 必须等于 `reg_new_device`
- payload 中的 `device_uid` 必须与 topic 一致
- payload 中必须提供数字型 `timestamp`

所以现阶段设备端最重要的是：

- **topic 不要错**
- **device_uid 不要乱**
- **建议统一全大写**
- **必须按规定 JSON 结构发送**

---

**备注**：本文严格基于 2026-04-19 当前版本的 `subscribe_bound.php` 实现整理；如果后续 subscriber 开始解析更多 payload 字段，本文档也应同步更新。
