<?php

namespace App\Controllers;

use App\Models\StudentModel;
use CodeIgniter\HTTP\ResponseInterface;

class Students extends BaseController
{
    public function search(): ResponseInterface
    {
        $q = (string) $this->request->getGet('q');
        $rows = (new StudentModel())->searchByName($q, 20);

        $results = array_map(static function (array $r): array {
            return [
                'id'    => (int) $r['id'],
                'name'  => trim($r['first_name'] . ' ' . $r['last_name']),
                'email' => $r['email'],
            ];
        }, $rows);

        return $this->response->setJSON($results);
    }
}
