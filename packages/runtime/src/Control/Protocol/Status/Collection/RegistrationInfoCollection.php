<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Status\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Control\Protocol\Status\RegistrationInfo;

/** @extends ListCollection<RegistrationInfo> */
final readonly class RegistrationInfoCollection extends ListCollection {}
