<?php

namespace Nudelsalat\Tests;

abstract class TestCase
{
    /** @var string[] */
    private array $temporaryPaths = [];

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            $this->removePath($path);
        }
        $this->temporaryPaths = [];
    }

    protected function fail(string $message): void
    {
        throw new \RuntimeException($message);
    }

    protected function markSkipped(string $message): void
    {
        throw new SkipTest($message);
    }

    protected function assertTrue(bool $condition, string $message = 'Expected condition to be true.'): void
    {
        if (!$condition) {
            $this->fail($message);
        }
    }

    protected function assertFalse(bool $condition, string $message = 'Expected condition to be false.'): void
    {
        if ($condition) {
            $this->fail($message);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail($message !== '' ? $message : $this->formatComparisonFailure($expected, $actual));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            $this->fail($message !== '' ? $message : $this->formatComparisonFailure($expected, $actual));
        }
    }

    protected function assertCount(int $expectedCount, iterable $actual, string $message = ''): void
    {
        $count = is_array($actual) ? count($actual) : iterator_count($actual);
        $this->assertSame($expectedCount, $count, $message !== '' ? $message : "Expected count {$expectedCount}, got {$count}.");
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $this->fail($message !== '' ? $message : "Expected to find '{$needle}' in output.\nActual: {$haystack}");
        }
    }

    protected function assertInstanceOf(string $className, mixed $value, string $message = ''): void
    {
        if (!$value instanceof $className) {
            $type = is_object($value) ? get_class($value) : gettype($value);
            $this->fail($message !== '' ? $message : "Expected instance of {$className}, got {$type}.");
        }
    }

    protected function assertThrows(string $exceptionClass, callable $callback, string $messageContains = ''): \Throwable
    {
        ob_start();
        try {
            $callback();
            ob_end_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            if (!$throwable instanceof $exceptionClass) {
                $this->fail("Expected exception {$exceptionClass}, got " . get_class($throwable) . ': ' . $throwable->getMessage());
            }

            if ($messageContains !== '' && !str_contains($throwable->getMessage(), $messageContains)) {
                $this->fail("Expected exception message to contain '{$messageContains}', got '{$throwable->getMessage()}'.");
            }

            return $throwable;
        }

        $this->fail("Expected exception {$exceptionClass} to be thrown.");
    }

    protected function createTemporaryDirectory(string $prefix = 'nudelsalat-test-'): string
    {
        $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . uniqid('', true);
        if (!mkdir($base, 0777, true) && !is_dir($base)) {
            throw new \RuntimeException("Failed to create temporary directory: {$base}");
        }

        $this->temporaryPaths[] = $base;
        return $base;
    }

    protected function captureOutput(callable $callback): string
    {
        ob_start();
        try {
            $callback();
            return (string) ob_get_clean();
        } catch (\Throwable $throwable) {
            ob_end_clean();
            throw $throwable;
        }
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }

    private function formatComparisonFailure(mixed $expected, mixed $actual): string
    {
        return "Expected:\n" . var_export($expected, true) . "\nActual:\n" . var_export($actual, true);
    }
}

class SkipTest extends \RuntimeException
{
}
