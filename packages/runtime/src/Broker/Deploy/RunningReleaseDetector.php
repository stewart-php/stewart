<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Stewart\Runtime\Config\ProjectRoot;
use Stewart\Runtime\Exception\DeployException;

final readonly class RunningReleaseDetector
{
    private const string RELEASES_DIRECTORY = 'releases';

    private const string CURRENT_RELEASE_LINK = 'current';

    public function __construct(private ProjectRoot $projectRoot) {}

    // The entrypoint runs a release from <app>/releases/<commit>, with <app>/current pointing at it.
    public function findRunningCommit(): ?CommitId
    {
        $releasesDirectory = \dirname($this->projectRoot->path);

        if (basename($releasesDirectory) !== self::RELEASES_DIRECTORY || !is_link(\dirname($releasesDirectory) . '/' . self::CURRENT_RELEASE_LINK)) {
            return null;
        }

        try {
            return new CommitId(basename($this->projectRoot->path));
        } catch (DeployException) {
            return null;
        }
    }
}
