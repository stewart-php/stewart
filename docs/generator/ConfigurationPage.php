<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final readonly class ConfigurationPage implements ReferencePage
{
    public function __construct(private Application $console) {}

    public function getFileName(): string
    {
        return 'reference/configuration.md';
    }

    public function renderMarkdown(): string
    {
        $output = new BufferedOutput();
        $this->console->find('config:reference')->run(new ArrayInput([]), $output);

        return new MarkdownPage(
            'Configuration reference',
            'Every setting stewart.yaml understands, with its default.',
            1,
            implode("\n", [
                'Every setting, with its default and a short explanation. This is the output of `make config-reference`.',
                '[Configuration](../guides/configuration.md) explains how to set them, in `stewart.yaml` or as environment variables.',
                '',
                '```yaml',
                rtrim($output->fetch()),
                '```',
            ]),
        )->renderMarkdown();
    }
}
