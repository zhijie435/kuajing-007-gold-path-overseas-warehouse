<?php
require_once __DIR__ . '/bootstrap.php';

class TestRunner {
    private $totalPassed = 0;
    private $totalFailed = 0;
    private $results = [];

    public function runTestClass($className) {
        if (!class_exists($className)) {
            require_once __DIR__ . '/' . $className . '.php';
        }
        $test = new $className();
        $result = $test->runAll();
        
        $this->totalPassed += $result['passed'];
        $this->totalFailed += $result['failed'];
        $this->results[] = $result;
        
        return $result;
    }

    public function runAll() {
        $testFiles = glob(__DIR__ . '/*Test.php');
        
        echo "========================================\n";
        echo "  海外仓一件代发系统 - 单元测试\n";
        echo "========================================\n\n";
        
        foreach ($testFiles as $file) {
            $className = basename($file, '.php');
            require_once $file;
            
            if (class_exists($className) && is_subclass_of($className, 'TestCase')) {
                $this->printClassResult($this->runTestClass($className));
            }
        }
        
        $this->printSummary();
        
        return $this->totalFailed === 0;
    }

    private function printClassResult($result) {
        $className = $result['class'];
        $passed = $result['passed'];
        $failed = $result['failed'];
        $total = $passed + $failed;
        
        $status = $failed === 0 ? '✓ 通过' : '✗ 失败';
        $statusColor = $failed === 0 ? "\033[32m" : "\033[31m";
        $resetColor = "\033[0m";
        
        echo "{$statusColor}{$status}{$resetColor} {$className} ({$passed}/{$total})\n";
        
        if ($failed > 0) {
            foreach ($result['errors'] as $test => $error) {
                echo "    - {$test}: {$error}\n";
            }
        }
        
        echo "\n";
    }

    private function printSummary() {
        $total = $this->totalPassed + $this->totalFailed;
        
        echo "========================================\n";
        echo "  测试总结\n";
        echo "========================================\n";
        echo "  总测试数: {$total}\n";
        echo "  通过: {$this->totalPassed}\n";
        echo "  失败: {$this->totalFailed}\n";
        echo "  通过率: " . ($total > 0 ? round($this->totalPassed / $total * 100, 2) : 0) . "%\n";
        echo "========================================\n";
    }
}

$runner = new TestRunner();
$success = $runner->runAll();

exit($success ? 0 : 1);
