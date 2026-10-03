<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Kernel;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\BrokerLifecycle;
use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Container\TypedContainer;
use Stewart\Runtime\Kernel\BrokerKernel;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Kernel\ContainerProfile;
use Stewart\Runtime\Kernel\NamedArguments;
use Stewart\Runtime\Kernel\NamedArgumentsPass;
use Stewart\Runtime\Kernel\ProfileCompiler;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigFixture;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(ConsoleKernel::class)]
#[CoversClass(BrokerKernel::class)]
#[CoversClass(ProfileCompiler::class)]
#[CoversClass(TypedContainer::class)]
#[CoversClass(ContainerProfile::class)]
#[CoversClass(SyntheticServices::class)]
#[CoversClass(NamedArguments::class)]
#[CoversClass(NamedArgumentsPass::class)]
final class KernelCompileTest extends TestCase
{
    private const string EMPTY_SERVICES_FILE = __DIR__ . '/../../Fixtures/Kernel/empty-services.php';

    public function testConsoleOffersEveryCommand(): void
    {
        $application = new ConsoleKernel(self::getProjectRoot(), self::psr4())->createApplication();

        foreach (['run', 'status', 'config:dump', 'config:reference'] as $command) {
            self::assertTrue($application->has($command), $command);
        }
    }

    public function testBrokerWiresWithTheControlSocketOff(): void
    {
        self::assertInstanceOf(BrokerLifecycle::class, self::bootBroker(['control' => ['listen' => 'off']]));
    }

    public function testBrokerWiresWithTheControlSocketOn(): void
    {
        self::assertInstanceOf(BrokerLifecycle::class, self::bootBroker(['control' => ['listen' => 'unix://var/run/kernel-test.sock', 'token' => 'secret']]));
    }

    public function testDefaultTimeoutsLogNoWarning(): void
    {
        $logger = new RecordingLogger();

        self::bootBroker(['control' => ['listen' => 'off']], $logger);

        self::assertSame([], $logger->listMessagesAt('warning'));
    }

    public function testWarnsWhenReadyTimeoutFitsNoInitialize(): void
    {
        $logger = new RecordingLogger();

        self::bootBroker(['control' => ['listen' => 'off'], 'supervision' => ['initialize_timeout' => '5m', 'ready_timeout' => '5m']], $logger);

        self::assertSame(['A worker is restarted before an app that uses its whole initialize_timeout can report ready'], $logger->listMessagesAt('warning'));
    }

    public function testWarnsWhenOnlyReadyTimeoutIsOn(): void
    {
        $logger = new RecordingLogger();

        self::bootBroker(['control' => ['listen' => 'off'], 'supervision' => ['initialize_timeout' => 'off']], $logger);

        self::assertSame(['A hanging initialize() restarts its whole worker, because initialize_timeout is off while ready_timeout is on'], $logger->listMessagesAt('warning'));
    }

    /** @param array<string, mixed> $yaml */
    private static function bootBroker(array $yaml, LoggerInterface $logger = new NullLogger()): BrokerLifecycle
    {
        return new BrokerKernel('test', self::EMPTY_SERVICES_FILE, new ProjectRoot(sys_get_temp_dir()))->createBroker(
            ConfigFixture::createStewartConfig($yaml),
            new AppCatalog(AppDefinitionCollection::keyedByAppId([]), AppIdCollection::fromIds([]), AppIdCollection::fromIds([])),
            $logger,
        );
    }

    /** @return array<string, array<int, string>> */
    private static function psr4(): array
    {
        $loader = array_values(ClassLoader::getRegisteredLoaders())[0] ?? null;

        return $loader?->getPrefixesPsr4() ?? [];
    }

    private static function getProjectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
