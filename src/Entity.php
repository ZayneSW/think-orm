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

namespace think;

use ArrayAccess;
use BackedEnum;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;
use Stringable;
use think\contract\Arrayable;
use think\contract\Jsonable;
use think\db\Raw;
use think\helper\Str;
use think\model\Collection;
use think\model\contract\EnumTransform;
use think\model\contract\FieldTypeTransform;
use think\model\contract\Modelable;
use think\model\contract\Typeable;
use think\model\type\Date;
use think\model\type\DateTime;
use think\model\type\Json;
use WeakMap;

/**
 * Class Entity.
 */
abstract class Entity implements JsonSerializable, ArrayAccess, Arrayable, Jsonable, Modelable
{
    private static ?WeakMap $weakMap = null;
    private static array $_schema    = [];

    /**
     * 架构函数.
     *
     * @param array|object $data 实体模型数据
     * @param Model $model 模型连接对象
     */
    public function __construct(array | object $data = [], ?Model $model = null)
    {
        // 获取对应模型对象
        if (is_null($model)) {
            $class = $this->parseModel();
            $model = new $class;
        }

        // 获取实体模型参数
        $options = $this->getOptions();

        if (!self::$weakMap) {
            self::$weakMap = new WeakMap;
        }

        self::$weakMap[$this] = [
            'model'         => $model,
            'get'           => [],
            'data'          => [],
            'schema'        => [],
            'origin'        => [],
            'together'      => [],
            'allow'         => [],
            'update_time'   => $options['update_time'] ?? 'update_time',
            'create_time'   => $options['create_time'] ?? 'create_time',
            'type'          => $options['type'] ?? [],
            'virtual'       => $options['virtual'] ?? false,
            'readonly'      => $options['readonly'] ?? [],
            'disuse'        => $options['disuse'] ?? [],
            'hidden'        => $options['hidden'] ?? [],
            'visible'       => $options['visible'] ?? [],
            'append'        => $options['append'] ?? [],
            'mapping'       => $options['mapping'] ?? [],
            'strict'        => $options['strict'] ?? true,
            'relation_keys' => $options['relation_keys'] ?? [],
        ];

        $model->setEntity($this);
        // 初始化模型数据
        $this->initializeData($data);
    }

    /**
     * 在实体模型中定义 返回相关配置参数.
     *
     * @return array
     */
    protected function getOptions(): array
    {
        return [];
    }

    /**
     * 解析模型实例名称.
     *
     * @return string
     */
    protected function parseModel(): string
    {
        return str_replace('\\entity', '\\model', static::class);
    }

    /**
     * 获取模型实例.
     *
     * @return Model
     */
    public function model(): Model
    {
        $schema = self::$_schema[static::class];
        return self::$weakMap[$this]['model']->schema($schema);
    }

    /**
     * 初始化模型数据.
     *
     * @param array|object $data 实体模型数据
     * @param bool  $fromSave
     *
     * @return void
     */
    protected function initializeData(array | object $data, bool $fromSave = false)
    {
        // 分析数据
        $data = $this->parseData($data);
        // 获取字段列表
        $schema = $this->getFields();
        $fields = array_keys($schema);

        // 实体模型赋值
        foreach ($data as $name => $val) {
            if (in_array($name, self::$weakMap[$this]['disuse'])) {
                // 废弃字段
                continue;
            }

            if (!empty(self::$weakMap[$this]['mapping'])) {
                // 字段映射
                $key = array_search($name, self::$weakMap[$this]['mapping']);
                if (is_string($key)) {
                    $name = $key;
                }
            }

            $trueName = $this->getRealFieldName($name);
            if ($this->model()->getPk() == $trueName) {
                // 记录主键值
                $this->model()->setKey($val);
                $this->model()->exists(true);
            }

            if (in_array($trueName, $fields)) {
                // 读取数据后进行类型转换
                $value = $this->readTransform($val, $schema[$trueName] ?? 'string');

                // 数据赋值
                $this->$trueName = $value;
                // 记录原始数据
                $origin[$trueName] = $value;
            }
        }

        if (!empty($origin) && !$fromSave) {
            $this->setWeak('origin', $origin);
        }
    }

    protected function setWeak($name, $value)
    {
        self::$weakMap[$this][$name] = $value;
    }

    protected function getWeakData($name, $default = null)
    {
        return self::$weakMap[$this][$name] ?? $default;
    }

