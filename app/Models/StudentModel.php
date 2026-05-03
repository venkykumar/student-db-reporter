<?php

namespace App\Models;

use CodeIgniter\Model;

class StudentModel extends Model
{
    protected $table         = 'students';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['first_name', 'last_name', 'email', 'date_of_birth', 'enrollment_date', 'gender'];

    public function countTotal(): int
    {
        return (int) $this->countAll();
    }

    public function searchByName(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return $this->select('id, first_name, last_name, email')
            ->groupStart()
                ->like('first_name', $q, 'after')
                ->orLike('last_name', $q, 'after')
            ->groupEnd()
            ->orderBy('last_name', 'ASC')
            ->orderBy('first_name', 'ASC')
            ->limit($limit)
            ->find();
    }
}
