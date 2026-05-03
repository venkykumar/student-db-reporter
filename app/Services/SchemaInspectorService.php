<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

class SchemaInspectorService
{
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
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
        $tables   = ['students', 'subjects', 'grades', 'student_subject_completion'];
        $samples  = [];

        foreach ($tables as $table) {
            $rows = $this->db->query("SELECT * FROM `{$table}` LIMIT 3")->getResultArray();
            $samples[$table] = $rows;
        }

        return $samples;
    }
}
