<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

final readonly class ReferenceWriter
{
    public function __construct(private string $directory) {}

    /**
     * @param list<ReferencePage> $pages
     * @return list<string>
     */
    public function listStaleFiles(array $pages): array
    {
        $stale = [];

        foreach ($pages as $page) {
            $path = $this->directory . '/' . $page->getFileName();

            if (!is_file($path) || file_get_contents($path) !== $page->renderMarkdown()) {
                $stale[] = $path;
            }
        }

        return $stale;
    }

    /** @param list<ReferencePage> $pages */
    public function writePages(array $pages): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o777, true);
        }

        foreach ($pages as $page) {
            file_put_contents($this->directory . '/' . $page->getFileName(), $page->renderMarkdown());
        }
    }
}
