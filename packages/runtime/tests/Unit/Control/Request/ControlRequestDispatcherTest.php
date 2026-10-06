<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\Request\ControlRequestDispatcher;
use Stewart\Runtime\Exception\ContainerError;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotRequestHandler;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ControlRequestDispatcher::class)]
final class ControlRequestDispatcherTest extends TestCase
{
    use AssertsReason;

    public function testRequestIsAnsweredByItsHandler(): void
    {
        $dispatcher = new ControlRequestDispatcher([new StubSnapshotRequestHandler(new StubSnapshotSource())]);

        self::assertInstanceOf(SnapshotFrame::class, $dispatcher->answerRequest(new SnapshotRequest()));
    }

    public function testRequestWithoutHandlerIsUnexpected(): void
    {
        $dispatcher = new ControlRequestDispatcher([]);

        $this->assertThrowsReason(ControlError::RequestUnexpected, fn() => $dispatcher->answerRequest(new Hello('token', 'phpunit')));
    }

    public function testSecondHandlerForSameRequestIsRefused(): void
    {
        $snapshots = new StubSnapshotSource();

        $this->assertThrowsReason(ContainerError::MessageHandlerDuplicated, static fn() => new ControlRequestDispatcher([
            new StubSnapshotRequestHandler($snapshots),
            new StubSnapshotRequestHandler($snapshots),
        ]));
    }
}
