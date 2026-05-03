<?php

namespace App\Controllers;

use App\Models\ReportConfigModel;
use App\Services\ReportExecutorService;
use App\Services\PdfExportService;
use Config\Reports as ReportsConfig;

class Reports extends BaseController
{
    public function index(): string
    {
        $reportModel = new ReportConfigModel();
        $reports     = $reportModel->findAll();

        return view('reports/index', [
            'reports'   => $reports,
            'pageTitle' => 'All Reports',
        ]);
    }

    public function view(string $reportId): string
    {
        $reportModel = new ReportConfigModel();
        $config      = $reportModel->findByReportId($reportId);

        if ($config === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(
                "Report '{$reportId}' not found."
            );
        }

        $params   = ReportConfigModel::decodeParameters($config['parameters'] ?? null);
        $resolved = $this->resolveDrilldown($params);

        $rows        = [];
        $chartLabels = [];
        $chartValues = [];

        if (!$resolved['needsParam'] || $resolved['entity'] !== null) {
            $executor = new ReportExecutorService();
            $rows     = $executor->execute(
                $config['sql_query'],
                $resolved['needsParam'] ? [$resolved['placeholder'] => $resolved['entityId']] : []
            );
            $chartLabels = array_column($rows, $config['x_axis']);
            $chartValues = array_column($rows, $config['y_axis']);
        }

        return view('reports/view', [
            'config'      => $config,
            'rows'        => $rows,
            'chartLabels' => $chartLabels,
            'chartValues' => $chartValues,
            'reports'     => $reportModel->findAll(),
            'pageTitle'   => $config['title'],
            'needsParam'  => $resolved['needsParam'],
            'entityId'    => $resolved['entityId'],
            'entity'      => $resolved['entity'],
            'entityNoun'  => $resolved['entityNoun'],
            'entityLabel' => $resolved['entityLabel'],
            'entityDetail'=> $resolved['entityDetail'],
            'placeholder' => $resolved['placeholder'],
            'searchRoute' => $resolved['searchRoute'],
        ]);
    }

    public function exportPdf(string $reportId): void
    {
        $reportModel = new ReportConfigModel();
        $config      = $reportModel->findByReportId($reportId);

        if ($config === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(
                "Report '{$reportId}' not found."
            );
        }

        $params   = ReportConfigModel::decodeParameters($config['parameters'] ?? null);
        $resolved = $this->resolveDrilldown($params);

        if ($resolved['needsParam']) {
            if ($resolved['entityId'] <= 0) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(
                    "This report requires a {$resolved['placeholder']} query parameter."
                );
            }
            if ($resolved['entity'] === null) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(
                    "{$resolved['entityNoun']} id {$resolved['entityId']} not found."
                );
            }
        }

        $executor = new ReportExecutorService();
        $rows     = $executor->execute(
            $config['sql_query'],
            $resolved['needsParam'] ? [$resolved['placeholder'] => $resolved['entityId']] : []
        );

        $html = view('reports/pdf', [
            'config'       => $config,
            'rows'         => $rows,
            'entity'       => $resolved['entity'],
            'entityNoun'   => $resolved['entityNoun'],
            'entityLabel'  => $resolved['entityLabel'],
            'entityDetail' => $resolved['entityDetail'],
            'generated'    => date('Y-m-d H:i:s'),
        ]);

        $filename = $config['report_id'];
        if ($resolved['entity'] !== null) {
            $filename .= '_' . strtolower((string) $resolved['entityNoun']) . '_' . $resolved['entityId'];
        }
        $filename .= '.pdf';

        $pdfService = new PdfExportService();
        $pdfService->stream($html, $filename);
    }

    /**
     * Resolve the drilldown context for the current request.
     *
     * @param list<string> $reportParams Parameters declared by the cached report.
     *
     * @return array{
     *   needsParam: bool,
     *   placeholder: ?string,
     *   entityId: int,
     *   entity: ?array,
     *   entityNoun: ?string,
     *   entityLabel: ?string,
     *   entityDetail: ?string,
     *   searchRoute: ?string
     * }
     */
    private function resolveDrilldown(array $reportParams): array
    {
        $reportsCfg = new ReportsConfig();
        $base = [
            'needsParam'   => false,
            'placeholder'  => null,
            'entityId'     => 0,
            'entity'       => null,
            'entityNoun'   => null,
            'entityLabel'  => null,
            'entityDetail' => null,
            'searchRoute'  => null,
        ];

        if ($reportsCfg->drilldown === null) {
            return $base;
        }

        $d                  = $reportsCfg->drilldown;
        $base['placeholder'] = $d['placeholder'];
        $base['entityNoun']  = $d['entity_label'];
        $base['searchRoute'] = $d['search_route'];
        $base['needsParam']  = in_array($d['placeholder'], $reportParams, true);

        if (!$base['needsParam']) {
            return $base;
        }

        $entityId = (int) ($this->request->getGet($d['placeholder']) ?? 0);
        $base['entityId'] = $entityId;

        if ($entityId <= 0) {
            return $base;
        }

        $modelClass = $d['model'];
        $entity     = (new $modelClass())->find($entityId);
        if ($entity === null) {
            return $base;
        }

        $base['entity'] = $entity;
        [$label, $detail] = $this->buildEntityLabel($entity, $d['search_columns']);
        $base['entityLabel']  = $label;
        $base['entityDetail'] = $detail;

        return $base;
    }

    /**
     * Build a display label and optional detail string from the entity row,
     * using the columns the typeahead searches over.
     *
     * - First two non-numeric string columns become the label ("Alice Smith").
     * - Remaining string columns join into a detail ("alice@example.com").
     *
     * @param array<string,mixed> $entity
     * @param list<string>        $columns
     *
     * @return array{0: string, 1: string}
     */
    private function buildEntityLabel(array $entity, array $columns): array
    {
        $main   = [];
        $detail = [];
        foreach ($columns as $col) {
            if (!isset($entity[$col]) || $entity[$col] === '' || is_numeric($entity[$col])) {
                continue;
            }
            $value = (string) $entity[$col];
            if (count($main) < 2) {
                $main[] = $value;
            } else {
                $detail[] = $value;
            }
        }
        return [trim(implode(' ', $main)), trim(implode(', ', $detail))];
    }
}