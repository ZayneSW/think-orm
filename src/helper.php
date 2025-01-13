<?php

// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2023 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

//------------------------
// ThinkORM 助手函数
//-------------------------

use think\db\Express;
use think\db\Raw;

if (!function_exists('raw')) {
    function raw(string $value, array $bind = [])
    {
        return new Raw($value, $bind);
    }
}

if (!function_exists('inc')) {
    function inc($step)
    {
        return new Express('+', $step);
    }
}

if (!function_exists('dec')) {
    function dec($step)
    {
        return new Express('-', $step);
    }
}
