<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($pageTitle ?? 'Student DB Reporter') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar {
            width: 260px;
            min-height: 100vh;
            background: #1e293b;
            color: #cbd5e1;
            flex-shrink: 0;
        }
        .sidebar .brand {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #334155;
            font-size: 1.1rem;
            font-weight: 600;
            color: #f1f5f9;
        }
        .sidebar .nav-section {
            padding: 0.75rem 1.5rem 0.25rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #64748b;
        }
        .sidebar .nav-link {
            color: #94a3b8;
            padding: .45rem 1.5rem;
            font-size: 0.875rem;
            border-radius: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: #f1f5f9;
            background: #334155;
        }
        .sidebar .nav-link .bi { margin-right: .4rem; }
        .main-content { flex: 1; overflow-x: hidden; }
        .topbar {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: .75rem 1.5rem;
        }
        .kpi-card { border: none; border-radius: .75rem; }
        .kpi-card .card-body { padding: 1.25rem 1.5rem; }
        .kpi-icon {
            width: 48px; height: 48px;
            border-radius: .5rem;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem;
        }
        .badge-category {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
    </style>
</head>
<body>
<div class="d-flex">
    <!-- Sidebar -->
    <nav class="sidebar d-flex flex-column">
        <div class="brand">
            <i class="bi bi-bar-chart-fill text-indigo" style="color:#818cf8"></i>
            Student Reporter
        </div>

        <div class="nav-section mt-2">Navigation</div>
        <a href="<?= base_url('/') ?>" class="nav-link <?= (uri_string() === '' || uri_string() === 'dashboard') ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="<?= base_url('reports') ?>" class="nav-link <?= str_starts_with(uri_string(), 'reports') ? 'active' : '' ?>">
            <i class="bi bi-file-earmark-bar-graph"></i> All Reports
        </a>
        <a href="<?= base_url('analysis') ?>" class="nav-link <?= str_starts_with(uri_string(), 'analysis') ? 'active' : '' ?>">
            <i class="bi bi-cpu"></i> AI Analysis
        </a>

        <?php if (!empty($reports)): ?>
        <div class="nav-section mt-3">Generated Reports</div>
        <?php foreach ($reports as $r): ?>
        <a href="<?= base_url('reports/' . esc($r['report_id'])) ?>"
           class="nav-link <?= (uri_string() === 'reports/' . $r['report_id']) ? 'active' : '' ?>">
            <i class="bi bi-graph-up"></i> <?= esc($r['title']) ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </nav>

    <!-- Main content -->
    <div class="main-content">
        <!-- Top bar -->
        <div class="topbar d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-semibold text-secondary"><?= esc($pageTitle ?? 'Dashboard') ?></h6>
            <div>
                <a href="<?= base_url('analysis') ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-cpu"></i> AI Analysis
                </a>
            </div>
        </div>

        <!-- Flash messages -->
        <div class="px-4 pt-3">
        <?php if (session()->has('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= esc(session('success')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (session()->has('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= esc(session('error')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (session()->has('info')): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <i class="bi bi-info-circle"></i> <?= esc(session('info')) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        </div>

        <!-- Page body -->
        <div class="p-4">
            <?= $this->renderSection('content') ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
