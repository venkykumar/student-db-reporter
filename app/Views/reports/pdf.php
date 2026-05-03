<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($config['title']) ?></title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1e293b; }
    .header { padding: 20px 0 14px; border-bottom: 2px solid #1e293b; margin-bottom: 16px; }
    .header h1 { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
    .header .meta { font-size: 10px; color: #64748b; }
    .description { font-size: 11px; color: #475569; margin-bottom: 16px; }
    .category-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: .05em;
        font-weight: bold;
        background: #e2e8f0;
        color: #475569;
        margin-bottom: 10px;
    }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    thead tr { background: #1e293b; color: #f8fafc; }
    thead th {
        padding: 7px 10px;
        text-align: left;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody tr:nth-child(odd)  { background: #ffffff; }
    tbody td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; font-size: 10.5px; }
    .footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; text-align: center; }
    .row-count { font-size: 10px; color: #64748b; margin-bottom: 6px; }
</style>
</head>
<body>

<div class="header">
    <div class="category-badge"><?= htmlspecialchars(str_replace('_', ' ', $config['category'])) ?></div>
    <h1><?= htmlspecialchars($config['title']) ?></h1>
    <?php if (!empty($student)): ?>
    <div class="meta">
        Student: <strong><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></strong>
        (<?= htmlspecialchars($student['email']) ?>)
        &nbsp;|&nbsp;
        Generated: <?= htmlspecialchars($generated) ?>
    </div>
    <?php else: ?>
    <div class="meta">Generated: <?= htmlspecialchars($generated) ?></div>
    <?php endif; ?>
</div>

<?php if (!empty($config['description'])): ?>
<div class="description"><?= htmlspecialchars($config['description']) ?></div>
<?php endif; ?>

<?php if (empty($rows)): ?>
<p style="color:#94a3b8; font-style:italic">No data available for this report.</p>

<?php else: ?>
<div class="row-count"><?= count($rows) ?> row<?= count($rows) !== 1 ? 's' : '' ?></div>
<table>
    <thead>
        <tr>
            <?php foreach (array_keys($rows[0]) as $col): ?>
            <th><?= htmlspecialchars(ucwords(str_replace('_', ' ', $col))) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
            <?php foreach ($row as $val): ?>
            <td><?= htmlspecialchars($val ?? '—') ?></td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div class="footer">
    Student DB Reporter &mdash; <?= htmlspecialchars($config['title']) ?>
</div>

</body>
</html>
