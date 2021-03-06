<?php
/**
 * Created by PhpStorm.
 * User: 63254
 * Date: 2017/11/26
 * Time: 1:49
 */

namespace app\api\controller\v1;

use app\api\controller\BaseController;
use app\api\exception\ParameterException;
use app\api\exception\UpdateException;
use app\api\exception\UserException;
use app\api\exception\UserExistException;
use app\api\model\Fishot_user as UserModel;
use app\api\model\Image;
use app\api\model\Fishot_relatedmessage as RelatedModel;
use app\api\model\Fishot_sharemember as MemberModel;
use app\api\model\Fishot_sharealbum as AlbumModel;
use app\api\model\Fishot_friend as FriendModel;
use app\api\service\UserToken as TokenService;
use app\api\service\Token;
use think\Request;

class User extends BaseController
{
    //前置验证
    protected $beforeActionList = [
        'checkExclusiveScope' => ['only' => 'getuserinfo,showfriend,buildgroup,searchuser,addfriend,change_sign,show_sign,show_background,change_background'],
    ];

    /*
     * 获取用户相片数和相册数
     * @token    用户的token令牌
     * @throws   UserException
     * */
    public function getUserInfo()
    {
        $uid = TokenService::getCurrentUid();
        $user = UserModel::get($uid);
        if (!$user) {
            throw new UserException();
        }
        $msg = [
            'photo_count' => (int)$user['photo_count'],
            'album_count' => (int)$user['album_count']
        ];
        return json_encode([
            'code' => 200,
            'msg' => $msg
        ]);
    }

    /*
     * 得到 相关信息 这个信息
     * @param    id = 1代表关于鱼拍  id = 2代表使用说明
     * */
    public function getRelatedInformation($id)
    {
        //判断传过来的id是否合法
        if($id != 1 && $id != 2){
            throw new ParameterException();
        }
        $related_information['message'] = RelatedModel::get(1);
        if ($id==1){
            $msg = [
                'relatedMessage' => $related_information['message']['about_fishot']
            ];
        }else{
            $msg = [
                'relatedMessage' => $related_information['message']['use_statement']
            ];
        }

        return json_encode([
            'code' => 200,
            'msg' => $msg
        ]);
    }


    /*
     * 显示这个人的朋友信息
     * @param    id 代表该用户的id
     * */
    public function showFriend($size, $page)
    {
        $id = Token::getCurrentUid();
        $friend = new FriendModel();
        $user = new UserModel();
        if (!preg_match("/^[0-9]+$/",$size) || !preg_match("/^[0-9]+$/",$page) || (int)$page<0 ){
            throw new ParameterException();
        }
        if ((int)$page == 0 && (int)$size == 0){
            //查所有
            $friend_id = $friend->where('user_id', '=', $id)
                ->field('friend_id')
                ->select();
        }else{
            //分页查
            $page = ((int)$page-1)*(int)$size;
            $friend_id = $friend->where('user_id', '=', $id)
                ->limit($page,$size)
                ->field('friend_id')
                ->select();
        }
        if ($friend_id) {
            $result = [];
            for ($i = 0; $i < count($friend_id); $i++) {
                $single_message = $user->where('id', '=', $friend_id[$i]['friend_id'])
                    ->field('id,username,portrait')
                    ->select();
                if ($single_message) {
                    $result[$i] = [
                        'username' => $single_message[0]['username'],
                        'portrait' => $single_message[0]['portrait']
                    ];
                } else {
                    throw new ParameterException();
                }
            }
            return json_encode([
                'code' => 200,
                'msg' => $result
            ]);
        } else {
            throw new ParameterException();
        }
    }

    /*
     * 将好友拉进相册
     * @param    id 表示所加好友id ,n表示有多少个id，[id1，idn]
     * */
    public function buildGroup($request)
    {
        $uid = Token::getCurrentUid();
        // 建立一个群
        $album = new AlbumModel();
        $album_id = time() . $uid;
        $album_id = substr($album_id,2,9);
        $result_album = $album->data([
            'id' => (int)$album_id,
            'main_speaker' => (int)$uid,
            'group_name' => '分享相册',
        ])->save();
        if (!$result_album) {
            throw new ParameterException();
        }
        // validate
        foreach ($request as $k => $v) {
            if (!preg_match("/^[0-9]+$/",$v) || (int)$v <= 0) {
                throw new ParameterException([
                    'msg' => 'id需为正整数'
                ]);
            }
            // 先判断他传过来的一定是这个id+数字
            if (preg_match('/^id[0-9]+$/', $k)) {
                $member = new MemberModel();
                $result = $member->data([
                    'group_id' => $album_id,
                    'user_id' => $v
                ])->save();
                if (!$result) {
                    throw new ParameterException();
                }
            }else{
                throw new ParameterException();
            }
        }
        return json_encode([
            'code' => 200,
            'msg' =>0
        ]);
    }

