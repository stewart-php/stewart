<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Config\EnvironmentVariables;
use Stewart\Runtime\Console\ConfigDumpCommand;
use Stewart\Runtime\Tests\Fixtures\Config\ConfigLoaderFixture;
use Stewart\Support\Url\UrlRedactor;
use Stewart\Testing\Filesystem\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ConfigDumpCommand::class)]
#[CoversClass(UrlRedactor::class)]
final class ConfigDumpCommandTest extends TestCase
{
    private const string USER = 'review-user';

    private const string PASSWORD = 'review-password';

    private TempDirectory $temp;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-dump-');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testCredentialsInsideTheUrlAreMaskedByDefault(): void
    {
        $output = $this->runConfigDump();

        self::assertStringNotContainsString(self::USER, $output);
        self::assertStringNotContainsString(self::PASSWORD, $output);
        self::assertStringContainsString('example.invalid', $output, 'The host is what makes the dump useful.');
    }

    public function testCredentialInTheQueryIsMaskedToo(): void
    {
        $output = $this->runConfigDump('wss://example.invalid/api/websocket?access_token=review-token&mode=live');

        self::assertStringNotContainsString('review-token', $output);
        self::assertStringContainsString('mode=live', $output);
    }

    public function testShowSecretsPrintsTheWholeUrl(): void
    {
        $output = $this->runConfigDump(reveal: true);

        self::assertStringContainsString(self::USER, $output);
        self::assertStringContainsString(self::PASSWORD, $output);
    }

    public function testTokenIsStillMaskedByItsName(): void
    {
        self::assertStringNotContainsString('from-the-file', $this->runConfigDump());
    }

    public function testStorePasswordInUrlIsMasked(): void
    {
        $output = $this->runConfigDump(storeUrl: 'redis://stewart:store-password@valkey:6379/0');

        self::assertStringNotContainsString('store-password', $output);
        self::assertStringContainsString('valkey:6379', $output, 'Which server it is stays worth knowing.');
    }

    public function testControlTokenIsMaskedByItsName(): void
    {
        $environment = ['STEWART_CONTROL__TOKEN' => 'control-secret'];

        self::assertStringNotContainsString('control-secret', $this->runConfigDump(environment: $environment));
        self::assertStringContainsString('control-secret', $this->runConfigDump(reveal: true, environment: $environment));
    }

    public function testFileThatWouldNotBootForWantOfATokenStillDumps(): void
    {
        $output = $this->runConfigDump();

        self::assertStringContainsString('unix://var/run/stewart.sock', $output);
        self::assertStringContainsString("  token: '***'", $output);
    }

    public function testUnsetSecretIsPrintedAsUnset(): void
    {
        $dump = Yaml::parse($this->runConfigDump());
        self::assertIsArray($dump);
        $control = $dump['control'] ?? null;

        self::assertIsArray($control);
        self::assertArrayHasKey('token', $control);
        self::assertNull($control['token']);
    }

    public function testDumpIsYamlTheLoaderReadsBack(): void
    {
        $output = $this->runConfigDump(reveal: true, environment: ['STEWART_APPS__DEMO__OPTIONS__ROOMS' => '[hall, kitchen]']);

        self::assertIsArray(Yaml::parse($output));
        self::assertMatchesRegularExpression('/rooms:\s+- hall\s+- kitchen/', $output);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function provideValuesRunRejects(): iterable
    {
        yield 'unparseable duration' => [['STEWART_SUPERVISION__PING_INTERVAL' => '5 seconds']];
        yield 'listen with unknown scheme' => [['STEWART_CONTROL__LISTEN' => 'ftp://x']];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('provideValuesRunRejects')]
    public function testValueRunRejectsFailsTheDump(array $environment): void
    {
        $tester = $this->executeConfigDump(environment: $environment);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /** @param array<string, string> $environment */
    private function runConfigDump(?string $url = null, bool $reveal = false, ?string $storeUrl = null, array $environment = []): string
    {
        $tester = $this->executeConfigDump($url, $reveal, $storeUrl, $environment);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        return $tester->getDisplay();
    }

    /** @param array<string, string> $environment */
    private function executeConfigDump(?string $url = null, bool $reveal = false, ?string $storeUrl = null, array $environment = []): CommandTester
    {
        $url ??= \sprintf('wss://%s:%s@example.invalid/api/websocket', self::USER, self::PASSWORD);
        $path = $this->temp->getFilePath('stewart.yaml');

        file_put_contents($path, \sprintf(
            "home_assistant:\n  url: '%s'\n  token: from-the-file\n%s",
            $url,
            $storeUrl === null ? '' : \sprintf("persistence:\n  url: '%s'\n", $storeUrl),
        ));

        $tester = new CommandTester(new ConfigDumpCommand(ConfigLoaderFixture::createLoader(new EnvironmentVariables($environment))));
        $tester->execute($reveal ? ['--config' => $path, '--show-secrets' => true] : ['--config' => $path]);

        return $tester;
    }
}
