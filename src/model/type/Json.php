<?php

declare (strict_types = 1);

namespace think\model\type;

use think\model\contract\Typeable;

class Json implements Typeable
{
    protected $data;

    public static function from(mixed $value, $assoc = true)
    {
        $static = new static();
        $static->data($value, $assoc);
        return $static;
    }

    public function data($data, bool $assoc = true)
    {
        if (is_string($data) && json_validate($data)) {
            $data = json_decode($data, $assoc);
        }
        $this->data = $data;
    }

    public function getData()
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function value()
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE);
    }
}
