<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Closure;
use Stewart\Contracts\EventStream;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Stream\Edge;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;

/**
 * @template T of ExposedCommand
 * @implements EventStream<T>
 */
final readonly class SettlingCommandStream implements EventStream
{
    /** @param EventStream<T> $commands */
    public function __construct(
        private EventStream $commands,
        private ExposedCommandSettlements $settlements,
    ) {}

    public function subscribe(Closure $handler): Subscription
    {
        $settlements = $this->settlements;

        return $this->commands->subscribe(static function (ExposedCommand $command) use ($settlements, $handler): void {
            $settlements->settleThroughHandler($command, $handler);
        });
    }

    public function filter(Closure $predicate): static
    {
        return new self($this->commands->filter($predicate), $this->settlements);
    }

    public function map(Closure $mapper): EventStream
    {
        return $this->commands->map($mapper);
    }

    public function debounce(Duration $window, Edge $edge = Edge::Trailing): static
    {
        return new self($this->commands->debounce($window, $edge), $this->settlements);
    }

    public function throttle(Duration $window, Edge $edge = Edge::Leading): static
    {
        return new self($this->commands->throttle($window, $edge), $this->settlements);
    }

    public function take(int $count): static
    {
        return new self($this->commands->take($count), $this->settlements);
    }

    public function takeUntil(EventStream $notifier): static
    {
        return new self($this->commands->takeUntil($notifier), $this->settlements);
    }
}
