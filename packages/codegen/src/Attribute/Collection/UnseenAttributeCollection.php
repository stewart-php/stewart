<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute\Collection;

use Stewart\Codegen\Attribute\UnseenAttribute;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<UnseenAttribute> */
final readonly class UnseenAttributeCollection extends ListCollection {}
