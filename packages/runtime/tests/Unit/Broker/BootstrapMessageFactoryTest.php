<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\BootstrapMessageFactory;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\AppTopologyFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Filesystem\TempDirectory;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(BootstrapMessageFactory::class)]
final class BootstrapMessageFactoryTest extends TestCase
{
    private TempDirectory $project;

    protected function setUp(): void
    {
        $this->project = TempDirectory::createWithPrefix('stewart-bootstrap-');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testServicesFileCreatedAfterTheStartReachesTheNextWorker(): void
    {
        $servicesFile = $this->project->getFilePath('services.php');
        $slot = new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]));
        $factory = new BootstrapMessageFactory(
            ConfigFixture::createStewartConfig(),
            AppTopologyFixture::createTopologyForSlots($slot),
            new AppPauseRegistry(AppDefinitionCollection::keyedByAppId([]), new DaemonStartTime(new VirtualClock())),
            $servicesFile,
        );
        $handle = new WorkerHandle($slot->workerId, new FakeWorkerProcess(), $slot, new NullLogger(), new OutboxLimits(10, 256));

        self::assertNull($factory->createBootstrapMessage($handle, FakeHaSession::createOpened())->settings->servicesFile);

        file_put_contents($servicesFile, "<?php\n");

        self::assertSame($servicesFile, $factory->createBootstrapMessage($handle, FakeHaSession::createOpened())->settings->servicesFile);
        self::assertSame(['demo'], $factory->createBootstrapMessage($handle, FakeHaSession::createOpened())->knownAppIds->collection->toStrings());
    }
}
