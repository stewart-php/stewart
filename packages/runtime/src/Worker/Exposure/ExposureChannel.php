<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

final readonly class ExposureChannel
{
    public function __construct(
        public ExposureRequester $requester,
        public ExposedHandleRegistry $handles,
        public ExposedCommandStreams $commandStreams,
    ) {}
}
