<?php
require_once __DIR__ . '/MockDatabase.php';

abstract class TestCase {
    protected $db;
    protected $passed = 0;
    protected $failed = 0;
    protected $errors = [];

    public function setUp(): void {
        MockDatabase::resetInstance();
        $this->db = MockDatabase::getInstance();
    }

    public function tearDown(): void {
    }

    public function runAll(): array {
        $results = [
            'class' => get_class($this),
            'tests' => [],
            'passed' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (strpos($method, 'test') === 0) {
                $this->setUp();
                try {
                    $this->$method();
                    $results['tests'][$method] = 'passed';
                    $results['passed']++;
                } catch (Exception $e) {
                    $results['tests'][$method] = 'failed';
                    $results['failed']++;
                    $results['errors'][$method] = $e->getMessage();
                }
                $this->tearDown();
            }
        }

        return $results;
    }

    protected function assertTrue($condition, string $message = ''): void {
        if (!$condition) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 'Expected true, got false');
        }
    }

    protected function assertFalse($condition, string $message = ''): void {
        if ($condition) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 'Expected false, got true');
        }
    }

    protected function assertEquals($expected, $actual, string $message = ''): void {
        if ($expected != $actual) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 
                "Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
        }
    }

    protected function assertSame($expected, $actual, string $message = ''): void {
        if ($expected !== $actual) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 
                "Expected " . var_export($expected, true) . " (same type), got " . var_export($actual, true));
        }
    }

    protected function assertNotEmpty($value, string $message = ''): void {
        if (empty($value)) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 'Expected non-empty value');
        }
    }

    protected function assertEmpty($value, string $message = ''): void {
        if (!empty($value)) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 'Expected empty value');
        }
    }

    protected function assertNull($value, string $message = ''): void {
        if ($value !== null) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 
                'Expected null, got ' . var_export($value, true));
        }
    }

    protected function assertNotNull($value, string $message = ''): void {
        if ($value === null) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 'Expected not null');
        }
    }

    protected function assertCount($expected, $array, string $message = ''): void {
        $count = is_array($array) || $array instanceof Countable ? count($array) : 0;
        if ($count !== $expected) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 
                "Expected count $expected, got $count");
        }
    }

    protected function assertArrayHasKey($key, $array, string $message = ''): void {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 
                "Expected key '$key' in array");
        }
    }

    protected function assertStringContainsString($needle, $haystack, string $message = ''): void {
        if (strpos($haystack, $needle) === false) {
            throw new Exception("Assertion failed: $message" . ($message ? ' - ' : '') . 
                "Expected '$needle' to be found in '$haystack'");
        }
    }

    protected function expectException(string $exceptionClass): void {
    }
}
