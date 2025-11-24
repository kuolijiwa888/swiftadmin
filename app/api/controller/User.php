<?php


namespace app\api\controller;

use app\ApiController;
use app\common\exception\OperateException;
use app\common\exception\user\UserException;
use app\common\library\ResultCode;
use app\common\library\Upload;
use app\common\model\system\User as UserModel;
use app\common\service\user\UserService;
use app\common\validate\system\User as UserValidate;
use PHPMailer\PHPMailer\Exception;
use Psr\SimpleCache\InvalidArgumentException;
use support\Request;
use support\Response;
use system\Random;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

/**
 * API用户登录
 */
class User extends ApiController
{
    /**
     * 需要登录
     * @var bool
     */
    public bool $needLogin = true;

    /**
     * 非鉴权方法
     * @var array
     */
    public array $noNeedLogin = ['register', 'login', 'mobileLogin', 'forgot'];

    /**
     * 构造方法
     */
    public function __construct()
    {
        parent::__construct();
        $this->model = new UserModel();
    }

    /**
     * 用户中心
     * @param Request $request
     * @return Response
     * @return array{"code":int,"msg":string,"data":array,"count":int,"url":string,"wait":int} 成功返回: {"code":200,"msg":"获取成功","data":{"id":1,"nickname":"用户名","mobile":"13800138000","email":"user@example.com"},"count":0,"url":"","wait":3}
     */
    public function center(Request $request): Response
    {
        $fields = $this->model->getVisibleFields();
        $userInfo = array_intersect_key($request->userInfo, array_flip($fields));
        return $this->success('获取成功', '', $userInfo);
    }

    /**
     * 修改用户资料
     * @param string $nickname 昵称
     * @return Response
     * @return array{"code":int,"msg":string,"data":mixed,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"修改成功","data":"","count":0,"url":"/","wait":3}
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws OperateException
     */
    public function profile(Request $request): Response
    {
        $post = request()->post();
        validate(UserValidate::class)->scene('nickname')->check($post);
        UserService::editProfile($post, $request->userId);
        return $this->success('修改成功', '/');
    }

    /**
     * 用户注册
     * @param string $nickname 用户名
     * @param string $mobile 手机号
     * @param string $email 邮箱
     * @param string $pwd 密码
     * @param string $captcha 验证码
     * @return Response
     * @return array{"code":int,"msg":string,"data":array,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"注册成功","data":{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."},"count":0,"url":"/","wait":3}
     * @throws DbException
     * @throws InvalidArgumentException
     * @throws OperateException
     */
    public function register(): Response
    {
        $post = request()->post();
        validate(UserValidate::class)->scene('register')->check($post);
        $result = UserService::register($post);
        return $this->success('注册成功', '/', ['token' => $result['token']]);
    }

    /**
     * 用户登录
     * @param string $nickname 用户名
     * @param string $pwd 密码
     * @return Response
     * @return array{"code":int,"msg":string,"data":array,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"登录成功","data":{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."},"count":0,"url":"/","wait":3}
     * @return array{"code":int,"msg":string,"data":mixed,"url":string,"wait":int} 失败返回:{"code":101,"msg":"请输入用户名或密码","data":"","url":"","wait":3}
     * @throws InvalidArgumentException
     * @throws OperateException
     */
    public function login(): Response
    {
        $nickname = input('nickname');
        $password = input('pwd');
        if (!$nickname || !$password) {
            return $this->error('请输入用户名或密码');
        }
        $result = UserService::accountLogin($nickname, $password);
        return $this->success('登录成功', '/', ['token' => $result['token']]);
    }

    /**
     * 手机号登录
     * @param string $mobile 手机号
     * @param string $captcha 验证码
     * @return Response
     * @return array{"code":int,"msg":string,"data":array,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"登录成功","data":{"token":"eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."},"count":0,"url":"/","wait":3}
     * @throws DataNotFoundException
     * @throws DbException
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     * @throws OperateException
     */
    public function mobileLogin(): Response
    {
        $mobile = input('mobile');
        $captcha = input('captcha');
        $result = UserService::mobileLogin($mobile, $captcha);
        return $this->success('登录成功', '/', ['token' => $result['token']]);
    }

    /**
     * 修改密码
     * @param string $oldpwd 旧密码
     * @param string $newpwd 新密码
     * @return Response
     * @return array{"code":int,"msg":string,"data":mixed,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"修改密码成功！","data":"","count":0,"url":"","wait":3}
     * @throws OperateException
     */
    public function changePwd(Request $request): Response
    {
        $post = request()->post();
        UserService::changePwd($post, $request->userId);
        return $this->success('修改密码成功！');
    }

    /**
     * 找回密码
     * @param string $mobile 手机号
     * @param string $captcha 验证码
     * @param string $password 新密码
     * @return Response
     * @return array{"code":int,"msg":string,"data":mixed,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"修改密码成功！","data":"","count":0,"url":"","wait":3}
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws OperateException
     */
    public function forgot(): Response
    {
        $post = request()->post();
        validate(UserValidate::class)->check($post);
        UserService::forgotPwd($post);
        return $this->success('修改密码成功！');
    }

