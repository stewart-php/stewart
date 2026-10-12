<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service\Collection;

use Stewart\Codegen\Service\ServiceField;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<ServiceField> */
final readonly class ServiceFieldCollection extends ListCollection {}
