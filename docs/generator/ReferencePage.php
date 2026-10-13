<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

interface ReferencePage
{
    public function getFileName(): string;

    public function renderMarkdown(): string;
}
