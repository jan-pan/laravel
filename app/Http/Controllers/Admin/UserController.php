<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ResourceController;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Role;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\Request as ValidateRequest;

class UserController extends Controller
{
    use ResourceController; //资源控制器

    /**
    * 模型绑定
    * MenuController constructor.
    * 参数: Menu $bindModel
    */
    public function __construct(User $bindModel){
        $this->bindModel = $bindModel;
    }

    /**
    * 新增或修改,验证规则获取
    * 返回: array
    */
    protected function getValidateRule(){
        return ['uname'=>'sometimes|required|alpha_dash|between:6,18|unique:users,uname','password'=>'sometimes|required|digits_between:6,18','name'=>'required','email'=>'sometimes|required|email|unique:users,email','mobile_phone'=>'sometimes|required|mobile_phone|digits:11|unique:users,qq','qq'=>'integer'];
    }

    /**
     * 获取菜单数据
     * @return static
     */
    public function getList(){
        //树状结构限制排序
        if(isset($this->treeOrder)){
            $obj = $this->bindModel->orderBy('left_margin');
        }else{
            $obj = $this->bindModel;
        }
        $data = $obj->options(Request::only('where', 'order'))->paginate();
        //判断用户是否可被删除
        $data->load('admin'); //是后台用户不可直接被删除
        $param = [
            'order'=> Request::input('order',[]), //排序
            'where'=>Request::input('where',[]), //条件查询
        ];
        return collect($data)->merge($param);
    }

    /**
     * 编辑数据页面
     * @param null $id
     */
    public function getEdit($id=null){
        $data = [];
        if($id){
            $data['row'] = $this->bindModel->findOrFail($id);
            $admin = $data['row']->admin;
            if($admin){
                $admin->isAdmin = intval(!!$admin->id);
                $admin->roles;
            }
            $no_disabled = false;
            //判断该用户是否可被当前随便修改
            $main_roles = $this->rolesChildsId(true,false); //当前用户角色,数组
            //如果被编辑用户的角色在用户的
            foreach($main_roles as $main_role){
                $flog = true; //拥有编辑权限标记
                if(!isset($admin->roles)){
                    $no_disabled = true;
                    break;
                }
                foreach($admin->roles as $role){
                    if(!($role->left_margin>$main_role['left_margin'] && $role->right_margin<$main_role['right_margin'])){
                        $flog = false;
                    }
                }
                $flog AND $no_disabled = true;
            }
            $data['row']->disabled = !$no_disabled;
        }
        $has_roles = isset($admin->roles) ? $admin->roles: collect([]);
        //获取当前用户所有下属角色
        $self_roles = $this->rolesChildsId($this->bindModel->isSuper());
        //列出所有角色
        $data['roles'] = Role::orderBy('left_margin')->get()->each(function($item)use($self_roles,$has_roles){
            $item->checked = in_array($item->id,$has_roles->pluck('id')->toArray()); //当前用户拥有角色
            $item->disabled = !in_array($item->id,$self_roles); //添加用户角色是否可用
        });
        return Response::returns($data);
    }

    /**
     * 获取当前用户角色的子角色
     * @return array
     */
    protected function rolesChildsId($all=false,$id=true){
        $roles = Session::get('admin')['roles']; //当前用户角色
        $rolesChilds = collect([]);
        collect($roles)->each(function($item)use (&$rolesChilds){
            $rolesChilds->push(Role::find($item['id'])->childs());
        });
        $rolesChilds = $rolesChilds->collapse();
        $id AND $rolesChilds = $rolesChilds->pluck('id');
        if(!$all){
            return $rolesChilds->toArray();
        }
        return $id ? $rolesChilds->merge(collect($roles)->pluck('id'))->toArray() : $rolesChilds->merge(collect($roles)->toArray())->toArray();
    }

    /**
     * 执行修改或添加
     * 参数 Request $request
     */
    public function postEdit(ValidateRequest $request){
        //验证数据
        $this->validate($request,$this->getValidateRule());
        $id = $request->get('id');
        $has_roles = $this->rolesChildsId();
        //修改
        if($id){
            $user = $this->bindModel->find($id);
            $res =$user->update($request->all());
            if($res===false){
                return Response::returns(['alert'=>alert(['content'=>'修改失败!'],500)]);
            }
            if($request->input('admin.isAdmin')&& !$user->admin){ //设置成后台管理员
                $old_admin = Admin::withTrashed()->where('user_id','=',$id)->first();
                if($old_admin->toArray()){
                    $admin = $old_admin->restore(); //恢复数据
                }else{
                    $admin = $user->admin()->save(new Admin([]));
                }
                //修改用户角色
                $new_roles = collect($request->input('new_roles'))->filter(function($item) use($has_roles){
                    return $item >0 && in_array($item,$has_roles);
                })->toArray();
                $new_roles AND $admin->roles()->detach($new_roles);
                $admin->roles()->attach($new_roles);
            }elseif($user->admin){ //删除后台管理员
                $user->admin->delete();
            }
            return Response::returns(['alert'=>alert(['content'=>'修改成功!'])]);
        }

        //新增
        $user = $this->bindModel->create($request->except('id'));
        if($request->input('admin.isAdmin')){ //设置成后台管理员
            $admin = $user->admin()->save(new Admin([]));
            //添加用户角色
            $new_roles = collect($request->input('new_roles'))->filter(function($item) use($has_roles){
                return $item >0 && in_array($item,$has_roles);
            })->toArray();
            $admin->roles()->attach($new_roles);
        }
        if($user===false){
            return Response::returns(['alert'=>alert(['content'=>'新增失败!'],500)]);
        }
        return Response::returns(['alert'=>alert(['content'=>'新增成功!'])]);
    }

    /**
     * 删除数据
     * @return mixed
     */
    public function postDestroy(){
        //管理员不可直接被删除,过滤掉管理员用户
        $ids = $this->bindModel->whereIn('id',Request::input('ids',[]))
            ->get()->load('admin')->filter(function($item){
                return !$item->admin;
        })->pluck('id');
        $res = $this->bindModel->destroy($ids);
        if($res===false){
            return Response::returns(['alert'=>alert(['content'=>'删除失败!'],500)]);
        }
        return Response::returns(['alert'=>alert(['content'=>'删除成功!'])]);
    }

    /**
     * 后台角色用户组织架构图
     * 返回: mixed
     */
    public function getFramework(){
        //查询所有角色
        $data = Role::orderBy('left_margin')->get()->load('admins.user');
        //查询层级最多的节点数
        $level_max_num = Role::select(DB::raw('count(*) as role_count'))->groupBy('level')->orderBy('role_count','desc')->first()->role_count;
        $data->width = ($level_max_num+1)*200;
        $data->height = Role::max('level')*115+150;
        return Response::returns($data);
    }
}