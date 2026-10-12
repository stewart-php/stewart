<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\Exception;

use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<SampleError> */
final class SampleException extends StewartException {}
