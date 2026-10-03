<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console;

use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Config\ConfigLoader;
use Stewart\Support\Secret\SecretName;
use Stewart\Support\Url\UrlRedactor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: self::NAME, description: 'Show the effective configuration after the environment is applied')]
final class ConfigDumpCommand extends StewartCommand
{
    public const string NAME = 'config:dump';

    // Broad on purpose: app options are free-form and can hold API keys.
    private const string MASK = '***';

    private const int YAML_INLINE_DEPTH = 10;

    public function __construct(private readonly ConfigLoader $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('show-secrets', null, InputOption::VALUE_NONE, 'Print credentials instead of masking them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $processed = $this->config->buildValidatedConfigTree($this->findConfigOption($input));
        } catch (StewartException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $values = $input->getOption('show-secrets') === true ? $processed : $this->maskSecrets($processed);

        $output->write(Yaml::dump($values, self::YAML_INLINE_DEPTH, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

        return Command::SUCCESS;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function maskSecrets(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                SecretName::looksSecret((string) $key) && !self::isEmptyValue($value) => self::MASK,
                \is_array($value) => $this->maskSecrets($value),
                // URL credentials (e.g. a reverse proxy) sit under keys the pattern does not match.
                \is_string($value) && UrlRedactor::looksLikeUrl($value) => UrlRedactor::redactCredentials($value),
                default => $value,
            };
        }

        return $values;
    }

    private static function isEmptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
