<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

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
    ->ignoreUnknownClasses(['Stewart\Runtime\Tests\Fixtures\Generated\NotGeneratedYet'])
    // stewart-php/phpstan gets PhpParser from phpstan.phar; the vendor copy comes only with PHPUnit.
    ->ignoreErrorsOnPackage('nikic/php-parser', [ErrorType::SHADOW_DEPENDENCY]);
