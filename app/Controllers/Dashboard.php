<?php

namespace App\Controllers;

use App\Models\StudentModel;
use App\Models\SubjectModel;
use App\Models\GradeModel;
use App\Models\CompletionModel;
use App\Models\ReportConfigModel;

class Dashboard extends BaseController
{
    public function index(): string
    {
        $studentModel    = new StudentModel();
        $subjectModel    = new SubjectModel();
        $gradeModel      = new GradeModel();
        $completionModel = new CompletionModel();
        $reportModel     = new ReportConfigModel();

        $kpis = [
            'total_students'   => $studentModel->countTotal(),
            'total_subjects'   => $subjectModel->countTotal(),
            'avg_grade_pct'    => $gradeModel->avgGradePercent(),
            'completion_rate'  => $completionModel->completionRate(),
        ];

        $avgBySubject        = $gradeModel->avgScoreBySubject();
        $statusDistribution  = $completionModel->statusDistribution();
        $reports             = $reportModel->findAll();
        $hasReports          = $reportModel->hasReports();

        return view('dashboard/index', [
            'kpis'               => $kpis,
            'avgBySubject'       => $avgBySubject,
            'statusDistribution' => $statusDistribution,
            'reports'            => $reports,
            'hasReports'         => $hasReports,
            'pageTitle'          => 'Dashboard',
        ]);
    }
}
