<?php

declare(strict_types=1);

// Writes the generated pages of the user documentation; --check only reports the ones that are out of date.

use Composer\Autoload\ClassLoader;
use Stewart\Docs\Generator\AdminApiPage;
use Stewart\Docs\Generator\CliPage;
use Stewart\Docs\Generator\ConfigurationPage;
use Stewart\Docs\Generator\ErrorsPage;
use Stewart\Docs\Generator\MetricsPage;
use Stewart\Docs\Generator\ReasonEnumFinder;
use Stewart\Docs\Generator\ReferenceWriter;
use Stewart\Docs\Generator\UpgradingPage;
use Stewart\Runtime\Kernel\ConsoleKernel;
use Stewart\Runtime\Metrics\RuntimeMetricSource;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;

$root = dirname(__DIR__);

/** @var ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';

/** @var array<string, string> $advice */
$advice = require $root . '/docs/generator/error-advice.php';

$console = new ConsoleKernel($root, $loader->getPrefixesPsr4())->createApplication();
$sources = array_map(
    static function (string $file): RuntimeMetricSource {
        $class = 'Stewart\Runtime\Metrics\Source\\' . basename($file, '.php');
        $source = new $class();
        assert($source instanceof RuntimeMetricSource);

        return $source;
    },
    glob($root . '/packages/runtime/src/Metrics/Source/*.php') ?: [],
);

$pages = [
    new ConfigurationPage($console),
    new CliPage($console, $root . '/skeleton/Makefile'),
    new ErrorsPage(new ReasonEnumFinder()->findReasonEnums($root . '/packages'), $advice),
    new MetricsPage($sources, new StubSnapshotSource()->takeSnapshot()),
    new AdminApiPage(),
    new UpgradingPage($root . '/CHANGELOG.md'),
];
$writer = new ReferenceWriter($root . '/docs/user');

/** @var list<string> $argv */
if (!in_array('--check', $argv, true)) {
    $writer->writePages($pages);

    exit(0);
}

$stale = $writer->listStaleFiles($pages);

foreach ($stale as $file) {
    fwrite(STDERR, substr($file, strlen($root) + 1) . " is out of date; run make docs-reference.\n");
}

exit($stale === [] ? 0 : 1);
