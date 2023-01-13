<?php

declare(strict_types=1);

namespace GocqAdapter;

use OneBot\V12\Object\MessageSegment;

class GocqEventConverter
{
    public function __construct(private string $self_id, private string $version)
    {
    }

    /**
     * 将 OneBot11/go-cqhttp 的事件转换为 OneBot 12 可用的事件
     *
     * 其中有几个细节：
     * 1. self 下的 platform 直接使用 qq 代替，未来说不定再添加动态的
     * 2. message_sent 直接默认转换，如果不需要 message_sent 事件请在 gocq 的配置中取消
     * 3. 事件的 id 为框架生成的随机 ID，并非 gocq 生成
     * 4. 仅将 gocq 写入文档的非标准内的事件字段转换为扩展字段
     *
     * @param array $event OneBot11/go-cqhttp 事件原数据
     */
    public function convertEvent(array $event): ?array
    {
        $ob12 = [];
        // post_type 转换为 type
        $ob12['type'] = $event['post_type'];
        // self_id 转换为 self
        if (isset($event['self_id'])) {
            $ob12['self'] = [
                'user_id' => strval($event['self_id']),
                'platform' => 'qq',
            ];
        } elseif ($event['post_type'] !== 'meta_event') {
            $ob12['self'] = [
                'user_id' => $this->self_id,
                'platform' => 'qq',
            ];
        }
        // time 原封不动
        $ob12['time'] = $event['time'];
        // 生成一个事件 ID
        $ob12['id'] = ob_uuidgen();
        switch ($ob12['type']) {
            case 'message':
            case 'message_sent':
                // message_type 转换为 detail_type
                $ob12['detail_type'] = $event['message_type'];
                // TODO：目前只适配了 gocq 的 private 和 group 类型，以后再适配 guild，因为 gocq 的 guild 太特殊了
                if (!in_array($ob12['detail_type'], ['private', 'group'])) {
                    return null;
                }
                // sub_type 原封不动
                $ob12['sub_type'] = $event['sub_type'];
                // message_id 需要 strval 后
                $ob12['message_id'] = strval($event['message_id']);
                // user_id 需要 strval
                $ob12['user_id'] = strval($event['user_id']);
                // 转换下消息
                $ob12['message'] = $this->convertMessageSegment($event['message']);
                // raw_message 转换为 alt_message
                $ob12['alt_message'] = $event['raw_message'];
                // sender 转换为 qq.sender
                $ob12['qq.sender'] = $event['sender'];
                // font 转换为 qq.font
                $ob12['qq.font'] = $event['font'];
                // message_type 为 group 时，需要转换 group_id
                if ($ob12['detail_type'] === 'group') {
                    $ob12['group_id'] = strval($event['group_id']);
                }
                break;
            case 'notice':
                $ob12 = $this->convertNoticeEvent($ob12, $event);
                break;
            case 'request':
                $ob12 = $this->convertRequestEvent($ob12, $event);
                break;
            case 'meta_event':
                $ob12 = $this->convertMetaEvent($ob12, $event);
                break;
        }
        // 有的事件没有 sub_type，补上
        if (!isset($ob12['sub_type'])) {
            $ob12['sub_type'] = '';
        }
        return $ob12;
    }

    /**
     * 将 OneBot 11 的消息转换为 OneBot 12 的消息段
     */
    public function convertMessageSegment(string|array $message): array
    {
        // 如果是 string，先读 CQ 码
        if (is_string($message)) {
            $message = GocqSegmentConverter::getInstance()->strToSegments($message);
        } else {
            foreach ($message as $k => $v) {
                [$type, $data] = GocqSegmentConverter::getInstance()->parseSegment11To12($v['type'], $v['data'] ?? []);
                $message[$k] = ['type' => $type, 'data' => $data];
            }
        }
        return $message;
    }

