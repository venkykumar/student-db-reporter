<?php

namespace App\Models;

class ReportConfigModel
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? WRITEPATH . 'report_configs.json';
    }

    public function findAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function findByReportId(string $reportId): ?array
    {
        foreach ($this->findAll() as $row) {
            if (($row['report_id'] ?? null) === $reportId) {
                return $row;
            }
        }
        return null;
    }

    public function hasReports(): bool
    {
        return $this->findAll() !== [];
    }

    public function replaceAll(array $configs): void
    {
        $rows = [];
        $now  = date('Y-m-d H:i:s');

        foreach ($configs as $config) {
            $rows[] = [
                'report_id'   => $config['id'],
                'title'       => $config['title'],
                'description' => $config['description'] ?? '',
                'category'    => $config['category']    ?? '',
                'sql_query'   => $config['sql'],
                'chart_type'  => $config['chart_type']  ?? 'bar',
                'x_axis'      => $config['x_axis']      ?? '',
                'y_axis'      => $config['y_axis']      ?? '',
                'parameters'  => isset($config['parameters']) && is_array($config['parameters'])
                    ? array_values($config['parameters'])
                    : [],
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        $json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode report configs as JSON: ' . json_last_error_msg());
        }

        $tmp = $this->path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write report configs to {$tmp}");
        }

        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to atomically replace {$this->path}");
        }
    }

    public static function decodeParameters(string|array|null $params): array
    {
        if ($params === null || $params === '') {
            return [];
        }
        if (is_array($params)) {
            return $params;
        }
        $decoded = json_decode($params, true);
        return is_array($decoded) ? $decoded : [];
    }
}