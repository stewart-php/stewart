<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Store\GlobalStore;
use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;
use Stewart\Runtime\Exception\ContainerException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final readonly class StoreAttributesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();

            if (
                $class === null
                || !$definition->isAutowired()
                || $definition->isAbstract()
                || $definition->isSynthetic()
                || $definition->getFactory() !== null
                || !class_exists($class)
            ) {
                continue;
            }

            $this->bindStoreArguments($container, $definition, $class);
        }
    }

    /** @param class-string $class */
    private function bindStoreArguments(ContainerBuilder $container, Definition $definition, string $class): void
    {
        $parameters = new ReflectionClass($class)->getConstructor()?->getParameters() ?? [];
        $arguments = $definition->getArguments();

        foreach ($parameters as $parameter) {
            // Whatever services.php or stewart.yaml set explicitly still wins.
            if (\array_key_exists('$' . $parameter->getName(), $arguments) || \array_key_exists($parameter->getPosition(), $arguments)) {
                continue;
            }

            $store = $this->storeReferenceFor($container, $class, $parameter);

            if ($store !== null) {
                $definition->setArgument('$' . $parameter->getName(), $store);
            }
        }
    }

    private function storeReferenceFor(ContainerBuilder $container, string $class, ReflectionParameter $parameter): ?Reference
    {
        $writable = $this->isWritable($parameter);

        if ($parameter->getAttributes(GlobalStore::class) !== []) {
            return new Reference($writable ? AppContainerBuilder::GLOBAL_STORE : AppContainerBuilder::READ_ONLY_GLOBAL_STORE);
        }

        $peer = ($parameter->getAttributes(PeerStore::class)[0] ?? null)?->newInstance();

        if ($peer === null) {
            return null;
        }

        if ($writable) {
            throw ContainerException::peerStoreWritable($class, $parameter->getName());
        }

        $peerAppId = $this->resolvePeerAppId($class, $parameter, $peer);
        $id = AppContainerBuilder::buildPeerStoreServiceId($peerAppId);

        if (!$container->hasDefinition($id)) {
            $container->setDefinition($id, new Definition(ReadableStore::class)
                ->setFactory([new Reference(AppContainerBuilder::STORES), 'openPeerStoreForReading'])
                ->setArguments([$peerAppId]));
        }

        return new Reference($id);
    }

    /** @throws ContainerException */
    private function resolvePeerAppId(string $class, ReflectionParameter $parameter, PeerStore $peer): AppId
    {
        try {
            return new AppId($peer->appId);
        } catch (IdentifierException $e) {
            throw ContainerException::peerStoreAppIdInvalid($class, $parameter->getName(), $e);
        }
    }

    private function isWritable(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType && $type->getName() === Store::class;
    }
}
