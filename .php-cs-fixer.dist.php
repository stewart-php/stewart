<?php

declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/packages', __DIR__ . '/tests', __DIR__ . '/skeleton'])
    // Generated code follows the generator's formatting, not this one's.
    ->notPath(['codegen/tests/Fixtures/Expected', 'runtime/tests/Fixtures/Generated/Code'])
    ->append([__DIR__ . '/packages/runtime/bin/stewart', __FILE__]);

return new Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS3x0' => true,
        '@PHP8x4Migration' => true,

        'no_unused_imports' => true,
        'fully_qualified_strict_types' => [
            'import_symbols' => true,
        ],
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => false,
        ],

        'binary_operator_spaces' => true,

        'declare_strict_types' => true,

        'void_return' => true,
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
        ],
    ])
    ->setFinder($finder)
    ->setCacheFile('var/php-cs-fixer.cache');
