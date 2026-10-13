<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

final readonly class UpgradingPage implements ReferencePage
{
    private const string RELEASE_HEADING = '/^## \[(\d+)\.(\d+)\.\d+\][^\n]*\n(.*?)(?=^## |\z)/ms';

    private const string UPGRADING_SECTION = '/^### Upgrading\n(.*?)(?=^### |\z)/ms';

    public function __construct(private string $changelog) {}

    public function getFileName(): string
    {
        return 'help/upgrading.md';
    }

    public function renderMarkdown(): string
    {
        $current = [];
        $older = [];

        foreach ($this->listUpgradeNotes() as $version => $notes) {
            $section = ['', '### ' . $version, '', $notes];
            str_starts_with($version, '0.') ? array_push($older, ...$section) : array_push($current, ...$section);
        }

        $body = [
            'Stewart is one version for everything: the packages in `composer.json`, the runtime image, and the Home Assistant',
            'integration. Upgrade them together.',
            '',
            '## How to upgrade',
            '',
            '1. Read the notes for every version between yours and the new one, below and in the',
            '   [changelog](https://github.com/stewart-php/stewart/blob/main/CHANGELOG.md).',
            '2. On your machine, run `make update` for the newest release of your version, or `make upgrade VERSION=x.y` to',
            '   move to another version. `make upgrade` also changes the image tags in `Dockerfile` and the compose files.',
            '3. Run `make generate`, `make stan` and `make test`, and follow the notes.',
            '4. Commit `composer.lock` and the changed files, and deploy as usual.',
            '5. If you use the Stewart integration in Home Assistant, update it to the same version.',
            '',
            'Within a major version, such as 1.x, a new release does not break your apps. Notes appear here only when a',
            'release asks you to do something.',
        ];

        if ($current !== []) {
            array_push($body, '', '## Upgrade notes', ...$current);
        }

        if ($older !== []) {
            array_push($body, '', '## Before 1.0', '', 'Each 0.x version could break apps; these notes are kept for old projects.', ...$older);
        }

        return new MarkdownPage('Upgrading', 'How to move to a new Stewart version, and what each version asks you to change.', 4, implode("\n", $body))
            ->renderMarkdown();
    }

    /** @return array<string, string> */
    private function listUpgradeNotes(): array
    {
        $changelog = file_get_contents($this->changelog);
        preg_match_all(self::RELEASE_HEADING, $changelog === false ? '' : $changelog, $releases, \PREG_SET_ORDER);
        $notes = [];

        foreach ($releases as [, $major, $minor, $content]) {
            if (preg_match(self::UPGRADING_SECTION, $content, $upgrading) === 1) {
                $notes[$major . '.' . $minor] ??= trim($upgrading[1]);
            }
        }

        return $notes;
    }
}
