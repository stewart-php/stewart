<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// The config DSL rejects object values, so runtime values are bound here, not under _defaults.
final readonly class NamedArgumentsPass implements CompilerPassInterface
{
    public function __construct(private NamedArguments $arguments) {}

    public function process(ContainerBuilder $container): void
    {
        $bindings = $this->arguments->toBoundArguments();

        foreach ($container->getDefinitions() as $definition) {
            if ($definition->isAutowired() && !$definition->isSynthetic()) {
                $definition->setBindings([...$bindings, ...$definition->getBindings()]);
            }
        }
    }
}
