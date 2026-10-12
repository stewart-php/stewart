<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

$config = new Configuration();

foreach (['config', 'bin'] as $productionDirectory) {
    if (is_dir(getcwd() . '/' . $productionDirectory)) {
        $config->addPathToScan(getcwd() . '/' . $productionDirectory, isDev: false);
    }
}

// One config serves every package, so each ignore matches only in some of them.
return $config
    ->disableReportingUnmatchedIgnores()
    ->ignoreUnknownFunctionsRegex('~^Symfony\\\\Component\\\\DependencyInjection\\\\Loader\\\\Configurator\\\\~')
    ->ignoreUnknownClasses(['Stewart\Runtime\Tests\Fixtures\Generated\NotGeneratedYet']);
