<?= $this->extend('layout/main') ?>
<?= $this->section('content') ?>

<div class="row justify-content-center">
    <div class="col-lg-8">

        <?php if (!$hasReports): ?>
        <!-- Initial state: no reports yet -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-5 text-center">
                <div style="font-size:4rem; color:#818cf8"><i class="bi bi-cpu"></i></div>
                <h4 class="mt-3 mb-2">AI Schema Analysis</h4>
                <p class="text-muted mb-4">
                    Claude will inspect your student database schema, understand the relationships between
                    tables, and automatically generate a set of meaningful reports tailored to your data.
                </p>
                <form action="<?= base_url('analysis/run') ?>" method="post">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary btn-lg px-5">
                        <i class="bi bi-lightning-charge-fill"></i> Run Analysis
                    </button>
                </form>
                <div class="mt-3 text-muted small">
                    This will call the Claude API once and cache the results permanently until you regenerate.
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Reports exist -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div>
                        <h5 class="mb-1 fw-semibold">Analysis Complete</h5>
                        <p class="text-muted mb-0"><?= count($reports) ?> report configurations are currently active.</p>
                    </div>
                    <i class="bi bi-check-circle-fill text-success" style="font-size:2rem"></i>
                </div>
                <form action="<?= base_url('analysis/regenerate') ?>" method="post" class="d-inline"
                      onsubmit="return confirm('This will delete all current report configs and re-run the Claude API analysis. Continue?')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-warning">
                        <i class="bi bi-arrow-clockwise"></i> Regenerate Analysis
                    </button>
                </form>
                <a href="<?= base_url('reports') ?>" class="btn btn-primary ms-2">
                    <i class="bi bi-file-earmark-bar-graph"></i> View All Reports
                </a>
            </div>
        </div>

        <!-- List of current report configs -->
        <h6 class="fw-semibold mb-3">Current Report Configurations</h6>
        <div class="row g-3">
            <?php foreach ($reports as $r): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="fw-semibold"><?= esc($r['title']) ?></span>
                            <?php
                                $catColors = [
                                    'academic_performance'    => 'primary',
                                    'completion_tracking'     => 'success',
                                    'enrollment_demographics' => 'info',
                                    'subject_analysis'        => 'warning',
                                ];
                                $badge = $catColors[$r['category']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $badge ?> badge-category"><?= esc(str_replace('_', ' ', $r['category'])) ?></span>
                        </div>
                        <p class="text-muted small mb-2"><?= esc($r['description']) ?></p>
                        <div class="d-flex align-items-center gap-2 text-muted small">
                            <i class="bi bi-bar-chart"></i> <?= esc($r['chart_type']) ?>
                            &nbsp;|&nbsp;
                            <i class="bi bi-code-slash"></i>
                            <code class="small"><?= esc(substr($r['sql_query'], 0, 60)) ?>…</code>
                        </div>
                    </div>
                    <div class="card-footer bg-white border-0 pt-0">
                        <a href="<?= base_url('reports/' . esc($r['report_id'])) ?>" class="btn btn-sm btn-outline-primary">
                            View Report <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<?= $this->endSection() ?>