    /*
     * 搜索账号
     * @param    identity 账号
     * */
    public function searchUser($indentity)
    {
        //账号必须由字符和数字或下划线组成
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $indentity)){
            throw new ParameterException();
        }
        $user = new UserModel();
        $info = $user->where('identity','like','%'.$indentity.'%')
            ->field('username,portrait,id')
            ->select();
        if (!$info){
            throw new ParameterException();
        }
        //现在是默认唯一用户名
        $msg = [
            'username' => $info[0]['username'],
            'portrait' => $info[0]['portrait'],
            'id' => (int)$info[0]['id']
        ];
        return json_encode([
            'code' => 200,
            'msg' => $msg
        ]);
    }

    /*
     * 添加好友
     * @param    id 朋友的id
     * */
    public function addFriend($friend_id)
    {
        if (!preg_match("/^[0-9]+$/",$friend_id)) throw new ParameterException();
        $uid = Token::getCurrentUid();
        if ($uid == $friend_id) throw new ParameterException();
        //检查这个id是否存在
        $User = new UserModel();
        $result = $User
            ->where('id','=',$friend_id)
            ->field('id')
            ->select();
        if(!$result) throw new UserException();
        $friend = new FriendModel();
        $info = $friend->data([
            'user_id' => $uid,
            'friend_id' => $friend_id,
        ])
        ->save();
        if (!$info) throw new ParameterException();
        return json_encode([
            'code' => 200,
            'msg' => 0
        ]);
    }

    public function change_sign($sign=''){
        //拿到用户的id
        $uid = Token::getCurrentUid();
        $User = new UserModel();
        //防XSS
        $sign = strip_tags($sign);
        $sign = htmlspecialchars($sign);
        $result = $User->where('id','=',$uid)
            ->update([
                'personality_signature' => $sign
            ]);
        if (!$result) throw new UpdateException([
            'msg' => '更新出错，可能是身份有误！'
        ]);
        return json_encode([
            'code' => 200,
            'msg' => 0
        ]);
    }

    /**
     * 显示个性签名
     * @return string
     * @throws ParameterException
     */

    public function show_sign(){
        //拿用户id
        $uid = Token::getCurrentUid();
        $User = new UserModel();
        $result = $User->where('id','=',$uid)
            ->field('personality_signature')
            ->find();
        if (!$result){
            throw new ParameterException([
                'msg' => '未找到用户，身份出错！'
            ]);
        }
        return json_encode([
            'code' => 200,
            'msg' => $result['personality_signature']
        ]);
    }

    /**
     * 展示背景图
     * @return string
     * @throws ParameterException
     */

    public function show_background(){
        //拿用户id
        $uid = Token::getCurrentUid();
        $User = new UserModel();
        $result = $User->where('id','=',$uid)
            ->field('background')
            ->find();
        if (!$result){
            throw new ParameterException([
                'msg' => '未找到用户，身份出错！'
            ]);
        }
        $url = config('setting.image_root').$result['background'];
        return json_encode([
            'code' => 200,
            'msg' => $url
        ]);
    }

    /**
     * 修改背景图
     * @return string
     * @throws ParameterException
     * @throws UpdateException
     * @throws UserExistException
     */

    public function change_background(){
        $image = new Image();
        $url = $image->upload_image('photo');

        //拿用户id
        $uid = Token::getCurrentUid();
        $User = new UserModel();
        $u = $User->where('id','=',$uid)
            ->field('background')
            ->find();
        $result = $User->where('id','=',$uid)
            ->update([
                'background' => $url
            ]);
        if (!$result){
            if (is_file(COMMON_PATH."/".$url)){
                unlink(COMMON_PATH."/".$url);
            }
            throw new UpdateException([
                'msg' => '更新出错，身份不正确！'
            ]);
        }
        if ($u['background'] != 'upload/default.png'){
            if (is_file(COMMON_PATH."/".$u['background'])){
                unlink(COMMON_PATH."/".$u['background']);
            }
        }
        $new_url = config('setting.image_root').$url;
        return json_encode([
            'code' => 200,
            'msg' => $new_url
        ]);
    }

}

