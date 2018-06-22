<?php

namespace Curia\Container;

use Closure;
use Exception;
use ArrayAccess;
use ReflectionClass;
use ReflectionParameter;
use Psr\Container\ContainerInterface;

class Container implements ArrayAccess, ContainerInterface
{
    /**
     * 存放绑定关系
     * @var array
     */
	protected $bindings = [];

    /**
     * 别名
     * @var array
     */
	protected $aliases = [];

    /**
     * 存放单例实例
     * @var array
     */
	protected $instances = [];

    /**
     * 绑定
     * @param $abstract
     * @param null $concrete
     * @param bool $shared
     */
	public function bind($abstract, $concrete = null, $shared = false)
	{
		if (is_null($concrete)) $concrete = $abstract;

		if (! $concrete instanceof Closure) {
			$concrete = $this->getClosure($concrete);
		}

		$this->bindings[$abstract] = compact('concrete', 'shared');
	}

    /**
     * 单例绑定
     * @param $abstract
     * @param null $concrete
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
	}

    /**
     * 设置别名
     * @param $alias
     * @param $abstract
     */
    public function alias($alias, $abstract)
    {
        $this->aliases[$alias] = $abstract;
	}

    /**
     * 获取Closure
     * @param $concrete
     * @return Closure
     */
	protected function getClosure($concrete)
	{
		return function ($container) use ($concrete) {
			return $container->resolve($concrete);	
		};
	}

    /**
     * 从容器中resolve传入的类型
     * @param $abstract
     * @return mixed|object
     * @throws Exception
     */
	protected function resolve($abstract)
	{
	    $abstract = $this->restoreFromAlias($abstract);

        $concrete = $this->getConcrete($abstract);

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $object = $this->build($concrete);

        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
	}

    /**
     * 从别名获取abstract
     * @param $alias
     * @return mixed
     */
    protected function restoreFromAlias($alias)
    {
        return $this->aliases[$alias] ?? $alias;
	}

    /**
     * 实例化一个concrete
     * @param $concrete
     * @return mixed|object
     * @throws \ReflectionException
     */
    protected function build($concrete)
    {
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        $reflector = new ReflectionClass($concrete);

        if (! $reflector->isInstantiable()) {
            throw new Exception("[$concrete]无法被实例化");
        }

        $constructor = $reflector->getConstructor();

        if (is_null($constructor)) {
            return new $concrete();
        }

        $parameters = $constructor->getParameters();

        $dependencies = $this->resolveDependencies($parameters);

        return $reflector->newInstanceArgs($dependencies);
	}

    /**
     * 获取concrete
     * @param $abstract
     * @return mixed
     */
    protected function getConcrete($abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
	}

    /**
     * 是否单例
     * @param $abstract
     * @return bool
     */
    protected function isShared($abstract)
    {
        return isset($this->instances[$abstract]) || (
            isset($this->bindings[$abstract]['shared']) && $this->bindings[$abstract]['shared'] === true
        );
	}

    /**
     * 解决依赖的参数
     * @param array $parameters
     * @return array
     * @throws \ReflectionException
     */
	protected function resolveDependencies(array $parameters)
	{
		$dependencies = [];

		foreach ($parameters as $parameter) {
			$class = $parameter->getClass();

			$dependencies[] = is_null($class)
								? $this->getParameterDefaultValue($parameter)
								: $this->resolve($class->name);
		}

		return $dependencies;
	}

    /**
     * 获取参数默认值
     * @param $parameter
     * @return mixed
     * @throws Exception
     */
	protected function getParameterDefaultValue($parameter)
	{
		if ($parameter->isDefaultValueAvailable()) {
		    return $parameter->getDefaultValue();
		}

		throw new Exception("[\$$parameter->name]参数没有默认值, 无法解决依赖关系");
	}

    /**
     * 从容器获得实例
     * @param string $abstract
     * @return mixed|object
     * @throws \ReflectionException
     */
	public function get($abstract)
	{
		return $this->resolve($abstract);
	}

    /**
     * abstract是否被绑定
     * @param string $id
     * @return bool
     */
	public function has($id)
	{
		return isset($this->bindings[$id]) ||
                isset($this->instances[$id]) ||
                isset($this->aliases[$id]);
	}

    /**
     * 从容器获得实例
     * @param mixed $offset
     * @return mixed|object
     * @throws \ReflectionException
     */
	public function offsetGet($offset)
	{
		return $this->get($offset);
	}

    /**
     * 删除绑定
     * @param mixed $offset
     */
	public function offsetUnset($offset)
	{
        unset($this->bindings[$offset], $this->instances[$offset], $this->aliases[$offset]);
	}

    /**
     * 绑定
     * @param mixed $offset
     * @param mixed $value
     */
	public function offsetSet($offset, $value)
	{
		return $this->bind($offset, $value);
	}

    /**
     * abstract是否被绑定
     * @param mixed $offset
     * @return bool
     */
	public function offsetExists($offset)
	{
		return $this->has($offset);
    }
}