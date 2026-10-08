<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Stewart\Runtime\Exception\DeployException;

final readonly class PreparedRelease
{
    public function __construct(
        public CommitId $commit,
        public ReleaseState $state,
    ) {}

    /** @throws DeployException */
    public static function fromScriptOutput(string $output): self
    {
        $line = trim($output);
        $parts = explode(' ', $line);
        $state = ReleaseState::tryFrom($parts[1] ?? '');

        if (\count($parts) !== 2 || $state === null) {
            throw DeployException::prepareOutputUnexpected($line);
        }

        return new self(new CommitId($parts[0]), $state);
    }
}
