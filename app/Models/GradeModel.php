<?php

namespace App\Models;

use CodeIgniter\Model;

class GradeModel extends Model
{
    protected $table         = 'grades';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['student_id', 'subject_id', 'score', 'max_score', 'grade_date'];

    public function avgGradePercent(): float
    {
        $result = $this->db->query(
            'SELECT ROUND(AVG((score / max_score) * 100), 1) AS avg_pct FROM grades'
        )->getRow();

        return (float) ($result->avg_pct ?? 0);
    }

    public function avgScoreBySubject(): array
    {
        return $this->db->query(
            'SELECT s.name AS subject_name, ROUND(AVG(g.score), 1) AS avg_score
             FROM grades g
             JOIN subjects s ON g.subject_id = s.id
             GROUP BY s.id, s.name
             ORDER BY s.name'
        )->getResultArray();
    }
}
