<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ComponentGoldenCopyTest extends TestCase
{
    private const string COPY_DIRECTORY = __DIR__ . '/../../packages/client/tests/Fixtures/Component/Protocol';

    private const string ORIGINAL_DIRECTORY = __DIR__ . '/../../integrations/home-assistant/tests/protocol';

    public function testClientCopiesMatchIntegrationGoldens(): void
    {
        $copies = glob(self::COPY_DIRECTORY . '/*.json') ?: [];

        self::assertNotSame([], $copies);

        foreach ($copies as $copy) {
            $original = self::ORIGINAL_DIRECTORY . '/' . basename($copy);

            self::assertFileExists($original);
            self::assertFileEquals($original, $copy, basename($copy) . ' drifted; copy it again from integrations/home-assistant/tests/protocol.');
        }
    }

    public function testEveryIntegrationGoldenIsCopied(): void
    {
        foreach (glob(self::ORIGINAL_DIRECTORY . '/*.json') ?: [] as $original) {
            self::assertFileExists(self::COPY_DIRECTORY . '/' . basename($original), basename($original) . ' is missing; copy it from integrations/home-assistant/tests/protocol.');
        }
    }
}
