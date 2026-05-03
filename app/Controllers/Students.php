<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Reports as ReportsConfig;

class Students extends BaseController
{
    public function search(): ResponseInterface
    {
        $cfg = new ReportsConfig();
        if ($cfg->drilldown === null) {
            return $this->response->setJSON([]);
        }

        $d          = $cfg->drilldown;
        $modelClass = $d['model'];
        $columns    = $d['search_columns'];

        $q    = (string) $this->request->getGet('q');
        $rows = (new $modelClass())->searchByName($q, 20);

        $results = array_map(static function (array $r) use ($columns): array {
            $main = [];
            $rest = [];
            foreach ($columns as $col) {
                if (!isset($r[$col]) || $r[$col] === '' || is_numeric($r[$col])) {
                    continue;
                }
                $val = (string) $r[$col];
                if (count($main) < 2) {
                    $main[] = $val;
                } else {
                    $rest[] = $val;
                }
            }

            $out = [
                'id'     => (int) ($r['id'] ?? 0),
                'name'   => trim(implode(' ', $main)),
                'detail' => trim(implode(', ', $rest)),
            ];

            // Pass through raw search-column values for callers that want
            // schema-specific access (back-compat with the demo's email field).
            foreach ($columns as $col) {
                if (isset($r[$col])) {
                    $out[$col] = $r[$col];
                }
            }
            return $out;
        }, $rows);

        return $this->response->setJSON($results);
    }
}