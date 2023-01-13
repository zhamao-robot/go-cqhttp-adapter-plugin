# go-cqhttp-adapter-plugin

炸毛框架用于接入 go-cqhttp（OneBot 11）的适配器插件。

## 功能

该插件将 gocq 的反向 WebSocket 接入信息全部转换为 OneBot 12 标准，安装该插件后几乎无需修改任何代码即可接入。

## 安装

```bash
# Composer 安装稳定版
composer require zhamao/go-cqhttp-adapter-plugin

# GitHub 安装 Nightly 版
./zhamao plugin:install https://github.com/zhamao-robot/go-cqhttp-adapter-plugin.git
```

## 转换注意事项

由于 OneBot 11 和 OneBot 12 有较多差异，而这些差异也导致两者不能无损相互转换。
例如 OneBot 11 中未规定要求文件分片上传和下载的动作，那么在使用本插件时也无法使用这些动作。

由于 OneBot 11 的实现较多，而且和 OneBot 11 本身相差较大，该插件也时重点针对 go-cqhttp 进行适配转换，这也是插件不叫 onebot-11-adapter 的原因。

## 事件转换规则（11 转 12）

下面是 `post_type` 转换规则：

- 字段名 `post_type` 转换为 `type`。
- 如果 `post_type` 值为 `meta_event`，转换为 `meta`。
- 如果 `post_type` 值为 `message_sent`，转换为 `message`。

下面是 `XXX_type` 转换规则：

- 如果 `post_type` 为 `message` 或 `message_sent`，将字段名 `message_type` 转换为 `detail_type`。
- 如果 `post_type` 为 `meta_event`，将字段名 `meta_event_type` 转换为 `detail_type`。
- 如果 `post_type` 为 `notice`，将字段名 `notice_type` 转换为 `detail_type`。
- 如果 `post_type` 为 `request`，将字段名 `request_type` 转换为 `detail_type`。

下面是 `self_id` 转换规则：

- 如果存在 `self_id` 字段，将该字段删除，替换为 `"self" => ["user_id" => $user_id, "platform" => "qq"]`，其中 `$user_id` 的值等于 `self_id` 的字符串值。
- 如果不存在 `self_id` 字段，将该字段删除，替换为和上方一样的格式，`$user_id` 的值等于该连接请求握手时 `X-Self-ID` 的值。

下面是 `user_id`、`group_id`、`guild_id`、`channel_id`、`message_id` 转换规则：

- 以上列举的值都取字符串值，即转换为字符串。

下面是其他字段的一些转换规则：

- 如果 `post_type` 为 `message` 或 `message_sent`，则将 `raw_message` 转换为 `alt_message`。
- go-cqhttp 的消息事件中默认带有 `sender`，将其字段名转换为 `qq.sender`，内部参数不变。

下面是通知事件（`notice`）的一些转换规则：

| go-cqhttp 的 `notice_type` | OneBot 12 的 `detail_type` | 描述        |
|---------------------------|---------------------------|-----------|
| `friend_recall`           | `private_message_delete`  | 撤回一条私聊消息  |
| `friend_add`              | `friend_increase`         | 添加好友的通知事件 |
| `group_increase`          | `group_member_increase`   | 群成员增加     |
| `group_decrease`          | `group_member_decrease`   | 群成员减少     |
| `group_recall`            | `group_message_delete`    | 群消息撤回     |
| 除上述外的其他通知事件               | `qq.` 前缀加上原名称             |           |

- `friend_recall` 转换后，仅保留 `message_id` 和 `user_id` 字段。
- `friend_add` 转换后，仅保留 `user_id` 字段。
- `group_increase` 转换后，如果 `sub_type` 值为 `approve` 或空，则转换为 `join`；如果为 `invite` 则不变，如果是其他，则加上前缀 `qq.`。
- `group_increase` 转换后，仅保留 `sub_type`、`group_id`、`user_id``operator_id` 且转换为字符串值。
- `group_decrease` 转换后，如果 `sub_type` 值为 `kick` 或 `kick_me`，则转换为 `kick`；如果为 `leave` 则不变，如果是其他，则加上前缀 `qq.`。如果想判断是否为 `kick_me`，可以使用判断 `user_id` 和 `operator_id` 是否相同。
- `group_decrease` 转换后，仅保留 `sub_type`、`group_id`、`user_id``operator_id` 且转换为字符串值。
- `group_recall` 转换后，如果 `user_id` 与 `operator_id` 相同，则 `sub_type` 值设定为 `recall`，否则为 `delete`（分别代表自己撤回和被撤回）。
- `group_recall` 转换后，仅保留 `message_id`、`group_id`、`user_id`、`operator_id` 且转换为字符串值。
- 其他通知类事件加前缀，其他字段除 `post_type`、`notice_type`、`sub_type`、`request_type`、`meta_event_type`、`time` 和一系列 `xxx_id` 外，均保留，并加上 `qq.` 前缀，值不变。