    protected function setWeakData($key, $name, $value)
    {
        self::$weakMap[$this][$key][$name] = $value;
    }

    /**
     * 获取实际字段名.
     * 严格模式下 完全和数据表字段对应一致（默认）
     * 非严格模式 统一转换为snake规范（支持驼峰规范读取）
     *
     * @param string $name  字段名
     *
     * @return mixed
     */
    protected function getRealFieldName(string $name)
    {
        if (!self::$weakMap[$this]['strict']) {
            return Str::snake($name);
        }

        return $name;
    }

    /**
     * 数据读取 类型转换.
     *
     * @param mixed        $value 值
     * @param string $type  要转换的类型
     *
     * @return mixed
     */
    protected function readTransform($value, string $type)
    {
        if (is_null($value)) {
            return;
        }

        $typeTransform = static function (string $type, $value, $model) {
            if (str_contains($type, '\\') && class_exists($type)) {
                if (is_subclass_of($type, Typeable::class)) {
                    $value = $type::from($value, $model);
                } elseif (is_subclass_of($type, FieldTypeTransform::class)) {
                    $value = $type::get($value, $model);
                } elseif (is_subclass_of($type, BackedEnum::class)) {
                    $value = $type::from($value);
                } else {
                    // 对象类型
                    $value = new $type($value);
                }
            }

            return $value;
        };

        return match ($type) {
            'string' => (string) $value,
            'int'       => (int) $value,
            'float'     => (float) $value,
            'bool'      => (bool) $value,
            'array'     => empty($value) ? [] : json_decode($value, true),
            'object'    => empty($value) ? new \stdClass() : json_decode($value),
            'json'      => $typeTransform(Json::class, $value, $this),
            'date'      => $typeTransform(Date::class, $value, $this),
            'datetime'  => $typeTransform(DateTime::class, $value, $this),
            'timestamp' => $typeTransform(Json::class, $value, $this),
            default     => $typeTransform($type, $value, $this),
        };
    }

    /**
     * 数据写入 类型转换.
     *
     * @param mixed        $value 值
     * @param string|array $type  要转换的类型
     *
     * @return mixed
     */
    protected function writeTransform($value, string $type)
    {
        if (null === $value) {
            return;
        }

        if ($value instanceof Raw) {
            return $value;
        }

        $typeTransform = static function (string $type, $value, $model) {
            if (str_contains($type, '\\') && class_exists($type)) {
                if (is_subclass_of($type, Typeable::class)) {
                    $value = $value->value($model);
                } elseif (is_subclass_of($type, FieldTypeTransform::class)) {
                    $value = $type::set($value, $model);
                } elseif ($value instanceof BackedEnum) {
                    $value = $value->value;
                } elseif ($value instanceof Stringable) {
                    $value = $value->__toString();
                }
            }

            return $value;
        };

        return match ($type) {
            'string'    => (string) $value,
            'int'       => (int) $value,
            'float'     => (float) $value,
            'bool'      => (bool) $value,
            'object'    => is_object($value) ? json_encode($value, JSON_FORCE_OBJECT) : $value,
            'array'     => json_encode((array) $value, JSON_UNESCAPED_UNICODE),
            'json'      => $typeTransform(Json::class, $value, $this),
            'date'      => $typeTransform(Date::class, $value, $this),
            'datetime'  => $typeTransform(DateTime::class, $value, $this),
            'timestamp' => $typeTransform(Json::class, $value, $this),
            default     => $typeTransform($type, $value, $this),
        };
    }

    /**
     * 获取数据表字段列表.
     *
     * @return array|string
     */
    protected function getFields(?string $field = null)
    {
        if (isset(self::$_schema[static::class])) {
            $schema = self::$_schema[static::class];
        } else {
            $class     = new ReflectionClass($this);
            $propertys = $class->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);
            $schema    = [];

            foreach ($propertys as $property) {
                $name = $this->getRealFieldName($property->getName());
                $type = $property->hasType() ? $property->getType()->getName() : 'string';

                $schema[$name] = $type;
            }

            if (empty($schema)) {
                // 获取数据表信息
                $model = self::$weakMap[$this]['model'];
                $fields = $model->getFieldsType($model->getTable());
                $schema = array_merge($fields, self::$weakMap[$this]['type'] ?: $model->getType());
            }

            self::$_schema[static::class] = $schema;
        }

