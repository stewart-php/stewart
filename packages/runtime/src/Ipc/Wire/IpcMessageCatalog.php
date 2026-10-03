<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use ReflectionClass;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\IpcMessage;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Symfony\Component\Finder\Finder;

final readonly class IpcMessageCatalog
{
    private const string MESSAGE_DIRECTORY = __DIR__ . '/../Message';

    private const string MESSAGE_NAMESPACE = 'Stewart\\Runtime\\Ipc\\Message\\';

    /**
     * @param array<class-string<BrokerMessage|WorkerMessage>, string> $tagsByClass
     * @param array<string, class-string<BrokerMessage|WorkerMessage>> $classesByTag
     */
    private function __construct(
        private array $tagsByClass,
        private array $classesByTag,
    ) {}

    /** @throws TransportException */
    public static function scanMessageDirectory(): self
    {
        $messageClasses = [];

        foreach (Finder::create()->files()->in(self::MESSAGE_DIRECTORY)->depth(0)->name('*.php')->sortByName() as $file) {
            $class = self::MESSAGE_NAMESPACE . $file->getBasename('.php');

            if (is_subclass_of($class, BrokerMessage::class) || is_subclass_of($class, WorkerMessage::class)) {
                $messageClasses[] = $class;
            }
        }

        return self::fromMessageClasses($messageClasses);
    }

    /**
     * @param iterable<class-string<BrokerMessage|WorkerMessage>> $messageClasses
     *
     * @throws TransportException
     */
    public static function fromMessageClasses(iterable $messageClasses): self
    {
        $classesByTag = [];

        foreach ($messageClasses as $class) {
            $attribute = new ReflectionClass($class)->getAttributes(IpcMessage::class)[0] ?? throw TransportException::messageTagMissing($class);
            $tag = $attribute->newInstance()->tag;

            if (isset($classesByTag[$tag])) {
                throw TransportException::messageTagDuplicated($tag, $classesByTag[$tag], $class);
            }

            $classesByTag[$tag] = $class;
        }

        ksort($classesByTag);

        return new self(array_flip($classesByTag), $classesByTag);
    }

    public function findTagForClass(string $messageClass): ?string
    {
        return $this->tagsByClass[$messageClass] ?? null;
    }

    /** @return class-string<BrokerMessage|WorkerMessage>|null */
    public function findClassForTag(string $tag): ?string
    {
        return $this->classesByTag[$tag] ?? null;
    }

    /** @return list<class-string<BrokerMessage|WorkerMessage>> */
    public function listMessageClasses(): array
    {
        return array_values($this->classesByTag);
    }
}
