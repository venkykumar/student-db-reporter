<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="kpi-icon" style="background:#ede9fe">
                    <i class="bi bi-people-fill" style="color:#7c3aed"></i>
                </div>
                <div>
                    <div class="fs-2 fw-bold"><?= esc($kpis['total_students']) ?></div>
                    <div class="text-muted small">Total Students</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="kpi-icon" style="background:#dbeafe">
                    <i class="bi bi-book-fill" style="color:#2563eb"></i>
                </div>
                <div>
                    <div class="fs-2 fw-bold"><?= esc($kpis['total_subjects']) ?></div>
                    <div class="text-muted small">Subjects</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="kpi-icon" style="background:#dcfce7">
                    <i class="bi bi-trophy-fill" style="color:#16a34a"></i>
                </div>
                <div>
                    <div class="fs-2 fw-bold"><?= esc($kpis['avg_grade_pct']) ?>%</div>
                    <div class="text-muted small">Avg Grade</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card kpi-card shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="kpi-icon" style="background:#fef9c3">
                    <i class="bi bi-check2-circle" style="color:#ca8a04"></i>
                </div>
                <div>
                    <div class="fs-2 fw-bold"><?= esc($kpis['completion_rate']) ?>%</div>
                    <div class="text-muted small">Completion Rate</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$hasReports): ?>
<!-- Run Analysis CTA -->
<div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg,#1e293b,#334155); color:#f1f5f9">
    <div class="card-body p-4 d-flex align-items-center gap-4">
        <div style="font-size:3rem"><i class="bi bi-cpu"></i></div>
        <div>
            <h5 class="mb-1">No reports generated yet</h5>
            <p class="mb-3 text-light opacity-75">
                Let AI analyze your student database schema and automatically create tailored reports for you.
            </p>
            <form action="<?= base_url('analysis/run') ?>" method="post">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-light fw-semibold">
                    <i class="bi bi-lightning-charge-fill text-warning"></i> Run AI Analysis
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Charts row -->
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3 pb-0">
                <h6 class="fw-semibold mb-0">Average Score by Subject</h6>
            </div>
            <div class="card-body">
                <canvas id="subjectChart" height="220"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3 pb-0">
                <h6 class="fw-semibold mb-0">Completion Status</h6>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="completionChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<?php if ($hasReports): ?>
<!-- Report quick links -->
<div class="mt-4">
    <h6 class="fw-semibold mb-3">Generated Reports</h6>
    <div class="row g-2">
        <?php foreach ($reports as $r): ?>
        <div class="col-md-4">
            <a href="<?= base_url('reports/' . esc($r['report_id'])) ?>" class="card border-0 shadow-sm text-decoration-none h-100">
                <div class="card-body py-3">
                    <div class="small fw-semibold text-dark"><?= esc($r['title']) ?></div>
                    <div class="text-muted" style="font-size:.8rem"><?= esc(substr($r['description'] ?? '', 0, 80)) ?>…</div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const subjectLabels = <?= json_encode(array_column($avgBySubject, 'subject_name')) ?>;
const subjectData   = <?= json_encode(array_map('floatval', array_column($avgBySubject, 'avg_score'))) ?>;

new Chart(document.getElementById('subjectChart'), {
    type: 'bar',
    data: {
        labels: subjectLabels,
        datasets: [{
            label: 'Avg Score',
            data: subjectData,
            backgroundColor: 'rgba(99,102,241,.75)',
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, max: 100, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
    }
});

const statusRaw    = <?= json_encode($statusDistribution) ?>;
const statusLabels = statusRaw.map(s => s.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()));
const statusData   = statusRaw.map(s => parseInt(s.total));

new Chart(document.getElementById('completionChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusData,
            backgroundColor: ['#e2e8f0','#fbbf24','#34d399'],
            borderWidth: 2,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 12 } } }
        },
        cutout: '65%'
    }
});
</script>
<?= $this->endSection() ?>
