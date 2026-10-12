<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model\Collection;

use Stewart\Codegen\Model\GenerationWarning;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<GenerationWarning> */
final readonly class GenerationWarningCollection extends ListCollection {}