    /**
     * 转换通知事件
     *
     * @param array $ob12  OneBot 12 事件数组
     * @param array $event OneBot 11 / go-cqhttp 事件数组
     */
    public function convertNoticeEvent(array $ob12, array $event): array
    {
        // 标准对照表，将一些特定 OneBot 12 中规定了的事件转换到标准的，剩下的都加上前缀
        switch ($event['notice_type']) {
            case 'friend_recall':   // 消息撤回，转换为 private_message_delete
                $ob12['detail_type'] = 'private_message_delete';
                $ob12['message_id'] = strval($event['message_id']);
                $ob12['user_id'] = strval($event['user_id']);
                break;
            case 'friend_add':      // 好友添加，转换为 friend_increase
                $ob12['detail_type'] = 'friend_increase';
                $ob12['user_id'] = strval($event['user_id']);
                break;
            case 'group_increase':  // 群成员增加，转换为 group_member_increase
                $ob12['detail_type'] = 'group_member_increase';
                $ob12['sub_type'] = match ($event['sub_type']) {
                    'approve', '' => 'join',
                    'invite' => 'invite',
                    // no break
                    default => 'qq.' . $event['sub_type'],
                };
                $ob12['group_id'] = strval($event['group_id']);
                $ob12['user_id'] = strval($event['user_id']);
                $ob12['operator_id'] = strval($event['operator_id']);
                break;
            case 'group_decrease':  // 群成员减少，转换为 group_member_decrease
                $ob12['detail_type'] = 'group_member_decrease';
                $ob12['sub_type'] = match ($event['sub_type']) {
                    'leave' => 'leave',
                    'kick', 'kick_me' => 'kick',
                    // no break
                    default => ('qq.' . $event['sub_type']),
                };
                $ob12['group_id'] = strval($event['group_id']);
                $ob12['user_id'] = strval($event['user_id']);
                $ob12['operator_id'] = strval($event['operator_id']);
                break;
            case 'group_recall':    // 群消息撤回，转换为 group_message_delete
                $ob12['detail_type'] = 'group_message_delete';
                $ob12['sub_type'] = $event['user_id'] == $event['operator_id'] ? 'recall' : 'delete';
                $ob12['group_id'] = strval($event['group_id']);
                $ob12['message_id'] = strval($event['message_id']);
                $ob12['user_id'] = strval($event['user_id']);
                $ob12['operator_id'] = strval($event['operator_id']);
                break;
            default:        // 其他的 notice 事件，统一加上前缀
                $ob12['detail_type'] = 'qq.' . $event['notice_type'];
                $ob12 = $this->parseExtendedKeys($ob12, $event);
                break;
        }
        return $ob12;
    }

    /**
     * 转换请求事件
     *
     * @param array $ob12  OneBot 12 事件数组
     * @param array $event OneBot 11 / go-cqhttp 事件数组
     */
    public function convertRequestEvent(array $ob12, array $event): array
    {
        // OneBot 12 标准中没有规定任何 request，所以任何 request 都加前缀
        $ob12['detail_type'] = 'qq.' . $event['request_type'];
        return $this->parseExtendedKeys($ob12, $event);
    }

    public function convertMetaEvent(array $ob12, array $event): array
    {
        $ob12['type'] = 'meta';
        switch ($event['meta_event_type']) {
            case 'lifecycle':
                if ($event['sub_type'] == 'connect') {
                    $ob12['detail_type'] = 'connect';
                    $ob12['version'] = [
                        'impl' => 'go-cqhttp',
                        'version' => $this->version,
                        'onebot_version' => '12',
                    ];
                }
                break;
            case 'heartbeat':
                $ob12['detail_type'] = 'heartbeat';
                $ob12['interval'] = $event['interval'];
                break;
            default:
                $ob12['detail_type'] = $event['meta_event_type'];
                $ob12 = $this->parseExtendedKeys($ob12, $event);
        }
        $ob12['sub_type'] = $ob12['detail_type'] === 'connect' ? '' : ($event['sub_type'] ?? '');
        return $ob12;
    }

    /**
     * 将 OneBot 11 / go-cqhttp 事件数组中的其他字段进行前缀化
     *
     * @param array $ob12  OneBot 12 事件数组
     * @param array $event OneBot 11 / go-cqhttp 事件数组
     */
    public function parseExtendedKeys(array $ob12, array $event): array
    {
        foreach ($event as $k => $v) {
            /*
            其他事件转换规则：
            1. 'post_type', 'notice_type', 'request_type', 'meta_event_type', 'time', 'self_id' 这几个字段忽略
            2. 现有 ID 类，'user_id', 'group_id', 'channel_id', 'guild_id', 'operator_id', 'message_id' 这几个 id 类的需要取字符串值
            3. 'sub_type' 如果值不为空，则直接加上前缀，否则保持空着
            4. 其他字段，统一加上前缀，值不变
             */
            $result = match ($k) {
                'post_type', 'notice_type', 'request_type', 'meta_event_type', 'time', 'self_id' => null,
                'user_id', 'group_id', 'channel_id', 'guild_id', 'operator_id', 'message_id' => [$k, strval($v)],
                'sub_type' => $v === '' ? [$k, $v] : ($event['post_type'] === 'meta_event' ? [$k, $v] : [$k, 'qq.' . $v]),
                default => ['qq.' . $k, $v],
            };
            if ($result !== null) {
                $ob12[$result[0]] = $result[1];
            }
        }
        return $ob12;
    }

}
