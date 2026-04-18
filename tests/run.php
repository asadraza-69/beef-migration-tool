<?php

require_once __DIR__ . '/bootstrap.php';

$caseFiles = glob(__DIR__ . '/cases/*Test.php');
sort($caseFiles);
foreach ($caseFiles as $file) {
    require_once $file;
}

$classes = array_filter(get_declared_classes(), static fn(string $class): bool => is_subclass_of($class, \Nudelsalat\Tests\TestCase::class));
sort($classes);

$results = [
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0,
    'failures' => [],
];

$start = microtime(true);
foreach ($classes as $className) {
    $reflection = new ReflectionClass($className);
    $testMethods = array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn(ReflectionMethod $method): bool => str_starts_with($method->getName(), 'test')
    );

    foreach ($testMethods as $method) {
        /** @var \Nudelsalat\Tests\TestCase $test */
        $test = $reflection->newInstance();
        $label = $reflection->getShortName() . '::' . $method->getName();

        try {
            $test->setUp();
            $method->invoke($test);
            $results['passed']++;
            echo "[PASS] {$label}\n";
        } catch (\Nudelsalat\Tests\SkipTest $skip) {
            $results['skipped']++;
            echo "[SKIP] {$label} - {$skip->getMessage()}\n";
        } catch (Throwable $throwable) {
            $results['failed']++;
            $results['failures'][] = [
                'label' => $label,
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ];
            echo "[FAIL] {$label} - {$throwable->getMessage()}\n";
        } finally {
            $test->tearDown();
        }
    }
}

$duration = round(microtime(true) - $start, 3);
echo "\nTest Report\n";
echo "Passed: {$results['passed']}\n";
echo "Skipped: {$results['skipped']}\n";
echo "Failed: {$results['failed']}\n";
echo "Duration: {$duration}s\n";

if ($results['failed'] > 0) {
    echo "\nFailures\n";
    foreach ($results['failures'] as $failure) {
        echo "- {$failure['label']}\n";
        echo "  Message: {$failure['message']}\n";
        echo "  Trace: {$failure['trace']}\n";
    }
    exit(1);
}

exit(0);
