<?php

declare(strict_types=1);

/**
 * Docker Compose integration smoke checks.
 *
 * Run inside the already-running app service:
 *   docker compose exec app php tests/integration.php
 */

$root = dirname(__DIR__);
$baseUrl = rtrim(getenv('SMOKE_BASE_URL') ?: 'http://127.0.0.1', '/');
$reportConfigPath = $root . '/writable/report_configs.json';
$backupPath = null;
$hadOriginalReportConfig = is_file($reportConfigPath);

final class IntegrationSuite
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

function requestUrl(string $url): array
{
    $headers = [];
    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (isset($http_response_header) && is_array($http_response_header)) {
        $headers = $http_response_header;
    }

    $status = 0;
    if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
        $status = (int) $m[1];
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => $body === false ? '' : $body,
    ];
}

function requireStatus(array $response, int $status, string $label): void
{
    if ($response['status'] !== $status) {
        throw new RuntimeException("{$label} returned HTTP {$response['status']}, expected {$status}");
    }
}

function dbConnect(): mysqli
{
    $host = getenv('SMOKE_DB_HOST') ?: 'db';
    $user = getenv('SMOKE_DB_USER') ?: 'student_user';
    $pass = getenv('SMOKE_DB_PASSWORD') ?: 'secret';
    $name = getenv('SMOKE_DB_NAME') ?: 'student_db';
    $port = (int) (getenv('SMOKE_DB_PORT') ?: 3306);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli($host, $user, $pass, $name, $port);
    $db->set_charset('utf8mb4');
    return $db;
}

function fetchRows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);
    return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function writeKnownReportConfigs(string $path): void
{
    $now = date('Y-m-d H:i:s');
    $rows = [
        [
            'report_id' => 'smoke_subject_average',
            'title' => 'Smoke Subject Average',
            'description' => 'Average score by subject for integration smoke tests.',
            'category' => 'academic_performance',
            'sql_query' => 'SELECT s.name AS subject_name, ROUND(AVG(g.score), 1) AS avg_score FROM grades g JOIN subjects s ON g.subject_id = s.id GROUP BY s.id, s.name ORDER BY s.name LIMIT 50',
            'chart_type' => 'bar',
            'x_axis' => 'subject_name',
            'y_axis' => 'avg_score',
            'parameters' => [],
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'report_id' => 'student_subject_performance',
            'title' => 'Subject Performance for Student',
            'description' => 'Scores by subject for a selected student.',
            'category' => 'student_drilldown',
            'sql_query' => 'SELECT s.name AS subject_name, g.score AS score FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = :student_id ORDER BY s.name LIMIT 50',
            'chart_type' => 'bar',
            'x_axis' => 'subject_name',
            'y_axis' => 'score',
            'parameters' => ['student_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ];

    $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException("Could not write known report config to {$path}");
    }
}

if ($hadOriginalReportConfig) {
    $backupPath = $reportConfigPath . '.integration-backup-' . getmypid();
    if (!copy($reportConfigPath, $backupPath)) {
        throw new RuntimeException('Could not create report config backup');
    }
}

register_shutdown_function(static function () use ($reportConfigPath, $backupPath, $hadOriginalReportConfig): void {
    if ($hadOriginalReportConfig && $backupPath !== null && is_file($backupPath)) {
        rename($backupPath, $reportConfigPath);
        return;
    }

    if (!$hadOriginalReportConfig && is_file($reportConfigPath)) {
        unlink($reportConfigPath);
    }
});

$suite = new IntegrationSuite();

$suite->run('database schema and seed data are present', function () use ($suite): void {
    $db = dbConnect();
    foreach (['students', 'subjects', 'grades', 'student_subject_completion'] as $table) {
        $rows = fetchRows($db, "SELECT COUNT(*) AS total FROM `{$table}`");
        $suite->assertTrue((int) $rows[0]['total'] > 0, "{$table} should contain seed data");
    }

    $joinRows = fetchRows($db, 'SELECT s.name AS subject_name, ROUND(AVG(g.score), 1) AS avg_score FROM grades g JOIN subjects s ON g.subject_id = s.id GROUP BY s.id, s.name LIMIT 1');
    $suite->assertTrue(count($joinRows) === 1, 'Dashboard/report grade-subject join should return data');
    $db->close();
});

$suite->run('known report configs are installed for integration checks', function () use ($reportConfigPath, $suite): void {
    writeKnownReportConfigs($reportConfigPath);
    $rows = json_decode((string) file_get_contents($reportConfigPath), true);
    $suite->assertTrue(is_array($rows) && count($rows) === 2, 'Known integration report configs should be readable');
});

$suite->run('public HTTP pages render successfully', function () use ($baseUrl, $suite): void {
    foreach ([
        '/' => 'Dashboard',
        '/dashboard' => 'Dashboard',
        '/reports' => 'Reports',
        '/analysis' => 'Analysis',
        '/reports/smoke_subject_average' => 'Smoke Subject Average',
        '/reports/student_subject_performance?student_id=1' => 'Subject Performance for Student',
    ] as $path => $expectedText) {
        $response = requestUrl($baseUrl . $path);
        requireStatus($response, 200, $path);
        $suite->assertTrue(str_contains($response['body'], $expectedText), "{$path} should contain {$expectedText}");
    }
});

$suite->run('student typeahead returns JSON results', function () use ($baseUrl, $suite): void {
    $response = requestUrl($baseUrl . '/students/search?q=A');
    requireStatus($response, 200, '/students/search');
    $data = json_decode($response['body'], true);
    $suite->assertTrue(json_last_error() === JSON_ERROR_NONE, 'Student search should return valid JSON');
    $suite->assertTrue(is_array($data), 'Student search response should be an array');
    $suite->assertTrue(count($data) > 0, 'Student search should return at least one seeded student');
    foreach (['id', 'name', 'email'] as $key) {
        $suite->assertTrue(array_key_exists($key, $data[0]), "Student search result should include {$key}");
    }
});

$suite->run('report chart axis contracts match SQL result columns', function () use ($reportConfigPath, $suite): void {
    $configs = json_decode((string) file_get_contents($reportConfigPath), true);
    $suite->assertTrue(is_array($configs), 'Report configs should decode');
    $db = dbConnect();

    foreach ($configs as $config) {
        $sql = str_replace(':student_id', '1', $config['sql_query']);
        $rows = fetchRows($db, $sql);
        $suite->assertTrue(count($rows) > 0, "Report {$config['report_id']} should return rows");
        $columns = array_keys($rows[0]);
        $suite->assertTrue(in_array($config['x_axis'], $columns, true), "Report {$config['report_id']} x_axis should be returned by SQL");
        $suite->assertTrue(in_array($config['y_axis'], $columns, true), "Report {$config['report_id']} y_axis should be returned by SQL");
    }

    $db->close();
});

$suite->run('PDF exports return PDF bytes', function () use ($baseUrl, $suite): void {
    foreach ([
        '/reports/pdf/smoke_subject_average',
        '/reports/pdf/student_subject_performance?student_id=1',
    ] as $path) {
        $response = requestUrl($baseUrl . $path);
        requireStatus($response, 200, $path);
        $suite->assertTrue(str_starts_with($response['body'], '%PDF'), "{$path} should return PDF bytes");
    }
});

exit($suite->finish());
