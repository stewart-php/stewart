<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Console\DoctorCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DoctorCommand::class)]
final class DoctorCommandTest extends TestCase
{
    public function testTestContainerPassesEveryCheck(): void
    {
        $tester = new CommandTester(new DoctorCommand());

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('All good.', $tester->getDisplay());
        self::assertStringNotContainsString('FAIL', $tester->getDisplay());
    }

    public function testEveryRequiredExtensionIsReported(): void
    {
        $tester = new CommandTester(new DoctorCommand());
        $tester->execute([]);

        foreach (['pcntl', 'json', 'ctype', 'tmpdir', 'unix sock'] as $label) {
            self::assertMatchesRegularExpression('/^  ' . preg_quote($label, '/') . '\s+✓/m', $tester->getDisplay());
        }
    }
}
