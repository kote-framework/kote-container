<?php

namespace Kote\Container;



class Container implements Contracts\Container
{
    use ResolverTrait;

    /**
     * @var Contracts\Container
     */
    private static $instance;

    /**
     * Storage for all registered services.
     *
     * @var array
     */
    private $storage = [];

    /**
     * @param $id
     * @return bool
     */
    public function has($id)
    {
        return isset($this->storage[$id]);
    }

    /**
     * @param $id
     * @return object
     * @throws Exception\NotFoundException
     */
    public function get($id)
    {
        if (!$this->has($id)) {
            throw new Exception\NotFoundException("Resource $id not found in container.");
        }

        if (is_callable($this->storage[$id])) {
            return call_user_func($this->storage[$id]);
        }

        return $this->storage[$id];
    }

    /**
     * @param $id
     * @return $this
     */
    public function unbind($id)
    {
        if ($this->has($id)) {
            unset ($this->storage[$id]);
        }

        return $this;
    }

    /**
     * @param $id
     * @param null $provider
     * @return $this
     */
    public function bind($id, $provider = null)
    {
        if (is_null($provider)) {
            $provider = $id;
        }

        $this->storage[$id] = $provider;

        return $this;
    }

    /**
     * @param string $id
     * @param null $provider
     * @return $this
     */
    public function singleton($id, $provider = null)
    {
        if (is_null($provider)) {
            $provider = $id;
        }

        $this->storage[$id] = function () use ($provider)
        {
            static $instance = null;

            if (is_null($instance)) {
                $instance = $this->invoke($provider);
            }

            return $instance;
        };

        return $this;
    }

    /**
     * @param $callable
     * @param array $args
     * @return $this
     */
    public function invoke($callable, array $args = [])
    {
        if (is_array($callable) && count($callable) == 2) {
            return $this->invokeClassMethod($callable[0], $callable[1], $args);
        }

        if (is_string($callable) && class_exists($callable)) {
            return $this->invokeClassConstructor($callable, $args);
        }

        return $this->invokeFunction($callable, $args);
    }

    private function invokeFunction($function, array $args = [])
    {
        $reflection = new \ReflectionFunction($function);

        $dependencies = $this->getDependencies($reflection->getParameters(), $args);

        return $function(...$dependencies);
    }

    private function invokeClassConstructor($class, array $args = [])
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if (is_null($constructor)) {
            return new $class;
        }

        $dependencies = $this->getDependencies($constructor->getParameters(), $args);

        return new $class(...$dependencies);
    }

    /**
     * @param $class
     * @param $method
     * @param array $args
     * @return mixed
     */
    private function invokeClassMethod($class, $method, array $args = [])
    {
        $function = new \ReflectionMethod($class, $method);
        $dependencies = $this->getDependencies($function->getParameters(), $args);

        if ($function->isStatic()) {
            return $class::$method(...$dependencies);
        }

        if (is_string($class) && !$function->isStatic()) {
            $class = $this->invokeClassConstructor($class, $args);
        }

        return $class->$method(...$dependencies);
    }

    /**
     * @param \ReflectionParameter[] $parameters
     * @param array $args
     * @return object[]
     * @throws Exception\NotFoundException
     */
    private function getDependencies(array $parameters, array $args = [])
    {
        $instances = [];
        foreach ($parameters as $parameter) {
            $instances[] = $this->loadDependency($parameter, $args);
        }

        return $instances;
    }

    /**
     * @param \ReflectionParameter $parameter
     * @param array $args
     * @return object
     * @throws Exception\NotFoundException
     */
    private function loadDependency(\ReflectionParameter $parameter, array $args)
    {
        $name = $parameter->getName();

        if (isset($args[$name])) {
            return $args[$name];
        }

        $class = $parameter->getClass();

        if (isset($class) && $this->has($class->getName())) {
            return $this->get($class->getName());
        }

        if ($this->has($name)) {
            return $this->get($name);
        }

        $type = Util::getTypeFromReflectionParameter($parameter);

        if ($this->isResolvable($name, $type)) {
            return $this->resolve($name, $type);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new Exception\NotFoundException(
            "Object with id {$parameter->getName()} not found in container."
        );
    }

    /**
     * @param Contracts\Container $container
     */
    public static function setInstance(Contracts\Container $container)
    {
        self::$instance = $container;
    }

    /**
     * @return Contracts\Container
     */
    public static function getInstance()
    {
        return self::$instance;
    }
}