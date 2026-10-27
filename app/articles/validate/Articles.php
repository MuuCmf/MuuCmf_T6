<?php
namespace app\articles\validate;
 
use think\Validate;
 
/**
 * 表单验证器
 */
class Articles extends Validate
{
    protected $rule = [
        'title'  =>  'require',
        'description' =>  'require',
        'category_id' => 'require|min:1',
    ];
    
    protected $message  =   [
        'title.require' =>  '标题不能为空',
        'description.require' => '简短描述不能为空',
        'category_id.require' => '请选择分类',
        'category_id.min' => '请选择分类',
    ];
    
    protected $scene = [
        'edit'   =>  ['title','description','category_id'],
    ];
}
