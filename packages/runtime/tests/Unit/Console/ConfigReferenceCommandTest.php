<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\StewartConfigSchema;
use Stewart\Runtime\Console\ConfigReferenceCommand;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ConfigReferenceCommand::class)]
#[CoversClass(StewartConfigSchema::class)]
final class ConfigReferenceCommandTest extends TestCase
{
    private const string GOLDEN = __DIR__ . '/../../Fixtures/Config/config-reference.txt';

    public function testReferenceMatchesTheReviewedCopy(): void
    {
        $tester = new CommandTester(new ConfigReferenceCommand(new StewartConfigSchema()));
        $tester->execute([]);

        self::assertSame((string) file_get_contents(self::GOLDEN), $tester->getDisplay(), 'The schema changed: review the diff, then regenerate with `vendor/bin/stewart config:reference`.');
    }
}
