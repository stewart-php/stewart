<?php

declare(strict_types=1);

namespace Stewart\Contracts\Mqtt\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Mqtt\MqttMessage;

/** @extends ListCollection<MqttMessage> */
final readonly class MqttMessageCollection extends ListCollection
{
    /** @param iterable<MqttMessage> $messages */
    public static function fromMessages(iterable $messages): self
    {
        return self::fromList($messages);
    }

    public function withMqttMessage(MqttMessage $message): self
    {
        return $this->withAppendedElement($message);
    }
}
