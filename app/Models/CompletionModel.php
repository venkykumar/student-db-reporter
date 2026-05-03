<?php

namespace App\Models;

use CodeIgniter\Model;

class CompletionModel extends Model
{
    protected $table         = 'student_subject_completion';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'student_id', 'subject_id', 'status', 'lessons_completed',
        'time_taken_hours', 'started_at', 'completed_at',
    ];

    public function completionRate(): float
    {
        $result = $this->db->query(
            'SELECT ROUND(SUM(status = \'completed\') / COUNT(*) * 100, 1) AS rate
             FROM student_subject_completion'
        )->getRow();

        return (float) ($result->rate ?? 0);
    }

    public function statusDistribution(): array
    {
        return $this->db->query(
            'SELECT status, COUNT(*) AS total
             FROM student_subject_completion
             GROUP BY status'
        )->getResultArray();
    }
}
