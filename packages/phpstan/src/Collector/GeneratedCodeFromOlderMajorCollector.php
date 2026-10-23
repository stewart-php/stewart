<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\FileNode;
use Stewart\PHPStan\Manifest\ManifestCatalogLoader;

/** @implements Collector<FileNode, string> */
final readonly class GeneratedCodeFromOlderMajorCollector implements Collector
{
    public function __construct(private ManifestCatalogLoader $manifests) {}

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?string
    {
        return $this->manifests->findOlderMajorNamespaceTrackedBy($scope);
    }
}
