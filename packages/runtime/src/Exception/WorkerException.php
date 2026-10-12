<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<WorkerError> */
final class WorkerException extends StewartException {}
