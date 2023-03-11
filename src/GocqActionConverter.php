<?php

declare(strict_types=1);

namespace GocqAdapter;

use OneBot\Util\Singleton;
use OneBot\V12\Object\Action;
use OneBot\V12\Object\ActionResponse;
use OneBot\V12\Object\MessageSegment;
use ZM\Exception\OneBot12Exception;

class GocqActionConverter
{
    use Singleton;

    /**
     * 将 Action 转换为 OneBot 11 的 API 数组
     *
     * @param  Action            $action OneBot 12 的 Action 对象
     * @return array             OneBot 11 的 API 数组
     * @throws OneBot12Exception
     */
    public function convertAction12To11(Action $action): array
    {
        $act = '';
        $params = [];
        $echo = $action->echo;
        switch ($action->action) {
            case 'send_message':
                switch ($action->params['detail_type'] ?? '') {
                    case 'group':
                        $act = 'send_group_msg';
                        $params['group_id'] = $action->params['group_id'];
                        $params['message'] = $this->parseSegments12To11($action->params['message']);
                        break;
                    case 'private':
                        $act = 'send_private_msg';
                        $params['user_id'] = $action->params['user_id'];
                        if (isset($action->params['group_id'])) {
                            $params['group_id'] = $action->params['group_id'];
                        }
                        $params['message'] = $this->parseSegments12To11($action->params['message']);
                        break;
                    default:
                        throw new OneBot12Exception('Unsupported detail_type for gocq action');
                }
                break;
            case 'delete_message':
                $act = 'delete_msg';
                $params['message_id'] = $action->params['message_id'];
                break;
            case 'get_self_info':
                $act = 'get_login_info';
                break;
            case 'get_user_info':
                $act = 'get_stranger_info';
                $params['user_id'] = $action->params['user_id'];
                break;
            case 'get_friend_list':
            case 'get_group_info':
            case 'get_group_list':
            case 'get_group_member_info':
            case 'get_group_member_list':
            case 'set_group_name':
                $act = $action->action;
                $params = $action->params;
                break;
            case 'leave_group':
                $act = 'set_group_leave';
                $params = $action->params;
                break;
            case 'upload_file':
                // OneBot 11 / go-cqhttp 只支持 url 方式上传文件
                if ($action->params['type'] !== 'url') {
                    throw new OneBot12Exception('OneBot 11 / go-cqhttp only support url uploader');
                }
                $act = 'download_file';
                $params = [
                    'url' => $action->params['url'],
                    'headers' => $action->params['headers'] ?? [],
                    'thread_count' => $action->params['thread_count'] ?? 1,
                ];
                break;
            case 'get_version':
                $act = 'get_version_info';
                $params = $action->params;
                break;
            default:
                // qq. 开头的动作，一律当作 gocq 的其他事件，这时候 params 原封不动发出
                if (str_starts_with($action->action, 'qq.')) {
                    $act = substr($action->action, 3);
                    $params = $action->params;
                } else {
                    throw new OneBot12Exception('Current action cannot send to gocq: ' . $action->action);
                }
                break;
        }
        return ['action' => $act, 'params' => $params, 'echo' => $echo];
    }

    public function convertActionResponse11To12(array $response, array $action): ActionResponse
    {
        $response_obj = new ActionResponse();
        $response_obj->status = $response['status'];
        $response_obj->retcode = GocqRetcodeConverter::getInstance()->convertRetCode11To12($response['retcode']);
        $response_obj->message = $response['msg'] ?? '';
        $response_obj->echo = $response['echo'] ?? null;
        // 接下来判断 action
        if ($response_obj->retcode !== 0) {
            return $response_obj;
        }
        switch ($action['action']) {
            case 'send_group_msg':
            case 'send_private_msg':
                $response_obj->data = [
                    'message_id' => $response['data']['message_id'],
                ];
                break;
            case 'get_stranger_info':
                $response_obj->data = [
                    'user_id' => $response['data']['user_id'],
                    'user_name' => $response['data']['nickname'],
                    'user_displayname' => $response['data']['nickname'],
                    'user_remark' => '',
                ];
                break;
            case 'get_login_info':
                $response_obj->data = [
                    'user_id' => $response['data']['user_id'],
                    'user_name' => $response['data']['nickname'],
                    'user_displayname' => $response['data']['nickname'],
                ];
                break;
            case 'get_friend_list':
                $response_obj->data = [];
                foreach ($response['data'] as $friend) {
                    $response_obj->data[] = [
                        'user_id' => $friend['user_id'],
                        'user_name' => $friend['nickname'],
                        'user_displayname' => $friend['nickname'],
                        'user_remark' => $friend['remark'],
                    ];
                }
                break;
            case 'get_group_info':
            case 'get_group_list':
                $response_obj->data = $response['data'];
                break;
            case 'get_group_member_info':
                $response_obj->data = [
                    'user_id' => $response['data']['user_id'],
                    'user_name' => $response['data']['nickname'],
                    'user_displayname' => $response['data']['card'] ?? $response['data']['nickname'],
                ];
                foreach ($response['data'] as $kss => $vss) {
                    if (in_array($kss, ['user_id', 'group_id'])) {
                        continue;
                    }
                    $response_obj->data['qq.' . $kss] = $vss;
                }
                break;
            case 'get_group_member_list':
                foreach ($response['data'] as $member) {
                    $dt = [
                        'user_id' => $member['user_id'],
                        'user_name' => $member['nickname'],
                        'user_displayname' => $member['card'] ?? $member['nickname'],
                    ];
                    foreach ($member as $kss => $vss) {
                        if (in_array($kss, ['user_id', 'group_id'])) {
                            continue;
                        }
                        $dt['qq.' . $kss] = $vss;
                    }
                    $response_obj->data[] = $dt;
                }
                break;

            case 'download_file':
                $response_obj->data = [
                    'file_id' => $response['data']['file'],
                ];
                break;
            case 'get_version_info':
                $response_obj->data = [
                    'impl' => $response['data']['app_name'] . ' (go-cqhttp-adapter converted)',
                    'version' => $response['data']['app_version'],
                    'onebot_version' => '12',
                ];
                break;
            case 'set_group_name':
            case 'set_group_leave':
            default:
                $response_obj->data = $response['data'] ?? [];
                break;
        }
        return $response_obj;
    }

    /**
     * @param  MessageSegment[] $message 消息段
     * @return array            OneBot 11 的消息段
     */
    private function parseSegments12To11(array $message): array
    {
        $msgs = [];
        foreach ($message as $v) {
            if (is_array($v)) {
                $v = segment($v['type'], $v['data'] ?? []);
            }
            $msgs[] = GocqSegmentConverter::getInstance()->parseSegment12To11($v);
        }
        return $msgs;
    }
}
