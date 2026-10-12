<?php

declare(strict_types=1);

namespace Stewart\Testing\Exception;

use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<HarnessError> */
final class HarnessException extends StewartException {}
