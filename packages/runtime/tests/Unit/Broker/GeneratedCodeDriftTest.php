<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\GeneratedCodeDrift;
use Stewart\Runtime\Broker\GeneratedManifest;
use Stewart\Runtime\Control\Protocol\Status\GeneratedCodeStatus;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\UnversionedManifest;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Manifest;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(GeneratedCodeDrift::class)]
#[CoversClass(GeneratedCodeStatus::class)]
final class GeneratedCodeDriftTest extends TestCase
{
    private FakeHaSession $session;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->session = FakeHaSession::createOpened();
        $this->logger = new RecordingLogger();
    }

    public function testSameDriftWarnsOnce(): void
    {
        $drift = $this->createDrift(GeneratedManifest::readFromClass(Manifest::class));
        $this->session->entityIds = [...Manifest::listEntityIds(), 'light.landing'];

        $drift->recheckGeneratedCode();
        $drift->recheckGeneratedCode();

        self::assertSame(['Generated entity classes no longer match Home Assistant; run stewart generate'], $this->logger->listMessagesAt('warning'));
        self::assertSame(['light.landing'], $drift->status?->added);
    }

    public function testChangedDriftWarnsAgainAndAClearedOneIsNoted(): void
    {
        $drift = $this->createDrift(GeneratedManifest::readFromClass(Manifest::class));
        $this->session->entityIds = [...Manifest::listEntityIds(), 'light.landing'];
        $drift->recheckGeneratedCode();

        $this->session->entityIds = [...Manifest::listEntityIds(), 'light.landing', 'switch.fan'];
        $drift->recheckGeneratedCode();
        $this->session->entityIds = Manifest::listEntityIds();
        $drift->recheckGeneratedCode();

        self::assertCount(2, $this->logger->listMessagesAt('warning'));
        self::assertSame(['Generated entity classes match Home Assistant again'], $this->logger->listMessagesAt('info'));
        self::assertFalse($drift->status?->hasDrift());
    }

    public function testOlderFormatWarnsOnce(): void
    {
        $drift = $this->createDrift(GeneratedManifest::readFromClass(UnversionedManifest::class));
        $this->session->entityIds = UnversionedManifest::listEntityIds();

        $drift->recheckGeneratedCode();
        $drift->recheckGeneratedCode();

        self::assertSame(['Generated classes were written by another stewart generate format; run stewart generate'], $this->logger->listMessagesAt('warning'));
        self::assertFalse($drift->status?->hasCurrentFormat());
    }

    public function testReplacedManifestIsWhatTheNextCheckCompares(): void
    {
        $this->session->entityIds = [...Manifest::listEntityIds(), 'light.landing'];
        $drift = $this->createDrift(GeneratedManifest::readFromClass(Manifest::class));
        $drift->recheckGeneratedCode();

        $drift->replaceGeneratedManifest(new GeneratedManifest(GeneratedManifest::readFromClass(Manifest::class)->formatVersion, $this->session->entityIds, []));
        $drift->recheckGeneratedCode();

        self::assertSame([], $drift->status?->added);
        self::assertSame(['Generated entity classes match Home Assistant again'], $this->logger->listMessagesAt('info'));
    }

    public function testProjectWithoutGeneratedCodeHasNoStatus(): void
    {
        $drift = $this->createDrift(null);

        $drift->recheckGeneratedCode();

        self::assertNull($drift->status);
        self::assertSame([], $this->logger->listMessagesAt('warning'));
    }

    private function createDrift(?GeneratedManifest $manifest): GeneratedCodeDrift
    {
        return new GeneratedCodeDrift($this->session, new VirtualClock(), $this->logger, $manifest);
    }
}