    /**
     * 获取消息列表
     * @param int $page 页码，默认1
     * @param int $limit 每页数量，默认10
     * @param string $status 状态，默认all（all-全部，read-已读，unread-未读）
     * @return Response
     * @return array{"code":int,"msg":string,"data":array,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"查询成功","data":[{"id":1,"title":"消息标题","content":"消息内容","status":"unread","create_time":"2024-01-01 12:00:00"}],"count":10,"url":"/","wait":3}
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function message(Request $request): Response
    {
        $page = input('page', 1);
        $limit = input('limit', 1);
        $status = input('status', 'all');
        $where[] = ['user_id', '=', $request->userId];
        if ($status !== 'all') {
            $where[] = ['status', '=', $status];
        }
        list($list, $count) = UserService::listMessage($limit, $page, $where);
        return $this->success('查询成功', "/", $list, $count);
    }

    /**
     * 查看消息
     * @param int $id 消息ID
     * @return Response
     * @return array{"code":int,"msg":string,"data":array,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"查询成功","data":{"id":1,"title":"消息标题","content":"消息内容","status":"read","create_time":"2024-01-01 12:00:00"},"count":0,"url":"/","wait":3}
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws OperateException
     */
    public function viewMessage(Request $request): Response
    {
        $id = input('id', 0);
        $result = UserService::viewMessage($id, $request->userId);
        return $this->success('查询成功', "/", $result);
    }

    /**
     * 批量操作消息
     * @param string|array $id 消息ID，多个用逗号分隔或数组
     * @param string $type 操作类型，默认del（del-删除，read-标记已读，unread-标记未读）
     * @return Response
     * @return array{"code":int,"msg":string,"data":mixed,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"操作成功","data":"","count":0,"url":"","wait":3}
     * @return array{"code":int,"msg":string,"data":mixed,"url":string,"wait":int} 失败返回:{"code":101,"msg":"操作失败原因","data":"","url":"","wait":3}
     */
    public function batchMessage(Request $request): Response
    {
        $ids = input('id');
        $type = input('type', 'del');
        try {
            UserService::batchMessage($ids, $type, $request->userId);
        } catch (UserException $e) {
            return $this->error($e->getMessage());
        }
        return $this->success('操作成功');
    }

    /**
     * 申请APP_KEY
     * @param Request $request
     * @return Response
     * @return array{"code":int,"msg":string,"data":array,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"申请成功！","data":{"id":1,"app_id":10001,"app_secret":"abcdefghijklmnopqrstuv"},"count":0,"url":"/","wait":3}
     * @return array{"code":int,"msg":string,"data":mixed,"url":string,"wait":int} 失败返回:{"code":101,"msg":"申请失败！","data":"","url":"","wait":3}
     */
    public function appid(Request $request): Response
    {
        $data['id'] = $request->userId;
        $data['app_id'] = 10000 + $request->userId;
        $data['app_secret'] = Random::alpha(22);
        if ($this->model->update($data)) {
            return $this->success('申请成功！', '/', $data);
        }

        return $this->error('申请失败！');
    }

    /**
     * 修改邮箱地址
     * @param string $email 新邮箱地址
     * @param string $captcha 验证码
     * @param string $event 事件类型，默认change（change-修改邮箱）
     * @return Response
     * @return array{"code":int,"msg":string,"data":mixed,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"修改邮箱成功！","data":"","count":0,"url":"","wait":3}
     * @throws Exception|UserException|OperateException
     */
    public function changeEmail(Request $request): Response
    {
        $email = input('email');
        $captcha = input('captcha');
        $event = input('event');
        UserService::changeEmail($email, $captcha, $event, $request->userId);
        return $this->success('修改邮箱成功！');
    }

    /**
     * 修改手机号
     * @param string $mobile 新手机号
     * @param string $captcha 验证码
     * @param string $event 事件类型，默认change（change-修改手机号）
     * @return Response
     * @return array{"code":int,"msg":string,"data":mixed,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"修改手机号成功！","data":"","count":0,"url":"","wait":3}
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws OperateException
     * @throws UserException
     */
    public function changeMobile(Request $request): Response
    {
        $mobile = input('mobile');
        $captcha = input('captcha');
        $event = input('event');
        UserService::changeMobile($mobile, $captcha, $event, $request->userId);
        return $this->success('修改手机号成功！');
    }

    /**
     * 意见反馈
     * @param string $type 反馈类型
     * @param string $content 反馈内容
     * @return Response
     * @return array{"code":int,"msg":string,"data":mixed,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"反馈成功","data":"","count":0,"url":"","wait":3}
     * @return array{"code":int,"msg":string,"data":mixed,"url":string,"wait":int} 失败返回:{"code":101,"msg":"参数错误","data":"","url":"","wait":3}
     */
    public function feedback(): Response
    {
        $type = input('type', '');
        $content = input('content');
        if (empty($type) || empty($content)) {
            return $this->error('参数错误');
        }

        return $this->success('反馈成功');
    }

    /**
     * 文件上传
     * @param file $file 上传的文件
     * @return Response
     * @return array{"code":int,"msg":string,"data":array,"count":int,"url":string,"wait":int} 成功返回:{"code":200,"msg":"上传成功","data":{"url":"/upload/file.jpg","name":"file.jpg","size":1024},"count":0,"url":"","wait":3}
     * @return array{"code":int,"msg":string,"data":mixed,"url":string,"wait":int} 失败返回:{"code":101,"msg":"上传失败原因","data":"","url":"","wait":3}
     * @throws DataNotFoundException
     * @throws DbException
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException
     */
    public function upload(): Response
    {
        if (request()->isPost()) {
            $response = Upload::instance()->upload();
            if (empty($response)) {
                return $this->error(Upload::instance()->getError());
            }
            return json($response);
        }
        return json(ResultCode::SUCCESS);
    }
}
