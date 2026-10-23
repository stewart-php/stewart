<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Psr\Log\LoggerInterface;

final readonly class CatalogWarnings
{
    public function logCatalogWarnings(DiscoveryResult $discovered, AppCatalog $apps, string $configPath, LoggerInterface $logger): void
    {
        foreach ($discovered->unmarked as $unmarked) {
            $logger->warning('A class in apps/ implements App but is not marked #[Automation] or #[AppTemplate], so it will not run.', [
                'class' => $unmarked->class,
            ]);
        }

        foreach ($discovered->listUnusedTemplateClasses() as $template) {
            $logger->notice('An #[AppTemplate] class runs only through apps.<id>.class, and no entry names it.', [
                'class' => $template,
            ]);
        }

        foreach ($discovered->unloadable as $file) {
            $logger->error('A file in apps/ failed to load, so the automation it declares will not run.', [
                'file' => $file->path,
                'exception' => $file->cause,
            ]);
        }

        if (!$apps->unmatchedOverrideIds->isEmpty()) {
            $logger->warning('stewart.yaml or the environment configures automations that were not found, so their settings are ignored.', [
                'apps' => $apps->unmatchedOverrideIds->toStrings(),
                'known' => $apps->knownIds->toStrings(),
            ]);
        }

        if ($apps->enabled->isEmpty()) {
            $logger->warning('No automations to run; Stewart will connect and do nothing.', [
                'config' => $configPath,
                'found' => $discovered->apps->count(),
            ]);
        }
    }
}
