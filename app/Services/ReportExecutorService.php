<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Reports as ReportsConfig;

class ReportExecutorService
{
    private BaseConnection $db;
    private ReportsConfig $config;

    public function __construct(?ReportsConfig $config = null)
    {
        $this->db     = \Config\Database::connect();
        $this->config = $config ?? new ReportsConfig();
    }

    /**
     * Execute a report's SQL with optional named parameter bindings.
     *
     * @param string             $sql    SQL containing :placeholder names
     * @param array<string,mixed> $params Map of placeholder => value (cast per Reports config)
     */
    public function execute(string $sql, array $params = []): array
    {
        $sql = $this->enforceSafeQuery($sql);
        [$boundSql, $bindings] = $this->bindNamedParameters($sql, $params);

        try {
            return $this->db->query($boundSql, $bindings)->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'ReportExecutorService SQL error: ' . $e->getMessage() . ' | SQL: ' . $boundSql);
            throw new \RuntimeException('Report query failed: ' . $e->getMessage());
        }
    }

    private function enforceSafeQuery(string $sql): string
    {
        $trimmed = ltrim($sql);
        if (!preg_match('/^SELECT\s/i', $trimmed)) {
            throw new \RuntimeException('Only SELECT queries are allowed in reports.');
        }

        if (!preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            $sql = rtrim($sql, '; ') . ' LIMIT ' . (int) $this->config->maxRowsPerReport;
        }

        return $sql;
    }

    /**
     * Replace :name placeholders with ? and produce a positional bindings array.
     * Cast type per placeholder is read from Reports::$allowedPlaceholders.
     *
     * @return array{0: string, 1: array<int, int|string>}
     */
    private function bindNamedParameters(string $sql, array $params): array
    {
        $allowed  = $this->config->allowedPlaceholders;
        $bindings = [];

        $boundSql = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            function (array $m) use ($allowed, $params, &$bindings): string {
                $name = $m[1];

                if (!array_key_exists($name, $allowed)) {
                    throw new \RuntimeException("Disallowed placeholder ':{$name}' in report SQL.");
                }
                if (!array_key_exists($name, $params)) {
                    throw new \RuntimeException("Missing value for placeholder ':{$name}'.");
                }

                $bindings[] = $allowed[$name] === 'string'
                    ? (string) $params[$name]
                    : (int) $params[$name];
                return '?';
            },
            $sql
        );

        return [$boundSql, $bindings];
    }
}