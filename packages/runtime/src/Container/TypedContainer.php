<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Stewart\Runtime\Exception\ContainerException;
use Symfony\Component\DependencyInjection\ContainerInterface;

final readonly class TypedContainer
{
    public function __construct(private ContainerInterface $container) {}

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     * @throws ContainerException
     */
    public function resolveService(string $type, ?string $id = null): object
    {
        $service = $this->container->get($id ?? $type);

        if (!$service instanceof $type) {
            throw ContainerException::serviceTypeMismatch($id ?? $type, $type, get_debug_type($service));
        }

        return $service;
    }
}
