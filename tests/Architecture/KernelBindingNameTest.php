<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stewart\Runtime\Kernel\ContainerProfile;
use Stewart\Tests\Architecture\Fixtures\RepositoryFiles;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

#[CoversNothing]
final class KernelBindingNameTest extends TestCase
{
    private const string BOUND_NAME = "/->withArgument\\('(\\w+)'/";

    private const string PACKAGE_VENDOR_PREFIX = 'stewart-php/';

    /** @return iterable<string, array{ContainerProfile, string}> */
    public static function provideKernels(): iterable
    {
        yield 'broker' => [ContainerProfile::Broker, 'packages/runtime/src/Kernel/BrokerKernel.php'];
        yield 'worker' => [ContainerProfile::Worker, 'packages/runtime/src/Kernel/WorkerKernel.php'];
        yield 'console' => [ContainerProfile::Console, 'packages/runtime/src/Kernel/ConsoleKernel.php'];
    }

    #[DataProvider('provideKernels')]
    public function testEachBindingReachesOneConstructor(ContainerProfile $profile, string $kernelFile): void
    {
        $repository = new RepositoryFiles();
        preg_match_all(self::BOUND_NAME, (string) file_get_contents($repository->rootPath . '/' . $kernelFile), $bound);
        $consumers = array_fill_keys($bound[1], []);

        foreach ($this->listAutowiredClasses($profile, $repository) as $class) {
            foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
                if (\array_key_exists($parameter->getName(), $consumers)) {
                    $consumers[$parameter->getName()][] = $class;
                }
            }
        }

        $shared = array_filter($consumers, static fn(array $classes): bool => \count(array_unique($classes)) > 1);

        self::assertSame([], $shared, 'Give each binding a name only its owner uses; share a value through a synthetic service.');
    }

    /** @return list<class-string> */
    private function listAutowiredClasses(ContainerProfile $profile, RepositoryFiles $repository): array
    {
        $builder = new ContainerBuilder();
        $loader = new PhpFileLoader($builder, new FileLocator($repository->rootPath . '/packages/runtime/config'));

        foreach ($profile->configFiles() as $file) {
            $loader->load($file);
        }

        foreach ($profile->optionalPackages() as $package) {
            $loader->load(\sprintf('%s/packages/%s/config/services.php', $repository->rootPath, substr($package, \strlen(self::PACKAGE_VENDOR_PREFIX))));
        }

        $classes = [];

        foreach ($builder->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;

            if ($definition->isAutowired() && !$definition->isSynthetic() && class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
