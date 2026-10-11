<?php

declare(strict_types=1);

// Prints phpmetrics class violations in files changed since the base ref as GitHub annotations.

require __DIR__ . '/../vendor/autoload.php';

/** @var list<string> $argv */
[, $violationsFile, $baseRef] = $argv + [1 => '', 2 => ''];
$root = (string) realpath(__DIR__ . '/..') . '/';

exec('git diff --name-only ' . escapeshellarg($baseRef), $changedFiles, $exitCode);

if ($exitCode !== 0) {
    fwrite(\STDERR, "Cannot list the files changed since {$baseRef}.\n");

    exit(1);
}

$report = simplexml_load_file($violationsFile);

if ($report === false) {
    fwrite(\STDERR, "Cannot read {$violationsFile}.\n");

    exit(1);
}

foreach ($report->file as $subject) {
    $className = (string) $subject['name'];

    if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
        continue;
    }

    $class = new ReflectionClass($className);
    $path = str_replace($root, '', (string) realpath((string) $class->getFileName()));

    if (!in_array($path, $changedFiles, true)) {
        continue;
    }

    foreach ($subject->violation as $violation) {
        $message = trim((string) preg_replace('/\s+/', ' ', (string) $violation));

        printf("::warning file=%s,line=%d,title=%s::%s\n", $path, $class->getStartLine(), $violation['rule'], $message);
    }
}
