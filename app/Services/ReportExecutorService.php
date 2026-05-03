<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

class ReportExecutorService
{
    /** Whitelist of placeholder names allowed in report SQL. */
    private const ALLOWED_PLACEHOLDERS = ['student_id'];

    private BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Execute a report's SQL with optional named parameter bindings.
     *
     * @param string             $sql    SQL containing :placeholder names
     * @param array<string,int>  $params Map of placeholder => value (only int values allowed)
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
            $sql = rtrim($sql, '; ') . ' LIMIT 200';
        }

        return $sql;
    }

    /**
     * Replace :name placeholders with ? and produce a positional bindings array.
     * Casts values to int (we only allow int IDs as parameters today).
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private function bindNamedParameters(string $sql, array $params): array
    {
        $bindings = [];

        $boundSql = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            function (array $m) use ($params, &$bindings): string {
                $name = $m[1];

                if (!in_array($name, self::ALLOWED_PLACEHOLDERS, true)) {
                    throw new \RuntimeException("Disallowed placeholder ':{$name}' in report SQL.");
                }
                if (!array_key_exists($name, $params)) {
                    throw new \RuntimeException("Missing value for placeholder ':{$name}'.");
                }

                $bindings[] = (int) $params[$name];
                return '?';
            },
            $sql
        );

        return [$boundSql, $bindings];
    }
}
