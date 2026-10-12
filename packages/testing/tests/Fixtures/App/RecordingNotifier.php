<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

final class RecordingNotifier implements Notifier
{
    /** @var list<string> */
    public private(set) array $messages = [];

    public function notify(string $message): void
    {
        $this->messages[] = $message;
    }
}
