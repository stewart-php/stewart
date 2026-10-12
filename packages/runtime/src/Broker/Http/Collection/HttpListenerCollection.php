<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Http\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Broker\Http\HttpListener;

/** @extends ListCollection<HttpListener> */
final readonly class HttpListenerCollection extends ListCollection {}
