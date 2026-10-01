<?php

declare(strict_types=1);

require_once __DIR__ . '/InspectorTest.php';

use EidCloud\AgentInspector\Tests\InspectorTest;

echo "=======================================================\n";
echo " EidCloud Agent Inspector - Automated Test Suite\n";
echo " Pure PHP 8.2+ (Zero external dependencies)\n";
echo "=======================================================\n\n";

$test = new InspectorTest();
$ref = new ReflectionClass($test);
$methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

$passed = 0;
$failed = 0;
$start = microtime(true);

foreach ($methods as $method) {
    if (!str_starts_with($method->getName(), 'test')) {
        continue;
    }

    $name = $method->getName();
    echo sprintf("  %-50s ", $name);

    try {
        $test->{$name}();
        echo "\033[32m[PASS]\033[0m\n";
        $passed++;
    } catch (Throwable $e) {
        echo "\033[31m[FAIL]\033[0m\n";
        echo "    \033[31mError:\033[0m " . $e->getMessage() . "\n";
        echo "    \033[90m" . $e->getFile() . ':' . $e->getLine() . "\033[0m\n\n";
        $failed++;
    }
}

$elapsed = round((microtime(true) - $start) * 1000, 2);
echo "\n=======================================================\n";
echo sprintf(" Result: %d passed, %d failed in %0.2f ms\n", $passed, $failed, $elapsed);
echo "=======================================================\n\n";

if ($failed > 0) {
    exit(1);
}

exit(0);
