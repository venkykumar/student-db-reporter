<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<?php
    $catColors = [
        'academic_performance'    => 'primary',
        'completion_tracking'     => 'success',
        'enrollment_demographics' => 'info',
        'subject_analysis'        => 'warning',
        'student_drilldown'       => 'dark',
    ];
    $badge = $catColors[$config['category']] ?? 'secondary';
    $needsStudentId = $needsStudentId ?? false;
    $studentId      = $studentId ?? 0;
    $student        = $student ?? null;
    $pdfUrl = base_url('reports/pdf/' . esc($config['report_id'])) . ($needsStudentId && $studentId ? '?student_id=' . $studentId : '');
?>

<!-- Report header -->
<div class="d-flex align-items-start justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-<?= $badge ?> badge-category">
                <?= esc(str_replace('_', ' ', $config['category'])) ?>
            </span>
            <span class="text-muted small"><?= esc($config['chart_type']) ?> chart</span>
        </div>
        <h4 class="fw-bold mb-1"><?= esc($config['title']) ?></h4>
        <p class="text-muted mb-0"><?= esc($config['description']) ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (!$needsStudentId || $student !== null): ?>
        <a href="<?= esc($pdfUrl) ?>" class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-pdf"></i> Export PDF
        </a>
        <?php endif; ?>
        <a href="<?= base_url('reports') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> All Reports
        </a>
    </div>
</div>

<?php if ($needsStudentId): ?>
<!-- Student picker -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <label for="studentSearch" class="form-label small fw-semibold text-muted text-uppercase mb-2">
            <i class="bi bi-person-fill"></i> Select Student
        </label>
        <div class="position-relative" style="max-width:480px">
            <input type="text"
                   id="studentSearch"
                   class="form-control"
                   placeholder="Type a name (first or last)…"
                   autocomplete="off"
                   value="<?= $student ? esc($student['first_name'] . ' ' . $student['last_name']) : '' ?>">
            <div id="studentResults" class="dropdown-menu w-100 shadow-sm" style="max-height:280px; overflow-y:auto"></div>
        </div>
        <?php if ($student): ?>
        <div class="mt-2 small text-muted">
            Showing data for <strong><?= esc($student['first_name'] . ' ' . $student['last_name']) ?></strong>
            (<?= esc($student['email']) ?>) — student #<?= esc($student['id']) ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($needsStudentId && $student === null): ?>
<!-- Empty state: no student selected -->
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-person-bounding-box" style="font-size:2.5rem; color:#cbd5e1"></i>
        <h6 class="mt-3 text-muted">Search and select a student above to view this report.</h6>
    </div>
</div>

<?php elseif (empty($rows)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox" style="font-size:2.5rem; color:#cbd5e1"></i>
        <h6 class="mt-3 text-muted">No data available for this report.</h6>
    </div>
</div>

<?php else: ?>
<!-- Chart -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <canvas id="reportChart" style="max-height:350px"></canvas>
    </div>
</div>

<!-- Data Table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between pt-3 pb-0">
        <h6 class="fw-semibold mb-0">Data Table</h6>
        <span class="text-muted small"><?= count($rows) ?> row<?= count($rows) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <?php foreach (array_keys($rows[0]) as $col): ?>
                        <th class="px-3 py-2 small fw-semibold"><?= esc(ucwords(str_replace('_', ' ', $col))) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($row as $val): ?>
                        <td class="px-3 py-2 small"><?= esc($val ?? '—') ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>

<?php if ($needsStudentId): ?>
<script>
(function() {
    const input  = document.getElementById('studentSearch');
    const drop   = document.getElementById('studentResults');
    const reportId = <?= json_encode($config['report_id']) ?>;
    let timer = null;

    function close() { drop.classList.remove('show'); drop.innerHTML = ''; }
    function open()  { drop.classList.add('show'); }

    input.addEventListener('input', function() {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 1) { close(); return; }

        timer = setTimeout(async function() {
            try {
                const res = await fetch('<?= base_url('students/search') ?>?q=' + encodeURIComponent(q));
                const data = await res.json();
                if (!Array.isArray(data) || data.length === 0) {
                    drop.innerHTML = '<div class="dropdown-item-text text-muted small">No matches</div>';
                    open();
                    return;
                }
                drop.innerHTML = data.map(s =>
                    `<a class="dropdown-item" href="<?= base_url('reports/' . $config['report_id']) ?>?student_id=${s.id}">
                        <span class="fw-semibold">${escapeHtml(s.name)}</span>
                        <span class="text-muted small ms-1">${escapeHtml(s.email)}</span>
                    </a>`
                ).join('');
                open();
            } catch (e) {
                drop.innerHTML = '<div class="dropdown-item-text text-danger small">Search failed</div>';
                open();
            }
        }, 200);
    });

    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !drop.contains(e.target)) close();
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
})();
</script>
<?php endif; ?>

<?php if (!empty($rows)): ?>
<script>
const chartType   = '<?= esc($config['chart_type']) ?>';
const chartLabels = <?= json_encode(array_values($chartLabels)) ?>;
const chartValues = <?= json_encode(array_map('floatval', array_values($chartValues))) ?>;
const chartTitle  = '<?= esc(addslashes($config['title'])) ?>';

const palette = [
    'rgba(99,102,241,.8)','rgba(52,211,153,.8)','rgba(251,191,36,.8)',
    'rgba(248,113,113,.8)','rgba(96,165,250,.8)','rgba(167,139,250,.8)',
    'rgba(45,212,191,.8)','rgba(249,168,212,.8)',
];

const isCircular = ['pie','doughnut'].includes(chartType);

new Chart(document.getElementById('reportChart'), {
    type: chartType,
    data: {
        labels: chartLabels,
        datasets: [{
            label: chartTitle,
            data: chartValues,
            backgroundColor: isCircular ? palette : palette[0],
            borderRadius: isCircular ? 0 : 4,
            borderWidth: isCircular ? 2 : 0,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: isCircular, position: 'bottom' },
            tooltip: { mode: 'index' }
        },
        scales: isCircular ? {} : {
            y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false }, ticks: { font: { size: 11 } } }
        }
    }
});
</script>
<?php endif; ?>
<?= $this->endSection() ?>
