<?php

declare(strict_types=1);

namespace GocqAdapter;

use OneBot\Util\Singleton;
use OneBot\V12\Object\MessageSegment;

class GocqSegmentConverter
{
    use Singleton;

    /**
     * 将字符串转换为消息段格式，并且按照 11 -> 12 进行兼容性转换
     *
     * @param string $msg 带 CQ 码的字符串
     */
    public function strToSegments(string $msg): array
    {
        $segments = [];
        // 循环找 CQ 码
        while ($msg !== '') {
            $before = mb_strstr($msg, '[CQ:', true);
            $after = mb_strstr($msg, '[CQ:');
            // 找不到 CQ 码，直接返回原文本
            if ($before === false) {
                $segments[] = MessageSegment::text($msg);
                break;
            }
            // 找下 ]，没找到的话返回消息
            if (($close = mb_strpos($after, ']')) === false) {
                $segments[] = MessageSegment::text($msg);
                break;
            }
            // 这里拿到右括号了，我们读取左括号和右括号之间的内容进行解析
            $cq = mb_substr($after, 4, $close - 4);
            // 读取到的 CQ 码 type
            $cqs = explode(',', $cq);
            $type = array_shift($cqs);
            // 剩下的都是参数，我们用等于号再次分割可以获得kv
            $params = [];
            foreach ($cqs as $v) {
                $kv = explode('=', $v);
                $key = array_shift($kv);
                $params[$key] = $this->cqDecode(implode('=', $kv));
            }
            // 将 11 的消息段转换为 12 的消息段
            [$type, $params] = $this->parseSegment11To12($type, $params);
            // CQ 码前面有文本，当作一个消息段
            if ($before !== '') {
                $segments[] = MessageSegment::text($before);
            }
            $segments[] = new MessageSegment($type, $params);
            $msg = mb_substr($after, $close + 1);
        }
        return $segments;
    }

    /**
     * 反转义 CQ 码特殊字符（仅参数内容）
     *
     * @param string $content 内容
     */
    public function cqDecode(string $content): string
    {
        return str_replace(['&amp;', '&#91;', '&#93;', '&#44;'], ['&', '[', ']', ','], $content);
    }

    /**
     * 将 OneBot 11 消息段转换为 OneBot 12 消息段
     *
     * @param string $type 类型
     * @param array  $data 参数
     */
    public function parseSegment11To12(string $type, array $data): array
    {
        switch ($type) {
            case 'text':
                return [$type, $data];
            case 'at':
                $qq = $data['qq'];
                unset($data['qq']);
                if ($qq === 'all') {
                    $type = 'mention_all';
                } else {
                    $type = 'mention';
                    $data['user_id'] = $qq;
                }
                break;
            case 'video':
            case 'image':
                // 使用 file 字段当作 file_id
                $file = $data['file'];
                unset($data['file']);
                $data['file_id'] = $file;
                break;
            case 'record':
                $type = 'voice';
                // 使用 file 字段当作 file_id
                $file = $data['file'];
                unset($data['file']);
                $data['file_id'] = $file;
                break;
            case 'location':
                $data_old = $data;
                $data = [
                    'latitude' => floatval($data_old['lat']),
                    'longitude' => floatval($data_old['lon']),
                    'title' => $data_old['title'] ?? '',
                    'content' => $data_old['content'] ?? '',
                ];
                break;
            case 'reply':
                $id = $data['id'];
                unset($data['id']);
                $data['message_id'] = $id;
                if (isset($data['qq'])) {
                    $qq = $data['qq'];
                    unset($data['qq']);
                    $data['user_id'] = strval($qq);
                }
                break;
            default:
                $type = 'qq.' . $type;
                break;
        }
        return [$type, $data];
    }

    /**
     * 将 OneBot 消息段由 12 -> 11
     * @param  MessageSegment $segment OneBot 12 的消息段
     * @return array          OneBot 11 的消息段
     */
    public function parseSegment12To11(MessageSegment $segment): array
    {
        switch ($segment->type) {
            case 'text':
                return ['type' => 'text', 'data' => ['text' => $segment->data['text']]];
            case 'mention':
                return ['type' => 'at', 'data' => ['qq' => $segment->data['user_id']]];
            case 'mention_all':
                return ['type' => 'at', 'data' => ['qq' => 'all']];
            case 'image':
                return ['type' => 'image', 'data' => ['file' => $segment->data['file_id'], 'url' => $segment->data['url'] ?? '']];
            case 'voice':
                return ['type' => 'record', 'data' => ['file' => $segment->data['file_id'], 'url' => $segment->data['url'] ?? '']];
            case 'video':
                return ['type' => 'video', 'data' => ['file' => $segment->data['file_id'], 'url' => $segment->data['url'] ?? '']];
            case 'location':
                return ['type' => 'location', 'data' => [
                    'lat' => $segment->data['latitude'],
                    'lon' => $segment->data['longitude'],
                    'title' => $segment->data['title'],
                    'content' => $segment->data['content'],
                ]];
            case 'reply':
                $data = ['id' => $segment->data['message_id']];
                if (isset($segment->data['user_id'])) {
                    $data['qq'] = $segment->data['user_id'];
                }
                return ['type' => 'reply', 'data' => $data];
            default:
                if (str_starts_with($segment->type, 'qq.')) {
                    $type = substr($segment->type, 3);
                } else {
                    $type = $segment->type;
                }
                $data = $segment->data;
                return ['type' => $type, 'data' => $data];
        }
    }
}
