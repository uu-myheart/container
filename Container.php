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
     * 容器实例
     *
     * @var
     */
    protected static $instance;

    /**
     * 存放绑定关系
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * 别名
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * 存放单例实例
     *
     * @var array
     */
    protected $instances = [];

    /**
     * Hooks before resolve an abstract or instance.
     * 
     * @var array 
     */
    protected $hooks = [];

    /**
     * The container's method bindings.
     *
     * @var array
     */
    protected $methodBindings = [];

    /**
     * 绑定
     *
     * @param $abstract
     * @param null $concrete
     * @param bool $shared
     *
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        $this->dropStale($abstract);

        if (is_null($concrete)) $concrete = $abstract;

        if (! $concrete instanceof Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');
    }

    /**
     * Drop all of the stale instances and aliases.
     *
     * @param  string  $abstract
     *
     * @return void
     */
    protected function dropStale($abstract)
    {
        unset($this->instances[$abstract], $this->aliases[$abstract]);
    }

    /**
     * 单例绑定
     *
     * @param $abstract
     * @param null $concrete
     *
     * @return void
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * 注册一个实例到容器上
     *
     * @param  string  $abstract
     * @param  mixed   $instance
     *
     * @return $this
     */
    public function instance($abstract, $instance)
    {
        $abstract = $this->restoreAlias($abstract);
        
        $this->instances[$abstract] = $instance;

        return $this;
    }

    /**
     * 设置别名
     *
     * @param $alias
     * @param $abstract
     *
     * @return void
     */
    public function alias($alias, $abstract)
    {
        $this->aliases[$alias] = $abstract;
    }

    /**
     * Set a hook to an abstract
     * 
     * @param $abstract
     * @param Callbale $callback
     *
     * @return void
     */
    public function hook($abstract, Callable $callback)
    {
        $abstract = $this->restoreAlias($abstract);

        $this->hooks[$abstract] = $callback;
    }

    /**
     * 获取Closure
     *
     * @param $concrete
     *
     * @return Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($container) use ($abstract, $concrete) {
            if ($abstract == $concrete) {
                return $container->build($concrete);
            }

            return $container->resolve($concrete);  
        };
    }

    /**
     * Resolve an abstract
     * 
     * @param $abstract
     *
     * @return mixed|object
     *
     * @throws Exception
     */
    protected function resolve($abstract)
    {
        $abstract = $this->restoreAlias($abstract);
        $concrete = $this->getConcrete($abstract);

        // Return instance if singleton.
        if (isset($this->instances[$abstract])) {
            $instance = $this->instances[$abstract];
            
            // hook
            if (isset($this->hooks[$abstract])) {
                $instance = $this->executeHook($instance, $this->hooks[$abstract]);
            }
            
            return $instance;
        }

        $object = $this->build($concrete);

        // hook
        if (isset($this->hooks[$abstract])) {
            $object = $this->executeHook($object, $this->hooks[$abstract]);
        }

        // Save singleton instance.
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Execute hook callback.
     * 
     * @param $instance
     * @param callable $callback
     *
     * @return mixed
     */
    protected function executeHook($instance, Callable $callback)
    {
        $hooked = call_user_func($callback, $instance, $this);
        
        return $hooked ?? $instance;
    }

    /**
     * 实例化一个concrete
     *
     * @param $concrete
     *
     * @return mixed|object
     *
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
     * 从别名获取abstract
     *
     * @param $alias
     *
     * @return mixed
     */
    protected function restoreAlias($alias)
    {
        return isset($this->aliases[$alias])
            ? $this->restoreAlias($this->aliases[$alias])
            : $alias;
    }

    /**
     * 获取concrete
     *
     * @param $abstract
     *
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
     *
     * @param $abstract
     *
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
     *
     * @param array $parameters
     *
     * @return array
     *
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
     *
     * @param $parameter
     *
     * @return mixed
     *
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
     * Determine if the container has a method binding.
     *
     * @param  string  $method
     *
     * @return bool
     */
    public function hasMethodBinding($method)
    {
        return isset($this->methodBindings[$method]);
    }

    /**
     * Bind a callback to resolve with Container::call.
     *
     * @param  array|string  $method
     * @param  \Closure  $callback
     *
     * @return void
     */
    public function bindMethod($method, $callback)
    {
        $this->methodBindings[$this->parseBindMethod($method)] = $callback;
    }

    /**
     * Get the method to be bound in class@method format.
     *
     * @param  array|string $method
     *
     * @return string
     */
    protected function parseBindMethod($method)
    {
        if (is_array($method)) {
            return $method[0].'@'.$method[1];
        }

        return $method;
    }

    /**
     * Determine if the given abstract type has been bound.
     *
     * @param  string  $abstract
     *
     * @return bool
     */
    public function bound($abstract)
    {
        return isset($this->bindings[$abstract]) ||
               isset($this->instances[$abstract]) ||
               isset($this->aliases[$abstract]);
    }

    /**
     * Call the given Closure / class@method and inject its dependencies.
     *
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     *
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null)
    {
        return MethodResolving::call($this, $callback, $parameters, $defaultMethod);
    }

    /**
     * Get the method binding for the given method.
     *
     * @param  string  $method
     * @param  mixed  $instance
     *
     * @return mixed
     */
    public function callMethodBinding($method, $instance)
    {
        return call_user_func($this->methodBindings[$method], $instance, $this);
    }

    /**
     * 存放容器实例
     *
     * @param $container
     *
     * @return mixed
     */
    public static function setInstance($container)
    {
        return static::$instance = $container;
    }

    /**
     * 获取容器实例
     *
     * @return mixed
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * 从容器获得实例
     *
     * @param string $abstract
     *
     * @return mixed|object
     *
     * @throws \ReflectionException
     */
    public function get($abstract)
    {
        return $this->resolve($abstract);
    }

    /**
     * abstract是否被绑定
     *
     * @param string $id
     *
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
     *
     * @param mixed $offset
     *
     * @return mixed|object
     *
     * @throws \ReflectionException
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * 删除绑定
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset)
    {
        unset($this->bindings[$offset], $this->instances[$offset], $this->aliases[$offset]);
    }

    /**
     * 绑定
     *
     * @param mixed $offset
     * @param mixed $value
     *
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        return $this->bind($offset, $value);
    }

    /**
     * abstract是否被绑定
     *
     * @param mixed $offset
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        return $this->has($offset);
    }
}