<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Client\Exception\HaClientError;
use Stewart\Client\Exception\HaClientException;

#[CoversClass(HaClientError::class)]
final class HaClientErrorTest extends TestCase
{
    public function testOnlyARefusedCredentialIsFatal(): void
    {
        self::assertTrue(HaClientException::tokenRejected('Invalid access token')->reason->isFatal());
        self::assertTrue(HaClientException::unexpectedGreeting('nothing')->reason->isFatal());
        self::assertTrue(HaClientException::administratorRequired(HaClientException::commandUnauthorized('subscribe_events', 'Unauthorized', 'unauthorized'))->reason->isFatal());

        self::assertFalse(HaClientException::connectionDropped('Home Assistant closed the connection.')->reason->isFatal());
        self::assertFalse(HaClientException::connectFailed('ws://ha:8123', new RuntimeException('refused'))->reason->isFatal());
    }
}
