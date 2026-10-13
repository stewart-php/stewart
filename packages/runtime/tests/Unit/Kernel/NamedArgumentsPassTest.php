<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Kernel\NamedArguments;
use Stewart\Runtime\Kernel\NamedArgumentsPass;
use Stewart\Runtime\Tests\Fixtures\Kernel\BoundGreeting;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

#[CoversClass(NamedArguments::class)]
#[CoversClass(NamedArgumentsPass::class)]
final class NamedArgumentsPassTest extends TestCase
{
    public function testConstructorTakesTheBoundValue(): void
    {
        $container = self::compileGreeting(new NamedArguments()->withArgument('text', 'hi'), useFactory: false);

        self::assertSame('hi', self::resolveGreeting($container)->text);
    }

    public function testFactoryMethodTakesTheBoundValue(): void
    {
        $container = self::compileGreeting(new NamedArguments()->withArgument('greetedName', 'Ada'), useFactory: true);

        self::assertSame('hello Ada', self::resolveGreeting($container)->text);
    }

    public function testUnusedBindingFailsTheCompile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\$renamedText/');

        self::compileGreeting(new NamedArguments()->withArgument('text', 'hi')->withArgument('renamedText', 'lost'), useFactory: false);
    }

    private static function compileGreeting(NamedArguments $arguments, bool $useFactory): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $definition = $container->register(BoundGreeting::class)->setAutowired(true)->setPublic(true);

        if ($useFactory) {
            $definition->setFactory([BoundGreeting::class, 'createForName']);
        }

        $container->addCompilerPass(new NamedArgumentsPass($arguments), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->compile();

        return $container;
    }

    private static function resolveGreeting(ContainerBuilder $container): BoundGreeting
    {
        $greeting = $container->get(BoundGreeting::class);
        self::assertInstanceOf(BoundGreeting::class, $greeting);

        return $greeting;
    }
}
