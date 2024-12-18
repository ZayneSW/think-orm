<?php

declare (strict_types = 1);

namespace think\model\type;

class Date extends DateTime
{
    protected $data;

    public static function from(mixed $value)
    {
        $static = new static();
        $static->data($value, 'Y-m-d');
        return $static;
    }
}
