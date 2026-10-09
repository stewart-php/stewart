<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditError;
use Stewart\Contracts\Exception\RegistryEditException;

#[CoversClass(RegistryEditException::class)]
#[CoversClass(RegistryEditError::class)]
final class RegistryEditExceptionTest extends TestCase
{
    public function testRejectionNamesEntityAndHaCode(): void
    {
        $failure = RegistryEditException::rejected(new EntityId('light.hall'), 'Device is disabled', 'invalid_info');

        self::assertSame('Home Assistant rejected the registry update for light.hall: Device is disabled (invalid_info)', $failure->getMessage());
    }

    public function testRejectionWithoutCodeAppendsNothing(): void
    {
        self::assertSame(
            'Home Assistant rejected the registry update for light.hall: Device is disabled',
            RegistryEditException::rejected(new EntityId('light.hall'), 'Device is disabled')->getMessage(),
        );
    }

    public function testWireRoundTripKeepsReasonMessageAndContext(): void
    {
        $original = RegistryEditException::notFound(new EntityId('light.hall'));

        $rebuilt = RegistryEditException::fromWire($original->reason, $original->getMessage(), $original->context);

        self::assertSame(RegistryEditError::NotFound, $rebuilt->reason);
        self::assertSame($original->getMessage(), $rebuilt->getMessage());
        self::assertSame($original->context, $rebuilt->context);
    }
}
