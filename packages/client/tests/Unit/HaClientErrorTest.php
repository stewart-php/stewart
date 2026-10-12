<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Exception\HaClientError;

#[CoversClass(HaClientError::class)]
final class HaClientErrorTest extends TestCase
{
    public function testOnlyARefusedCredentialIsFatal(): void
    {
        self::assertTrue(HaClientError::TokenRejected->isFatal());
        self::assertTrue(HaClientError::UnexpectedGreeting->isFatal());
        self::assertTrue(HaClientError::AdministratorRequired->isFatal());

        self::assertFalse(HaClientError::ConnectionDropped->isFatal());
        self::assertFalse(HaClientError::ConnectFailed->isFatal());
    }

    public function testOnlyAnsweredRefusalsCountAsCommandRefusal(): void
    {
        self::assertTrue(HaClientError::CommandRejected->isCommandRefusal());
        self::assertTrue(HaClientError::CommandUnauthorized->isCommandRefusal());
        self::assertTrue(HaClientError::AdministratorRequired->isCommandRefusal());

        self::assertFalse(HaClientError::ConnectionDropped->isCommandRefusal());
        self::assertFalse(HaClientError::NotConnected->isCommandRefusal());
    }
}
