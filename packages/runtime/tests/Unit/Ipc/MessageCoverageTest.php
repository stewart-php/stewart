<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stewart\Runtime\Ipc\MessageHandler;
use Stewart\Runtime\Ipc\Wire\IpcMessageCatalog;
use Symfony\Component\Finder\Finder;

#[CoversNothing]
final class MessageCoverageTest extends TestCase
{
    private const string SOURCE = __DIR__ . '/../../../src/';

    private const array HANDLER_DIRECTORIES = ['Broker/Message', 'Worker/Message'];

    public function testEveryMessageHasExactlyOneHandler(): void
    {
        $handledCounts = array_count_values($this->listHandledMessageClasses());

        foreach (IpcMessageCatalog::scanMessageDirectory()->listMessageClasses() as $messageClass) {
            self::assertSame(1, $handledCounts[$messageClass] ?? 0, $messageClass);
        }
    }

    public function testEveryHandlerHandlesCatalogMessage(): void
    {
        $catalogued = IpcMessageCatalog::scanMessageDirectory()->listMessageClasses();

        foreach ($this->listHandledMessageClasses() as $messageClass) {
            self::assertContains($messageClass, $catalogued);
        }
    }

    /** @return list<class-string> */
    private function listHandledMessageClasses(): array
    {
        $handled = [];

        foreach (self::HANDLER_DIRECTORIES as $directory) {
            $namespace = 'Stewart\\Runtime\\' . str_replace('/', '\\', $directory) . '\\';

            foreach (Finder::create()->files()->in(self::SOURCE . $directory)->depth(0)->name('*.php') as $file) {
                $class = $namespace . $file->getBasename('.php');
                \assert(class_exists($class) || interface_exists($class));
                $reflection = new ReflectionClass($class);

                if ($reflection->isInstantiable() && $reflection->implementsInterface(MessageHandler::class)) {
                    $handler = $reflection->newInstanceWithoutConstructor();
                    \assert($handler instanceof MessageHandler);
                    $handled[] = $handler->handledMessageClass();
                }
            }
        }

        return $handled;
    }
}
