<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core;

defined('ABSPATH') || exit;

final class Container
{
    /**
     * @var array<string, array{
     *     concrete: callable|string,
     *     singleton: bool
     * }>
     */
    private array $bindings = [];

    /**
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * @var array<string, true>
     */
    private array $resolving = [];

    public function bind(
        string $abstract,
        callable|string $concrete,
        bool $singleton = false
    ): void {
        $abstract = trim($abstract);

        if ($abstract === '') {
            throw new \InvalidArgumentException(
                'Visitor Intelligence container abstract cannot be empty.'
            );
        }

        if (
            is_string($concrete)
            && trim($concrete) === ''
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence container binding for %s has an empty concrete class.',
                    $abstract
                )
            );
        }

        if (
            !interface_exists($abstract)
            && !class_exists($abstract)
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence container abstract does not exist: %s',
                    $abstract
                )
            );
        }

        /*
         * A new binding must invalidate an already-resolved instance.
         * Otherwise a previous singleton can survive a rebinding.
         */
        unset(
            $this->instances[$abstract]
        );

        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'singleton' => $singleton,
        ];
    }

    public function singleton(
        string $abstract,
        callable|string $concrete
    ): void {
        $this->bind(
            $abstract,
            $concrete,
            true
        );
    }

    public function instance(
        string $abstract,
        object $instance
    ): void {
        $abstract = trim($abstract);

        if ($abstract === '') {
            throw new \InvalidArgumentException(
                'Visitor Intelligence container abstract cannot be empty.'
            );
        }

        if (
            !interface_exists($abstract)
            && !class_exists($abstract)
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Visitor Intelligence container abstract does not exist: %s',
                    $abstract
                )
            );
        }

        if (
            !$instance instanceof $abstract
            && !is_a(
                $instance,
                $abstract
            )
        ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Instance of %s cannot be registered as %s.',
                    $instance::class,
                    $abstract
                )
            );
        }

        $this->instances[$abstract] = $instance;
    }

    public function has(
        string $abstract
    ): bool {
        return isset(
            $this->bindings[$abstract]
        ) || isset(
            $this->instances[$abstract]
        );
    }

    public function get(
        string $abstract
    ): object {
        $abstract = trim($abstract);

        if ($abstract === '') {
            throw new \InvalidArgumentException(
                'Visitor Intelligence container abstract cannot be empty.'
            );
        }

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (!isset($this->bindings[$abstract])) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor Intelligence container binding not found: %s',
                    $abstract
                )
            );
        }

        if (isset($this->resolving[$abstract])) {
            throw new \RuntimeException(
                sprintf(
                    'Circular dependency detected while resolving: %s',
                    $abstract
                )
            );
        }

        $this->resolving[$abstract] = true;

        try {
            $binding = $this->bindings[$abstract];

            if (is_callable($binding['concrete'])) {
                $instance = ($binding['concrete'])(
                    $this
                );
            } else {
                $instance = $this->build(
                    $binding['concrete']
                );
            }

            if (!is_object($instance)) {
                throw new \RuntimeException(
                    sprintf(
                        'Container factory for %s did not return an object.',
                        $abstract
                    )
                );
            }

            if (
                !$instance instanceof $abstract
                && !is_a(
                    $instance,
                    $abstract
                )
            ) {
                throw new \RuntimeException(
                    sprintf(
                        'Resolved %s is not compatible with %s.',
                        $instance::class,
                        $abstract
                    )
                );
            }

            if ($binding['singleton']) {
                $this->instances[$abstract] = $instance;
            }

            return $instance;
        } finally {
            unset(
                $this->resolving[$abstract]
            );
        }
    }

    public function forget(
        string $abstract
    ): void {
        unset(
            $this->bindings[$abstract],
            $this->instances[$abstract]
        );
    }

    /**
     * @return string[]
     */
    public function bindings(): array
    {
        return array_keys(
            $this->bindings
        );
    }

    /**
     * @return string[]
     */
    public function instances(): array
    {
        return array_keys(
            $this->instances
        );
    }

    private function build(
        string $class
    ): object {
        if (!class_exists($class)) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor Intelligence class not found: %s',
                    $class
                )
            );
        }

        $reflection = new \ReflectionClass(
            $class
        );

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException(
                sprintf(
                    'Visitor Intelligence class is not instantiable: %s',
                    $class
                )
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach (
            $constructor->getParameters()
            as $parameter
        ) {
            $type = $parameter->getType();

            if (
                $type instanceof \ReflectionNamedType
                && !$type->isBuiltin()
            ) {
                $dependency = $type->getName();

                if (!$this->has($dependency)) {
                    throw new \RuntimeException(
                        sprintf(
                            'Unable to resolve dependency %s for %s::$%s.',
                            $dependency,
                            $class,
                            $parameter->getName()
                        )
                    );
                }

                $arguments[] = $this->get(
                    $dependency
                );

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] =
                    $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = null;

                continue;
            }

            throw new \RuntimeException(
                sprintf(
                    'Unable to resolve constructor parameter $%s for %s.',
                    $parameter->getName(),
                    $class
                )
            );
        }

        return $reflection->newInstanceArgs(
            $arguments
        );
    }
}