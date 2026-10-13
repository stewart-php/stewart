<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

use LogicException;
use Stewart\Contracts\Exception\ExceptionReason;

final readonly class ErrorsPage implements ReferencePage
{
    /**
     * @param list<class-string<ExceptionReason>> $reasonEnums
     * @param array<string, string> $adviceByReason
     */
    public function __construct(
        private array $reasonEnums,
        private array $adviceByReason,
    ) {}

    public function getFileName(): string
    {
        return 'reference/errors.md';
    }

    public function renderMarkdown(): string
    {
        $sections = [
            'Every failure Stewart reports has a reason. The exception class names the part of Stewart that failed, and',
            '`$e->reason` names the failure: `StoreError::TypeMismatch` belongs to a `StoreException`. Words in braces are',
            'filled in from `$e->context`. [Handling errors](../guides/handling-errors.md) explains how to catch them.',
        ];
        $used = [];

        foreach ($this->reasonEnums as $enum) {
            $rows = [];

            foreach ($enum::cases() as $case) {
                $key = $this->getShortName($enum) . '::' . $case->name;
                $used[$key] = true;
                $rows[] = [MarkdownTable::formatCode($case->name), $case->messageTemplate(), $this->adviceByReason[$key] ?? ''];
            }

            $exception = substr($enum, 0, -\strlen('Error')) . 'Exception';
            array_push($sections, '', '## ' . $this->getShortName($enum), '');

            if (class_exists($exception)) {
                array_push($sections, 'Thrown as `' . $this->getShortName($exception) . '`.', '');
            }

            $sections[] = MarkdownTable::formatTable(['Reason', 'Message', 'What to do'], $rows);
        }

        $unused = array_diff(array_keys($this->adviceByReason), array_keys($used));

        if ($unused !== []) {
            throw new LogicException('Advice for reasons that do not exist: ' . implode(', ', $unused));
        }

        return new MarkdownPage('Error reference', 'Every error reason Stewart reports, its message, and what to do about it.', 3, implode("\n", $sections))
            ->renderMarkdown();
    }

    /** @param class-string $class */
    private function getShortName(string $class): string
    {
        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
