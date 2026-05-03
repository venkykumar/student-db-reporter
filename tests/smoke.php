<?php

declare(strict_types=1);

/**
 * Lightweight smoke checks for the student DB reporter.
 *
 * These tests intentionally avoid MySQL, Claude, and HTTP servers so they can
 * run in a fresh checkout with only Composer dependencies installed.
 */

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';

if (!defined('ROOTPATH')) {
    define('ROOTPATH', $root . DIRECTORY_SEPARATOR);
}
if (!defined('APPPATH')) {
    define('APPPATH', $root . '/app/');
}
if (!defined('WRITEPATH')) {
    define('WRITEPATH', $root . '/writable/');
}

if (!function_exists('log_message')) {
    function log_message(string $level, string $message): void
    {
        // The smoke runner does not boot CodeIgniter; keep validator logging inert.
    }
}

final class SmokeSuite
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            echo "PASS {$name}\n";
        } catch (Throwable $e) {
            $this->failed++;
            echo "FAIL {$name}: {$e->getMessage()}\n";
        }
    }

    public function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public function finish(): int
    {
        echo "\n{$this->passed} passed, {$this->failed} failed\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

function readFileOrFail(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Could not read {$path}");
    }
    return $contents;
}

function validateReportConfigRows(array $rows, SmokeSuite $suite): void
{
    $required = [
        'report_id',
        'title',
        'description',
        'category',
        'sql_query',
        'chart_type',
        'x_axis',
        'y_axis',
        'parameters',
        'created_at',
        'updated_at',
    ];
    $allowedCharts = ['bar', 'line', 'pie', 'doughnut', 'scatter'];
    $allowedParams = ['student_id'];
    $ids = [];

    foreach ($rows as $index => $row) {
        $suite->assertTrue(is_array($row), "Report config row {$index} must be an object/array");

        foreach ($required as $key) {
            $suite->assertTrue(array_key_exists($key, $row), "Report config row {$index} is missing {$key}");
        }

        $id = (string) $row['report_id'];
        $suite->assertTrue($id !== '', "Report config row {$index} has an empty report_id");
        $suite->assertTrue(!isset($ids[$id]), "Duplicate report_id {$id}");
        $ids[$id] = true;

        $sql = ltrim((string) $row['sql_query']);
        $suite->assertTrue((bool) preg_match('/^SELECT\s/i', $sql), "Report {$id} SQL must start with SELECT");
        $suite->assertTrue(!(bool) preg_match('/\b(DROP|DELETE|UPDATE|INSERT|ALTER|TRUNCATE|GRANT|REVOKE)\b/i', $sql), "Report {$id} SQL contains a forbidden keyword");

        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $matches);
        $usedParams = array_unique($matches[1] ?? []);
        $suite->assertSame([], array_values(array_diff($usedParams, $allowedParams)), "Report {$id} uses an unsupported SQL placeholder");

        $params = $row['parameters'];
        $suite->assertTrue(is_array($params), "Report {$id} parameters must be an array");
        $suite->assertSame([], array_values(array_diff($params, $allowedParams)), "Report {$id} declares an unsupported parameter");
        $suite->assertSame([], array_values(array_diff($usedParams, $params)), "Report {$id} SQL placeholders must match declared parameters");

        $suite->assertTrue(in_array($row['chart_type'], $allowedCharts, true), "Report {$id} has unsupported chart_type {$row['chart_type']}");
        $suite->assertTrue((string) $row['x_axis'] !== '', "Report {$id} has an empty x_axis");
        $suite->assertTrue((string) $row['y_axis'] !== '', "Report {$id} has an empty y_axis");
    }
}

$suite = new SmokeSuite();

$suite->run('critical PHP files have valid syntax', function () use ($root, $suite): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                $path = $file->getPathname();
                return !str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
                    && !str_contains($path, DIRECTORY_SEPARATOR . 'writable' . DIRECTORY_SEPARATOR)
                    && !str_contains($path, DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR);
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
        exec($cmd, $output, $code);
        $suite->assertSame(0, $code, implode("\n", $output));
    }
});

$suite->run('expected public routes are registered', function () use ($root, $suite): void {
    $routes = readFileOrFail($root . '/app/Config/Routes.php');
    foreach ([
        "'/'",
        "'/dashboard'",
        "'/reports'",
        "'/reports/pdf/(:segment)'",
        "'/reports/(:segment)'",
        "'/analysis'",
        "'/analysis/run'",
        "'/analysis/regenerate'",
        "'/students/search'",
    ] as $route) {
        $suite->assertTrue(str_contains($routes, $route), "Missing route {$route}");
    }
});

