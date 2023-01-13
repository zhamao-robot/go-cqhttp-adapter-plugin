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

本插件下方列举的事件转换规则仅为自身的一些转换细节处理方案，不代表 OneBot 11 和 OneBot 12 的事件定义。
但是本插件的转换规则也是 OneBot 11 和 OneBot 12 事件定义的一个参考，如果你想了解 OneBot 11 和 12 的差异，也可以阅读下方的转换规则。

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
- go-cqhttp 未提供事件的 ID，因此该插件会自动生成一个随机的 UUID 作为事件 ID。

下面是消息事件（`message`）的一些转换规则：

- `message` 字段如果为字符串，会先将 CQ 码无损转换为 OneBot 11 的等价消息段，然后再将消息段转换为 OneBot 12 标准的消息段。
- CQ 码的转换基本按照 OneBot 11 的规范进行，但是由于 OneBot 11 的规范不完整，因此可能会有一些不同。例如，CQ 码在解码参数时未考虑参数内带有等号，该适配器仅会获取第一个等号前的参数，后面的等号会被当作值的一部分。
- 转换为 OneBot 12 的消息段时，仅保留 `type` 和 `data`，如果存在其他字段，将丢弃。
- `at` 类型，如果参数 `qq` 为 `all`，则类型转换为 `mention_all`，否则类型转换为 `mention` 同时 `qq` 字段名转换为 `user_id`。
- `video`、`image`、`record` 类型，将参数 `file` 转换为 `file_id`。
- `record` 类型转换为 `voice`。
- `location` 类型，将参数 `lat` 转换为 `latitude`，将参数 `lon` 转换为 `longitude`。
- `reply` 类型，将 `id` 转换为 `message_id`，如果存在参数 `qq`，将 `qq` 转换为 `user_id`。
- 其他类型，类型字段名称统一添加前缀 `qq.`，例如 `forward` 转换为 `qq.forward`。

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

下面是请求事件（`request`）的一些转换规则：

- 由于 OneBot 12 标准未规定任何 `request` 事件，故所有 `request` 事件均将 `request_type` 加上 `qq.` 前缀，并转换名称为 `detail_type`。
- 其他字段除 `post_type`、`notice_type`、`sub_type`、`request_type`、`meta_event_type`、`time` 和一系列 `xxx_id` 外，均保留，并加上 `qq.` 前缀，值不变。

下面是元事件（`meta`）的一些转换规则：

- `meta_event_type` 转换为 `detail_type`。
- 目前 go-cqhttp 仅有两种元事件，其中 `meta_event_type` 分别为 `lifecycle` 和 `heartbeat`。
- 如果 `meta_event_type` 为 `lifecycle`，`sub_type` 为 `connect`，则将 `detail_type` 转换为 `connect`。
- 如果按照上面的规则转换为 `connect`，其中 OneBot 12 转换后的 `version` 内容为：`['impl' => 'go-cqhttp', 'version' => $user_agent, 'onebot_version' => '12']`。其中 `$user_agent` 为与 gocq 建立连接时对端发送过来的 `User-Agent` 值。
- 如果 `meta_event_type` 为 `heartbeat`，则参数仅保留 `interval`，类型名称不变。
- 其他 `meta_evet_type`，不添加前缀，假设实现端有一个 `xxx` 元事件，则此处转换为 `detail_type` 依旧是 `xxx`。
- 其他元事件的其他字段，除 `post_type`、`notice_type`、`sub_type`、`request_type`、`meta_event_type`、`time` 和一系列 `xxx_id` 外，均保留，并加上 `qq.` 前缀，值不变。

## 动作转换规则（12 转 11）

- `send_message` 动作根据参数 `detail_type` 的值，转换为对应的 `send_xxx_msg` 动作，但目前仅支持私聊和群组类型。
- 如果 `detail_type` 为 `group`，将保留 `group_id` 参数，并将 OneBot 12 消息段转换为 OneBot 11 消息段发送。
- 如果 `detail_type` 为 `private`，将保留 `user_id` 参数，如果传入的 Action 对象存在 `group_id` 参数也将会保留，然后将 OneBot 12 消息段转换为 OneBot 11 消息段发送。
- `delete_message` 动作名称变更为 `delete_msg`，保留 `message_id` 参数。
- `get_self_info` 动作名称变更为 `get_login_info`。
- `get_user_info` 动作名称变更为 `get_stranger_info`，保留 `user_id` 字段。
- `get_friend_list`、`get_group_info`、`get_group_list`、`get_group_member_info`、`get_group_member_list`、`set_group_name` 动作名称和参数均不变。
- `leave_group` 动作名称变更为 `set_group_leave`，参数不变。
- `upload_file` 动作名称变更为 `download_file`，并且仅支持 URL 方式上传文件。
- `get_status` 名称和参数（好像也没有请求参数，不管了）均保持不变。
- `get_version` 动作名称变更为 `get_version_info`。
- 其他动作如果开头使用 `qq.` 前缀，则将其前缀去除，参数保持不变。
- `echo` 回响字段保持不变。

## 动作响应转换规则（11 转 12）

> 动作响应的转换在插件内部对动作请求做了缓存，通过 echo 字段进行匹配，从而确定响应对应的动作请求。

- `status`、`retcode`、`echo` 字段保持不变。
- `msg` 字段如果存在则转换为 `message` 字段。
- 如果请求的动作是 `send_xxx_msg`，则响应中的 `message_id` 字段保持不变。
- 如果请求动作是 `get_stranger_info`，响应中的 `user_id` 保持不变，`nickname` 转换为 `user_name`，同时也将 `user_displayname` 设置为 `user_name` 相同的值，`user_remark` 设置为空。
- 如果请求动作是 `get_login_info`，响应中的 `user_id` 保持不变，`nickname` 转换为 `user_name`，同时也将 `user_displayname` 设置为 `user_name` 相同的值。
- 如果请求动作是 `get_friend_list`，每个用户元素的 `user_id` 保持不变，`nickname` 转换为 `user_name`，同时也将 `user_displayname` 设置为 `user_name` 相同的值，`user_remark` 设置为空。
- 如果请求动作是 `get_group_info` 或 `get_group_list`，参数保持不变。
- 如果请求动作是 `get_group_member_info`，现有参数转换规则同 `get_stranger_info` 的参数转换规则，其他参数的参数名添加前缀 `qq.`，值保持不变。
- 如果请求动作是 `get_group_member_list`，每个用户元素的参数转换规则同 `get_group_member_info`。
- 如果请求动作是 `download_file`，响应中的 `file` 字段转换为 `file_id`。
- 如果请求动作是 `set_group_name`、`set_group_leave`，参数保持不变。
- 如果请求动作是 `get_status`，仅保留 `good` 字段，OB12 的 `bots` 字段值为 `[['impl' => 'go-cqhttp', 'version' => $user_agent, 'onebot_version' => '12']]`。
- 如果请求动作是 `get_version_info`，`app_name` 转换为 `impl`，`app_version` 转换为 `version`，`onebot_version` 设置为 12，其他值丢弃。

## 其他不兼容项

转换器不支持转换以下 OneBot 12 动作到 OneBot 11 API：

- `get_latest_events`
- `get_supported_actions`
- 所有二级群组动作，例如 `get_guild_info` 等
- `upload_file_fragmented`
- `get_file`
- `get_file_fragmented`
