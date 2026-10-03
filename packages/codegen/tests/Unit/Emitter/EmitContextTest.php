<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Emitter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Emitter\GeneratedCodePrinter;
use Stewart\Codegen\GenerationTarget;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;

#[CoversClass(EmitContext::class)]
final class EmitContextTest extends TestCase
{
    public function testImportSharingClassNameGetsContractAlias(): void
    {
        $context = self::createEmitContext();
        $namespace = $context->createNamespace();

        self::assertSame('EntityStateContract', $context->importClass($namespace, EntityState::class, 'entitystate'));
        self::assertSame(['EntityStateContract' => EntityState::class], $namespace->getUses());
    }

    public function testOtherImportKeepsItsShortName(): void
    {
        $context = self::createEmitContext();
        $namespace = $context->createNamespace();

        self::assertSame('EntityId', $context->importClass($namespace, EntityId::class, 'LightEntities'));
        self::assertSame(['EntityId' => EntityId::class], $namespace->getUses());
    }

    private static function createEmitContext(): EmitContext
    {
        return new EmitContext(new GenerationTarget('Acme\Home', '/unused'), new GeneratedCodePrinter());
    }
}
