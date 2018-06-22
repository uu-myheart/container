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

    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
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

	protected function resolve($abstract)
	{
        $concrete = $this->getConcrete($abstract);



        return $this->build($concrete);
	}

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

    protected function getConcrete($abstract)
    {
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]['concrete'];
        }

        return $abstract;
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

	public function has($id)
	{
		
	}

	public function offsetGet($offset)
	{
		return $this->get($offset);
	}

	public function offsetUnset($offset)
	{
		
	}

	public function offsetSet($offset, $value)
	{
		return $this->bind($offset, $value);
	}

	public function offsetExists($offset)
	{
		
	}
}