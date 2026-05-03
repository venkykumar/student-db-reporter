<?php

namespace App\Models;

use CodeIgniter\Model;

class SubjectModel extends Model
{
    protected $table         = 'subjects';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'code', 'total_lessons', 'complexity'];

    public function countTotal(): int
    {
        return (int) $this->countAll();
    }
}
