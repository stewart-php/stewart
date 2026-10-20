<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<ScaffoldError> */
final class ScaffoldException extends StewartException {}
