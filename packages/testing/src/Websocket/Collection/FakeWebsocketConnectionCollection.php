<?php

declare(strict_types=1);

namespace Stewart\Testing\Websocket\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Testing\Websocket\FakeWebsocketConnection;

/** @extends ListCollection<FakeWebsocketConnection> */
final readonly class FakeWebsocketConnectionCollection extends ListCollection
{
    /** @param iterable<FakeWebsocketConnection> $connections */
    public static function fromConnections(iterable $connections): self
    {
        return self::fromList($connections);
    }

    public function withConnection(FakeWebsocketConnection $connection): self
    {
        return $this->withAppendedElement($connection);
    }
}
