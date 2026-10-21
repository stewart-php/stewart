<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Process;

use Amp\Parallel\Context\ProcessContextFactory;
use Closure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\ProcessWorkerSpawner;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Lifecycle\ReloadProbe;
use Stewart\Testing\Filesystem\TempDirectory;

use function Amp\delay;

#[CoversNothing]
final class WorkerReloadProcessTest extends TestCase
{
    private const float GRACE_SECONDS = 0.5;

    private const float DEADLINE_SECONDS = 10;

    // Older than opcache.file_update_protection, so the first version is cached.
    private const int FIRST_VERSION_AGE_SECONDS = 10;

    private TempDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = TempDirectory::createWithPrefix('stewart-reload-');
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testReplacedWorkerRunsTheEditedCode(): void
    {
        $this->assertReplacedWorkerRunsTheEditedCode(new ProcessContextFactory());
    }

    public function testReplacedWorkerRunsTheEditedCodeWithOpcacheFileCache(): void
    {
        $cache = $this->directory->getFilePath('opcache');
        mkdir($cache);

        $this->assertReplacedWorkerRunsTheEditedCode(new ProcessContextFactory(binary: [\PHP_BINARY, '-d', 'opcache.file_cache=' . $cache]));

        self::assertNotSame([], glob($cache . '/*'), 'The workers compiled through the file cache.');
    }

    private function assertReplacedWorkerRunsTheEditedCode(ProcessContextFactory $contexts): void
    {
        $script = $this->directory->getFilePath('probe.php');
        file_put_contents($script, "<?php return 'v1';\n");
        touch($script, (int) filemtime($script) - self::FIRST_VERSION_AGE_SECONDS);

        $appId = new AppId('reload-probe');
        $listener = new RecordingPoolListener([
            TestBootstrap::createForApps([new WorkerApp(id: $appId, class: ReloadProbe::class, options: ['script' => $script])]),
            new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])), 1),
        ]);
        $pools = WorkerPoolFixture::createWorkerPool(new ProcessWorkerSpawner($contexts, IpcCodec::createForWorkerBootstrap()), requestedRestartGrace: Duration::seconds(self::GRACE_SECONDS));
        $slot = new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(id: $appId, class: ReloadProbe::class)]));

        self::assertTrue($pools->pool->startWorker($slot, $listener));
        self::waitUntil(static fn(): bool => self::listLoadedVersions($listener) === ['v1']);
        $firstPid = $pools->slots->findHandleForWorker($slot->workerId)?->getPid();

        file_put_contents($script, "<?php return 'v2, edited';\n");
        $pools->pool->applyWorkerSlots(WorkerSlotCollection::fromList([$slot]), $listener);
        self::waitUntil(static fn(): bool => self::listLoadedVersions($listener) === ['v1', 'v2, edited']);

        self::assertNotSame($firstPid, $pools->slots->findHandleForWorker($slot->workerId)?->getPid());
        self::assertSame(0, $pools->slots->findSlotState($slot->workerId)?->restarts);

        $pools->pool->shutdown('test', Duration::seconds(self::GRACE_SECONDS));
    }

    /** @return list<mixed> */
    private static function listLoadedVersions(RecordingPoolListener $listener): array
    {
        $versions = [];

        foreach ($listener->messages as $message) {
            if ($message instanceof LogRecord && $message->message === 'Probe loaded') {
                $versions[] = $message->context['version'] ?? null;
            }
        }

        return $versions;
    }

    /** @param Closure(): bool $condition */
    private static function waitUntil(Closure $condition): void
    {
        $startedAt = hrtime(true);

        while (!$condition()) {
            if ((hrtime(true) - $startedAt) / 1e9 > self::DEADLINE_SECONDS) {
                self::fail('The worker process did not reach the expected state in time.');
            }

            delay(0.05);
        }
    }
}
