<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Deploy;

use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Testing\Filesystem\TempDirectory;

final readonly class ReleaseDirectories
{
    private function __construct(private TempDirectory $app) {}

    public static function createRunning(string $commit): self
    {
        $app = TempDirectory::createWithPrefix('stewart-releases-');
        mkdir($app->getFilePath('releases/' . $commit), 0o700, true);
        symlink('releases/' . $commit, $app->getFilePath('current'));

        return new self($app);
    }

    public function getProjectRoot(string $commit): ProjectRoot
    {
        return new ProjectRoot($this->app->getFilePath('releases/' . $commit));
    }

    public function remove(): void
    {
        $this->app->remove();
    }
}
