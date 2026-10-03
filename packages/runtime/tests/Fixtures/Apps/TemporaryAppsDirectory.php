<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Apps;

use Closure;
use Stewart\Runtime\App\AppDiscovery;
use Stewart\Runtime\Config\Psr4Map;

final class TemporaryAppsDirectory
{
    private const string NAMESPACE_PREFIX = 'Stewart\\Runtime\\Tests\\Temporary';

    public readonly string $namespace;

    public readonly string $path;

    /** @var Closure(string): void */
    private readonly Closure $autoloader;

    public function __construct()
    {
        $suffix = bin2hex(random_bytes(6));
        $this->namespace = self::NAMESPACE_PREFIX . $suffix;
        $this->path = sys_get_temp_dir() . '/stewart-apps-' . $suffix;
        mkdir($this->path);

        $this->autoloader = $this->loadClass(...);
        spl_autoload_register($this->autoloader);
    }

    public function writeClass(string $shortName, string $source): void
    {
        file_put_contents(
            $this->path . '/' . $shortName . '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $this->namespace . ";\n\n" . $source . "\n",
        );
    }

    public function createDiscovery(): AppDiscovery
    {
        return new AppDiscovery(new Psr4Map([$this->namespace . '\\' => [$this->path]]), $this->path);
    }

    public function remove(): void
    {
        spl_autoload_unregister($this->autoloader);

        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->path);
    }

    private function loadClass(string $class): void
    {
        $prefix = $this->namespace . '\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $file = $this->path . '/' . substr($class, \strlen($prefix)) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
}