$suite->run('cached report configs are valid when present', function () use ($root, $suite): void {
    $path = $root . '/writable/report_configs.json';
    if (!is_file($path)) {
        return;
    }

    $raw = readFileOrFail($path);
    $rows = json_decode($raw, true);
    $suite->assertTrue(json_last_error() === JSON_ERROR_NONE, 'report_configs.json is not valid JSON: ' . json_last_error_msg());
    $suite->assertTrue(is_array($rows), 'report_configs.json must contain a JSON array');
    validateReportConfigRows($rows, $suite);
});

$suite->run('ReportConfigModel reads, writes, and finds report configs', function () use ($suite): void {
    $path = sys_get_temp_dir() . '/student-db-reporter-smoke-' . getmypid() . '.json';
    @unlink($path);

    $model = new App\Models\ReportConfigModel($path);
    $suite->assertSame([], $model->findAll(), 'New report config store should be empty');

    $model->replaceAll([
        [
            'id' => 'student_subject_performance',
            'title' => 'Subject Performance for Student',
            'description' => 'Scores by subject for a selected student.',
            'category' => 'student_drilldown',
            'sql' => 'SELECT s.name AS subject_name, g.score AS score FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = :student_id ORDER BY s.name',
            'chart_type' => 'bar',
            'x_axis' => 'subject_name',
            'y_axis' => 'score',
            'parameters' => ['student_id'],
        ],
    ]);

    $rows = $model->findAll();
    validateReportConfigRows($rows, $suite);
    $suite->assertSame('student_subject_performance', $model->findByReportId('student_subject_performance')['report_id'] ?? null, 'Report lookup should find the stored report');
    $suite->assertSame(null, $model->findByReportId('missing_report'), 'Unknown report lookup should return null');
    $suite->assertSame(['student_id'], App\Models\ReportConfigModel::decodeParameters('["student_id"]'), 'JSON parameters should decode');

    @unlink($path);
});

$suite->run('Analysis validator accepts safe Claude JSON and rejects unsafe entries', function () use ($suite): void {
    $analysis = (new ReflectionClass(App\Controllers\Analysis::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(App\Controllers\Analysis::class, 'parseAndValidate');
    $method->setAccessible(true);

    $raw = json_encode([
        [
            'id' => 'safe_report',
            'title' => 'Safe Report',
            'sql' => 'SELECT s.name AS subject_name, COUNT(*) AS total FROM subjects s GROUP BY s.name LIMIT 50',
            'parameters' => [],
        ],
        [
            'id' => 'unsafe_report',
            'title' => 'Unsafe Report',
            'sql' => 'DELETE FROM students',
            'parameters' => [],
        ],
        [
            'id' => 'bad_placeholder',
            'title' => 'Bad Placeholder',
            'sql' => 'SELECT * FROM grades WHERE student_id = :other_id',
            'parameters' => ['other_id'],
        ],
        [
            'id' => 'undeclared_placeholder',
            'title' => 'Undeclared Placeholder',
            'sql' => 'SELECT * FROM grades WHERE student_id = :student_id',
            'parameters' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    $valid = $method->invoke($analysis, $raw);
    $suite->assertSame(1, count($valid), 'Only one report should pass validation');
    $suite->assertSame('safe_report', $valid[0]['id'], 'The safe report should pass validation');
});

$suite->run('Analysis validator strips markdown JSON fences', function () use ($suite): void {
    $analysis = (new ReflectionClass(App\Controllers\Analysis::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(App\Controllers\Analysis::class, 'parseAndValidate');
    $method->setAccessible(true);

    $raw = <<<'JSON'
```json
[
  {
    "id": "fenced_report",
    "title": "Fenced Report",
    "sql": "SELECT name AS subject_name, total_lessons AS total_lessons FROM subjects LIMIT 10",
    "parameters": []
  }
]
```
JSON;

    $valid = $method->invoke($analysis, $raw);
    $suite->assertSame(1, count($valid), 'Markdown-fenced JSON should parse');
    $suite->assertSame('fenced_report', $valid[0]['id'], 'The fenced report should pass validation');
});

exit($suite->finish());
