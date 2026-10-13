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
        foreach ($pages as $page) {
            $path = $this->directory . '/' . $page->getFileName();

            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0o777, true);
            }

            file_put_contents($path, $page->renderMarkdown());
        }
    }
}
