<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;

final readonly class AppBuildFailure
{
    /** @param StewartException<ExceptionReason> $error */
    public function __construct(
        public AppId $appId,
        public StewartException $error,
    ) {}
}
