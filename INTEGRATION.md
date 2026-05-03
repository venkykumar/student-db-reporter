# Add AI Reports to Your CodeIgniter App

You'll add 3 pages — **/analysis**, **/reports**, **/reports/{id}** — that ask Claude to look at your MySQL schema and generate 6–10 reports as charts and tables.

**What it costs you:** 5 steps, ~15 minutes, 11 files copied, 1 line of config to edit.

**What you need before starting:** a working CI4 4.x app on MySQL, a writable `writable/` folder, and an Anthropic API key from https://console.anthropic.com.

**About the database:** the reporter reuses your existing CI4 database connection — whatever you've configured in `app/Config/Database.php` as the `default` group. **You don't set up a new connection.** It just needs `SELECT` access to the tables you'll list in Step 5 plus `INFORMATION_SCHEMA`. If you want reports to hit a read replica or a separate analytics DB instead, that's a one-line change covered in [Going further](#going-further).

---

## Step 1 — Install one Composer package

In your project root:

```bash
composer require guzzlehttp/guzzle:^7.0
```

(If you also want PDF export, add `composer require dompdf/dompdf:^2.0`. Skip otherwise — the rest of this guide doesn't need it.)

---

## Step 2 — Copy 11 files into your app

Clone this repo into a scratch folder, then copy the files in:

```bash
git clone https://github.com/your-org/student-db-reporter /tmp/donor
cd /path/to/your/app

cp     /tmp/donor/app/Config/Reports.php             app/Config/
cp     /tmp/donor/app/Services/ClaudeService.php          app/Services/
cp     /tmp/donor/app/Services/SchemaInspectorService.php app/Services/
cp     /tmp/donor/app/Services/ReportExecutorService.php  app/Services/
cp     /tmp/donor/app/Models/ReportConfigModel.php   app/Models/
cp     /tmp/donor/app/Controllers/Analysis.php       app/Controllers/
cp     /tmp/donor/app/Controllers/Reports.php        app/Controllers/
cp -r  /tmp/donor/app/Views/analysis                 app/Views/
cp -r  /tmp/donor/app/Views/reports                  app/Views/
```

That's 9 PHP files and 2 view directories.

The view files use Bootstrap 5 + Chart.js loaded from a CDN by `app/Views/layout/main.php`. If **you don't have your own base layout**, also copy ours:

```bash
cp -r /tmp/donor/app/Views/layout app/Views/
```

If **you do have a layout**, open `app/Views/analysis/index.php`, `app/Views/reports/index.php`, and `app/Views/reports/view.php` and change the first line `<?= $this->extend('layout/main') ?>` to your layout name. Make sure your layout loads Chart.js (or wrap the chart `<script>` in `$this->section('scripts')`).

Then refresh Composer's autoloader:

```bash
composer dump-autoload
```

---

## Step 3 — Add 6 routes

Open `app/Config/Routes.php` and paste in:

```php
$routes->get ('/analysis',               'Analysis::index');
$routes->post('/analysis/run',           'Analysis::run');
$routes->post('/analysis/regenerate',    'Analysis::regenerate');
$routes->get ('/reports',                'Reports::index');
$routes->get ('/reports/(:segment)',     'Reports::view/$1');
$routes->get ('/reports/pdf/(:segment)', 'Reports::exportPdf/$1');
```

If `/reports` or `/analysis` clash with your existing routes, prefix them — `/ai-reports`, `/ai-analysis`. Don't forget to update the redirect in `Analysis.php` (it currently sends users to `/dashboard` after a successful run; change that line if you don't have a `/dashboard`).

---

## Step 4 — Add your API key to `.env`

```ini
CLAUDE_API_KEY = sk-ant-api03-xxxxxxxxxxxx
```

Optional: set `CLAUDE_MODEL = claude-sonnet-4-6` to pin the model (this is the default).

---

## Step 5 — Tell the reporter which tables to read

Open `app/Config/Reports.php`. **The only line you need to change is the `$tables` array.** Replace the demo's table names with yours:

```php
public array $tables = [
    'pupils',         // ← your table names
    'courses',
    'assessments',
];
```

Also set `$drilldown = null` (line near the bottom of the file) — drilldown is an optional feature you can come back to later (see "Going further" below).

That's it. Save the file.

---

## Try it

1. Open **`/analysis`** in your browser. You should see a "Run Analysis" button.
2. Click it. After 5–15 seconds you'll see a green flash: *"Analysis complete! N reports generated."*
3. Open **`/reports`**. You'll see a card grid of the reports Claude generated against your schema.
4. Click any card. A chart and a data table appear.

If you want to regenerate (Claude tries again from scratch), use the **Regenerate** button on `/analysis`.

---

## Going further

These are independent — pick what you need.

- **Per-entity drilldown** (pick a student/teacher/cohort, see only their data). Set `$drilldown` in `Config/Reports.php` and add a `searchByName()` method to your entity model. See [Drilldown details](#drilldown-details) below.
- **PDF export.** `composer require dompdf/dompdf:^2.0`, then also copy `app/Services/PdfExportService.php` and `app/Views/reports/pdf.php` from the donor.
- **Read replica or separate analytics DB.** Define a second connection group in `app/Config/Database.php`, then set `$connectionGroup = 'your_group_name'` in `Config/Reports.php`.
- **Hide PII from Claude.** Set `$sampleRowLimit = 0` in `Config/Reports.php` (the schema alone is often enough), or redact in `SchemaInspectorService::getSampleRows()` before the rows leave the box.

---

## Going to production

Before pushing this to a real environment:

- [ ] Put `/analysis*` behind your auth filter — anyone hitting it burns API credit.
- [ ] Use a **read-only DB user** for the tables in `$tables`. The SQL validator rejects DML keywords, but defence in depth is cheap.
- [ ] Restrict `/reports` to roles cleared to see all rows in `$tables`.
- [ ] Rotate `CLAUDE_API_KEY` on a schedule. Never commit it; never log it.
- [ ] If you autoscale, mount a shared volume on `writable/` (or pass an absolute path to `new ReportConfigModel(...)`).

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Class App\Services\ClaudeService not found` | Run `composer dump-autoload`. If you renamed namespaces, update `namespace` and `use` lines in every copied file. |
| `/analysis/run` returns 403 | CSRF token missing. The donor view already calls `csrf_field()` — if you customised it, put it back inside `<form>`. |
| Flash error: "Claude returned no valid report configurations" | Check `writable/logs/log-*.php`. Usually Claude referenced a column or table you didn't include in `$tables`. |
| Chart renders empty | The cached `x_axis`/`y_axis` doesn't match a SELECT alias. Click **Regenerate** on `/analysis`, or hand-edit `writable/report_configs.json`. |
| `writable/report_configs.json` permission denied | The PHP user needs write access (same as logs/sessions). `chmod -R u+w writable/`. |
| MySQL error "Base table or view not found: 'information_schema.columns'" | Your DB user lacks `INFORMATION_SCHEMA` access. `GRANT SELECT ON information_schema.* TO 'your_user'@'%';` |

---

## Drilldown details

A drilldown lets one report be filtered by an entity ID (for example: pick a student → see only their grades).

**1.** Add a `searchByName()` method to your entity model. Reference shape:

```php
public function searchByName(string $q, int $limit = 20): array
{
    return $q === '' ? [] : $this
        ->select('id, first_name, last_name, email')
        ->groupStart()
            ->like('first_name', $q, 'after')
            ->orLike('last_name', $q, 'after')
        ->groupEnd()
        ->limit($limit)->find();
}
```

**2.** Copy `app/Controllers/Students.php` from the donor. If your entity isn't "student", rename the file (`Teachers.php`) — the controller is config-driven and contains no schema-specific code.

**3.** Add the typeahead route:

```php
$routes->get('/students/search', 'Students::search');     // or /teachers/search, etc
```

**4.** Fill the `$drilldown` field in `Config/Reports.php`:

```php
public array $allowedPlaceholders = ['student_id' => 'int'];

public ?array $drilldown = [
    'placeholder'    => 'student_id',          // becomes :student_id in SQL
    'entity_label'   => 'Student',             // shown in the picker
    'category'       => 'student_drilldown',   // also add this to $categories
    'model'          => \App\Models\StudentModel::class,
    'search_columns' => ['first_name', 'last_name', 'email'],
    'search_route'   => '/students/search',    // matches the route above
    'required_report' => [
        'id'                => 'student_subject_performance',
        'title'             => 'Subject Performance for Student',
        'shape_description' => "Show a single student's score across every subject",
        'sql_example'       => "SELECT s.name AS subject_name, g.score AS score FROM grades g JOIN subjects s ON g.subject_id = s.id WHERE g.student_id = :student_id ORDER BY s.name",
    ],
];
```

**5.** Click **Regenerate** on `/analysis` so Claude rebuilds with the new prompt.

You'll now see the drilldown report in `/reports`, with a typeahead picker at the top of its detail page.