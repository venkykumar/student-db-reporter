<?php

namespace App\Controllers;

use App\Models\ReportConfigModel;
use App\Models\StudentModel;
use App\Services\ReportExecutorService;
use App\Services\PdfExportService;

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

        $params         = ReportConfigModel::decodeParameters($config['parameters'] ?? null);
        $needsStudentId = in_array('student_id', $params, true);
        $studentId      = $needsStudentId ? (int) ($this->request->getGet('student_id') ?? 0) : 0;
        $student        = null;

        if ($needsStudentId && $studentId > 0) {
            $student = (new StudentModel())->find($studentId);
        }

        $rows        = [];
        $chartLabels = [];
        $chartValues = [];

        if (!$needsStudentId || $student !== null) {
            $executor    = new ReportExecutorService();
            $rows        = $executor->execute(
                $config['sql_query'],
                $needsStudentId ? ['student_id' => $studentId] : []
            );
            $chartLabels = array_column($rows, $config['x_axis']);
            $chartValues = array_column($rows, $config['y_axis']);
        }

        return view('reports/view', [
            'config'         => $config,
            'rows'           => $rows,
            'chartLabels'    => $chartLabels,
            'chartValues'    => $chartValues,
            'reports'        => $reportModel->findAll(),
            'pageTitle'      => $config['title'],
            'needsStudentId' => $needsStudentId,
            'studentId'      => $studentId,
            'student'        => $student,
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

        $params         = ReportConfigModel::decodeParameters($config['parameters'] ?? null);
        $needsStudentId = in_array('student_id', $params, true);
        $studentId      = $needsStudentId ? (int) ($this->request->getGet('student_id') ?? 0) : 0;
        $student        = null;

        if ($needsStudentId) {
            if ($studentId <= 0) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(
                    'This report requires a student_id query parameter.'
                );
            }
            $student = (new StudentModel())->find($studentId);
            if ($student === null) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound(
                    "Student id {$studentId} not found."
                );
            }
        }

        $executor = new ReportExecutorService();
        $rows     = $executor->execute(
            $config['sql_query'],
            $needsStudentId ? ['student_id' => $studentId] : []
        );

        $html = view('reports/pdf', [
            'config'    => $config,
            'rows'      => $rows,
            'student'   => $student,
            'generated' => date('Y-m-d H:i:s'),
        ]);

        $filename = $config['report_id'];
        if ($student !== null) {
            $filename .= '_student_' . $student['id'];
        }
        $filename .= '.pdf';

        $pdfService = new PdfExportService();
        $pdfService->stream($html, $filename);
    }
}