        if ($field) {
            return $schema[$field] ?? 'string';
        }

        return $schema;
    }

    /**
     * 解析模型数据.
     *
     * @param array|object $data 数据
     *
     * @return array
     */
    protected function parseData(array | object $data): array
    {
        if ($data instanceof Model) {
            $data = array_merge($data->getData(), $data->getRelation());
        } elseif (is_object($data)) {
            $data = get_object_vars($data);
        }

        return $data;
    }

    /**
     * 关联数据写入或删除.
     *
     * @param array $relation 关联
     *
     * @return $this
     */
    public function together(array $relation)
    {
        $this->setWeak('together', $relation);

        return $this;
    }

    /**
     * 允许写入字段.
     *
     * @param array $allow 允许字段
     *
     * @return $this
     */
    public function allowField(array $allow)
    {
        $this->setWeak('allow', $allow);

        return $this;
    }

    /**
     * 强制写入或删除
     *
     * @param bool $force 强制更新
     *
     * @return $this
     */
    public function force(bool $force = true)
    {
        $this->model()->force($force);

        return $this;
    }

    /**
     * 新增数据是否使用Replace.
     *
     * @param bool $replace
     *
     * @return $this
     */
    public function replace(bool $replace = true)
    {
        $this->model()->replace($replace);

        return $this;
    }

    /**
     * 设置需要附加的输出属性.
     *
     * @param array $append 属性列表
     * @param bool  $merge  是否合并
     *
     * @return $this
     */
    public function append(array $append, bool $merge = false)
    {
        self::$weakMap[$this]['append'] = $merge ? array_merge(self::$weakMap[$this]['append'], $append) : $append;

        return $this;
    }

    /**
     * 设置需要隐藏的输出属性.
     *
     * @param array $hidden 属性列表
     * @param bool  $merge  是否合并
     *
     * @return $this
     */
    public function hidden(array $hidden, bool $merge = false)
    {
        self::$weakMap[$this]['hidden'] = $merge ? array_merge(self::$weakMap[$this]['hidden'], $hidden) : $hidden;

        return $this;
    }

    /**
     * 设置需要输出的属性.
     *
     * @param array $visible
     * @param bool  $merge   是否合并
     *
     * @return $this
     */
    public function visible(array $visible, bool $merge = false)
    {
        self::$weakMap[$this]['visible'] = $merge ? array_merge(self::$weakMap[$this]['visible'], $visible) : $visible;

        return $this;
    }

    /**
     * 设置属性的映射输出.
     *
     * @param array $map
     *
     * @return $this
     */
    public function mapping(array $map)
    {
        self::$weakMap[$this]['mapping'] = $map;

        return $this;
    }

    /**
     * 字段值增长
     *
     * @param string $field 字段名
     * @param float  $step  增长值
     *
     * @return $this
     */
    public function inc(string $field, float $step = 1)
    {
        $this->set($field, $this->get($field) + $step);
        return $this;
    }

    /**
     * 字段值减少.
     *
     * @param string $field 字段名
     * @param float  $step  增长值
     *
     * @return $this
     */
    public function dec(string $field, float $step = 1)
    {
        $this->set($field, $this->get($field) - $step);
        return $this;
    }

    /**
     * 保存模型实例数据.
     *
     * @param array|object $data 数据
     * @return bool
     */
    public function save(array | object $data = []): bool
    {
        if ($this->isVirtual()) {
            return true;
        }

        if (!empty($data)) {
            // 初始化模型数据
            $this->initializeData($data, true);
        }

        $data     = $this->getData();
        $origin   = $this->getOrigin();
        $allow    = $this->getWeakData('allow') ?: array_keys($this->getFields());
        $readonly = $this->getWeakData('readonly');
        $disuse   = $this->getWeakData('disuse');
        $allow    = array_diff($allow, $readonly, $disuse);
        $isUpdate = $this->model()->getKey() && !$this->model()->isForce();

        foreach ($data as $name => &$val) {
            if ($val instanceof Entity || is_subclass_of($this->getFields($name), Entity::class)) {
                $relations[$name] = $val;
                unset($data[$name]);
            } elseif ($val instanceof Collection || !in_array($name, $allow)) {
                // 禁止更新字段（包括只读、废弃和数据集）
                unset($data[$name]);
            } elseif ($isUpdate && $this->isNotRequireUpdate($name, $val, $origin)) {
                // 无需更新字段
                unset($data[$name]);
            } else {
                // 统一执行修改器或类型转换后写入
                $attr   = Str::studly($name);
                $method = 'set' . $attr . 'Attr';
                if (method_exists($this, $attr) && $set = $this->$attr()['set'] ?? '') {
                    $val = $set($val, $data);
                } elseif (method_exists($this, $method)) {
                    $val = $this->$method($val, $data);
                } else {
                    // 类型转换
                    $val = $this->writeTransform($val, $this->getFields($name));
                }
            }
        }

        if (empty($data)) {
            return false;
        }

        // 自动时间戳处理
        $this->autoDateTime($data, $isUpdate);
        $result = $this->model()->allowField($allow)->save($data);

        if (false === $result) {
            return false;
        }

        // 保存关联数据
        if (!empty($relations)) {
            $this->relationSave($relations);
        }

        return true;
    }

    /**
     * 时间字段自动写入.
     *
     * @param array $data 数据
     * @param bool $update 是否更新
     * @return void
     */
    protected function autoDateTime(array &$data, bool $update)
    {
        $dateTimeFields = [self::$weakMap[$this]['update_time']];
        if (!$update) {
            array_unshift($dateTimeFields, self::$weakMap[$this]['create_time']);
        }

        foreach ($dateTimeFields as $field) {
            if (is_string($field)) {
                $data[$field] = $this->getDateTime($field);
                $this->$field = $this->readTransform($data[$field], $this->getFields($field));
            }
        }
    }

    /**
     * 获取当前时间.
     *
     * @param string $field 字段名
     * @return void
     */
    protected function getDateTime(string $field)
    {
        $type = $this->getFields($field);
        if ('int' == $type) {
            $value = time();
        } elseif (is_subclass_of($type, Typeable::class)) {
            $value = $type::from('now', $this)->value();
        } elseif (str_contains($type, '\\')) {
            // 对象数据写入
            $obj = new $type();
            if ($obj instanceof Stringable) {
                // 对象数据写入
                $value = $obj->__toString();
            }
        } else {
            $value = DateTime::from('now', $this)->value();
        }
        return $value;
    }

    /**
     * 检查字段是否有更新（主键无需更新）.
     *
     * @param string $name 字段
     * @param mixed $val 值
     * @param array $origin 原始数据
     * @return bool
     */
    protected function isNotRequireUpdate(string $name, $val, array $origin): bool
    {
        return (array_key_exists($name, $origin) && $val === $origin[$name]) || $this->model()->getPk() == $name;
    }

    /**
     * 写入模型关联数据（一对一）.
     *
     * @param array $relations 数据
     * @return bool
     */
    protected function relationSave(array $relations = [])
    {
        foreach ($relations as $name => $relation) {
            if ($relation && in_array($name, $this->getWeakData('together'))) {
                $relationKey = $this->getRelationKey($name);
                if ($relationKey) {
                    $relation->$relationKey = $this->model()->getKey();
                }
                $relation->save();
            }
        }
    }

    /**
     * 删除模型关联数据（一对一）.
     *
     * @param array $relations 数据
     * @return void
     */
    protected function relationDelete(array $relations = [])
    {
        foreach ($relations as $name => $relation) {
            if ($relation && in_array($name, $this->getWeakData('together'))) {
                $relation->delete();
            }
        }
    }

    /**
     * 获取关联的外键名.
     *
     * @param string $relation 关联名
     * @return string|null
     */
    protected function getRelationKey(string $relation)
    {
        $relationKey = self::$weakMap[$this]['relation_keys'];
        return $relationKey[$relation] ?? null;
    }

    /**
     * 是否为虚拟模型（不能写入）.
     *
     * @return bool
     */
    public function isVirtual(): bool
    {
        return self::$weakMap[$this]['virtual'];
    }

    /**
     * 删除模型数据.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if ($this->isVirtual()) {
            return true;
        }

        foreach ($this->getData() as $name => $val) {
            if ($val instanceof Entity || $val instanceof Collection) {
                $relations[$name] = $val;
            }
        }

        $result = $this->model()->delete();

        if ($result) {
            // 删除关联数据
            if (!empty($relations)) {
                $this->relationDelete($relations);
            }
        }

        return true;
    }

    /**
     * 写入数据.
     *
     * @param array|object  $data 数据
     * @param array  $allowField  允许字段
     * @param bool   $replace     使用Replace
     * @return static
     */
    public static function create(array | object $data, array $allowField = [], bool $replace = false): Entity
    {
        $model = new static();

        if (!empty($allowField)) {
            $model->allowField($allowField);
        }

        $model->replace($replace);
        $model->save($data);

        return $model;
    }

    /**
     * 更新数据.
     *
     * @param array|object  $data 数据
     * @param mixed  $where       更新条件
     * @param array  $allowField  允许字段
     * @return static
     */
    public static function update(array | object $data, $where = [], array $allowField = []): Entity
    {
        $model = new static();

        if (!empty($allowField)) {
            $model->allowField($allowField);
        }

        if (!empty($where)) {
            $model->setUpdateWhere($where);
        }

        $model->exists(true);
        $model->save($data);

        return $model;
    }

    /**
     * 删除记录.
     *
     * @param mixed $data  主键列表 支持闭包查询条件
     * @param bool  $force 是否强制删除
     *
     * @return bool
     */
    public static function destroy($data, bool $force = false): bool
    {
        $entity = new static();
        if ($entity->isVirtual()) {
            return true;
        }

        return $entity->model()->destroy($data, $force);
    }

    /**
     * 设置主键值
     *
     * @param int|string $value 值
     * @return void
     */
    public function setKey($value)
    {
        $pk = $this->model()->getPk();
        if (is_string($pk)) {
            $this->$pk = $value;
        }
    }

    /**
     * 获取模型数据.
     *
     * @return array
     */
    public function getData(): array
    {
        $class = new class {
            function getPublicVars($object)
            {
                return get_object_vars($object);
            }
        };
        return array_merge($class->getPublicVars($this), self::$weakMap[$this]['data']);
    }

    /**
     * 重置模型数据.
     *
     * @param array $data
     *
     * @return $this
     */
    public function setData(array $data)
    {
        $this->initializeData($data);
    }

    /**
     * 获取原始数据.
     *
     * @param string|null $name 字段名
     * @return mixed
     */
    public function getOrigin(?string $name = null)
    {
        if ($name) {
            return self::$weakMap[$this]['origin'][$name] ?? null;
        }
        return self::$weakMap[$this]['origin'];
    }

    /**
     * 模型数据转数组.
     *
     * @param array $allow 允许输出字段
     * @return array
     */
    public function toArray(array $allow = []): array
    {
        $data = $this->getData();
        if (empty($allow)) {
            foreach (['visible', 'hidden', 'append'] as $convert) {
                ${$convert} = self::$weakMap[$this][$convert];
                foreach (${$convert} as $key => $val) {
                    if (is_string($key)) {
                        $relation[$key][$convert] = $val;
                        unset(${$convert}[$key]);
                    } elseif (str_contains($val, '.')) {
                        [$relName, $name]               = explode('.', $val);
                        $relation[$relName][$convert][] = $name;
                        unset(${$convert}[$key]);
                    }
                }
            }
            $allow = array_diff($visible ?: array_keys($data), $hidden);
        }

        foreach ($data as $name => &$item) {
            if ($item instanceof Entity || $item instanceof Collection) {
                if (!empty($relation[$name])) {
                    // 处理关联数据输出
                    foreach ($relation[$name] as $key => $val) {
                        $item->$key($val);
                    }
                }
                $item = $item->toarray();
            } elseif (!empty($allow) && !in_array($name, $allow)) {
                unset($data[$name]);
            } elseif ($item instanceof Typeable) {
                $item = $item->value();
            } elseif (is_subclass_of($item, EnumTransform::class)) {
                $item = $item->value();
            } elseif (isset(self::$weakMap[$this]['get'][$name])) {
                $item = self::$weakMap[$this]['get'][$name];
            } else {
                $method = 'get' . Str::studly($name) . 'Attr';
                if (method_exists($this, $method)) {
                    // 使用获取器转换输出
                    $item = $this->$method($item, $data);
                    $this->setWeakData('get', $name, $item);
                }
            }

            if (isset(self::$weakMap[$this]['mapping'][$name])) {
                // 检查字段映射
                $key        = self::$weakMap[$this]['mapping'][$name];
                $data[$key] = $data[$name];
                unset($data[$name]);
            }
        }

        // 输出额外属性 必须定义获取器
        foreach (self::$weakMap[$this]['append'] as $key) {
            $data[$key] = $this->get($key);
        }

        return $data;
    }

    /**
     * 判断数据是否为空.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->getData());
    }

    /**
     * 设置数据对象的值
     *
     * @param string $name  名称
     * @param mixed  $value 值
     *
     * @return void
     */
    public function set(string $name, $value): void
    {
        if (!empty(self::$weakMap[$this]['mapping'])) {
            $key = array_search($name, self::$weakMap[$this]['mapping']);
            if (is_string($key)) {
                $name = $key;
            }
        }
        $name = $this->getRealFieldName($name);
        if (property_exists($this, $name)) {
            $this->$name = $value;
        } else {
            $this->setWeakData('data', $name, $value);
        }

        if (isset(self::$weakMap[$this]['get'][$name])) {
            self::$weakMap[$this]['get'][$name] = null;
        }
    }

    /**
     * 获取数据对象的值（使用获取器）
     *
     * @param string $name 名称
     *
     * @return mixed
     */
    public function get(string $name)
    {
        if (isset(self::$weakMap[$this]['mapping'][$name])) {
            // 检查字段映射
            $name = self::$weakMap[$this]['mapping'][$name];
        }

        $name = $this->getRealFieldName($name);
        if (isset(self::$weakMap[$this]['get'][$name])) {
            return self::$weakMap[$this]['get'][$name];
        }

        $value  = $this->getValue($name);
        $attr   = Str::studly($name);
        $method = 'get' . $attr . 'Attr';
        if (method_exists($this, $attr) && $get = $this->$attr()['get'] ?? '') {
            $value = $get($value, $this->getData());
        } elseif (method_exists($this, $method)) {
            $value = $this->$method($value, $this->getData());
        } elseif (is_subclass_of($this->getFields($name), Entity::class) ||
            is_subclass_of($this->getFields($name), Collection::class)
        ) {
            // 动态获取关联数据
            $value = $this->model()->getRelation($name, true);
        }

        $this->setWeakData('get', $name, $value);
        return $value;
    }

    /**
     * 获取数据对象的值
     *
     * @param string $name 名称
     *
     * @return mixed
     */
    private function getValue(string $name)
    {
        if (property_exists($this, $name)) {
            return $this->$name ?? null;
        }
        return self::$weakMap[$this]['data'][$name] ?? null;
    }

    /**
     * 模型数据转Json.
     *
     * @param int $options json参数
     * @param array $allow 允许输出字段
     * @return string
     */
    public function tojson(int $options = JSON_UNESCAPED_UNICODE, array $allow = []): string
    {
        return json_encode($this->toarray($allow), $options);
    }

    /**
     * 获取属性
     *
     * @param string $name 名称
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->get($name);
    }

    /**
     * 设置数据
     *
     * @param string $name  名称
     * @param mixed  $value 值
     *
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $this->set($name, $value);
    }

    /**
     * 检测数据对象的值
     *
     * @param string $name 名称
     *
     * @return bool
     */
    public function __isset(string $name): bool
    {
        $name = $this->getRealFieldName($name);
        if (property_exists($this, $name)) {
            return isset($this->name);
        }
        return isset(self::$weakMap[$this]['data'][$name]);
    }

    /**
     * 销毁数据对象的值
     *
     * @param string $name 名称
     *
     * @return void
     */
    public function __unset(string $name): void
    {
        $name = $this->getRealFieldName($name);
        if (property_exists($this, $name)) {
            unset($this->name);
        } else {
            self::$weakMap[$this]['data'][$name] = null;
        }
    }

    public function __toString()
    {
        return $this->toJson();
    }

    public function __debugInfo()
    {
        return [
            'data'   => self::$weakMap[$this]['data'],
            'origin' => self::$weakMap[$this]['origin'],
            'schema' => self::$_schema[static::class],
        ];
    }

    // JsonSerializable
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ArrayAccess
    public function offsetSet(mixed $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function offsetGet(mixed $name): mixed
    {
        return $this->get($name);
    }

    public function offsetExists(mixed $name): bool
    {
        return __isset($name);
    }

    public function offsetUnset(mixed $name): void
    {
        $this->__unset($name);
    }

    public static function __callStatic($method, $args)
    {
        $model = new static();

        return call_user_func_array([$model->model()->db(), $method], $args);
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->model(), $method], $args);
    }
}
