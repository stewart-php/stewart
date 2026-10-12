<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service\Collection;

use Stewart\Codegen\Service\FieldModel;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<FieldModel> */
final readonly class FieldModelCollection extends ListCollection {}
