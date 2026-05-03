<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Reports as ReportsConfig;

class SchemaInspectorService
{
    private BaseConnection $db;
    private ReportsConfig $config;

    public function __construct(?ReportsConfig $config = null)
    {
        $this->config = $config ?? new ReportsConfig();
        $this->db     = \Config\Database::connect($this->config->connectionGroup);
    }

    public function inspect(): array
    {
        return [
            'tables'       => $this->getTables(),
            'columns'      => $this->getColumns(),
            'foreign_keys' => $this->getForeignKeys(),
            'samples'      => $this->getSampleRows(),
        ];
    }

    private function getTables(): array
    {
        return $this->db->query(
            "SELECT TABLE_NAME, TABLE_ROWS
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = 'BASE TABLE'"
        )->getResultArray();
    }

    private function getColumns(): array
    {
        return $this->db->query(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_NAME, ORDINAL_POSITION"
        )->getResultArray();
    }

    private function getForeignKeys(): array
    {
        return $this->db->query(
            "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND REFERENCED_TABLE_NAME IS NOT NULL"
        )->getResultArray();
    }

    private function getSampleRows(): array
    {
        if ($this->config->sampleRowLimit <= 0) {
            return [];
        }

        $limit   = (int) $this->config->sampleRowLimit;
        $samples = [];

        foreach ($this->config->tables as $table) {
            // Defensive: config is trusted but the table name is interpolated into SQL.
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
                continue;
            }
            $rows = $this->db->query("SELECT * FROM `{$table}` LIMIT {$limit}")->getResultArray();
            $samples[$table] = $rows;
        }

        return $samples;
    }
}
