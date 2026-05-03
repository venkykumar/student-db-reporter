# Integration Guide

Drop this app's AI-driven report generator into an existing CodeIgniter 4 project. You add 3 services + 1 model + 2 controllers + 4 views + 1 config file. No migrations.

**End state:** `/analysis` runs Claude against your live schema; `/reports` lists 6–10 generated reports; `/reports/{id}` renders each as a Chart.js chart and a table. Optional `/students/search` typeahead for per-entity drilldown.

```
your DB ──schema + samples──▶ Claude ──JSON specs──▶ writable/report_configs.json ──▶ /reports
   ▲                                                                                    │
   └──────────────── validated SELECT only ─────────────────────────────────────────────┘
```

## Prerequisites

CI4 4.x · PHP 8.1+ · MySQL 5.7+ with `INFORMATION_SCHEMA` read access · Composer · Anthropic API key · writable `writable/`.

The reporter uses your CI4 default DB connection group. Override `Config\Reports::$connectionGroup` to point at a read replica.

---

## Phase 1 — Install (≈10 min)

**1. Composer**

```bash
composer require guzzlehttp/guzzle:^7.0
composer require dompdf/dompdf:^2.0   # PDF export, optional
```

**2. Copy these files into your app at the same paths.** The "skip" column says when you can omit a file.

| File | Skip if… |
|---|---|
| `app/Config/Reports.php` | never — Phase 2 lives here |
| `app/Services/{Claude,SchemaInspector,ReportExecutor}Service.php` | never |
| `app/Models/ReportConfigModel.php` | never |
| `app/Controllers/{Analysis,Reports}.php` | never |
| `app/Views/analysis/index.php`, `app/Views/reports/{index,view}.php` | never |
| `app/Controllers/Students.php` | no drilldown |
| `app/Services/PdfExportService.php`, `app/Views/reports/pdf.php` | no PDF export |
| `app/Models/StudentModel.php` | no drilldown — otherwise adapt to your entity (see Phase 3) |
| `app/Views/layout/main.php` | you have your own — change `extend('layout/main')` in the report views |
| `app/Controllers/BaseController.php` | always — merge into yours, keep your version |
| `app/Controllers/Dashboard.php`, demo `*Model.php`, `docker/`, `tests/` | always — demo-only |

**3. Routes** in `app/Config/Routes.php`:

```php
$routes->get('/reports',                'Reports::index');
$routes->get('/reports/pdf/(:segment)', 'Reports::exportPdf/$1');
$routes->get('/reports/(:segment)',     'Reports::view/$1');
$routes->get('/analysis',               'Analysis::index');
$routes->post('/analysis/run',          'Analysis::run');
$routes->post('/analysis/regenerate',   'Analysis::regenerate');
$routes->get('/students/search',        'Students::search');   // drilldown only
```

**4. `.env`**

```ini
CLAUDE_API_KEY = sk-ant-...
CLAUDE_MODEL   = claude-sonnet-4-6
```

`ANTHROPIC_API_KEY` and OS-level env vars also work.

**5. CSRF.** The two POST routes need a token. The donor `analysis/index.php` calls `csrf_field()`, so default CI4 CSRF works out of the box — no action needed unless you build a custom form or call from curl.

✓ **Gate:** `composer dump-autoload && php spark routes | grep analysis` shows the routes; `curl -fI http://localhost:8080/analysis` returns 200.

---

## Phase 2 — Point it at your schema (≈10 min)

Open `app/Config/Reports.php`. The file is heavily commented; the only edits a typical integration needs are:

```php
public array $tables = ['pupils', 'courses', 'assessments'];   // your tables
public string $connectionGroup = 'default';                    // or 'analytics' / 'replica'
public array $categories = [                                   // drop 'student_drilldown' if no drilldown
    'academic_performance', 'completion_tracking',
];
public array $allowedPlaceholders = [];        // empty = no parameterised reports
public ?array $drilldown = null;               // see Phase 3 to enable
```

Leave `$sampleRowLimit`, `$maxRowsPerReport`, and `$min/maxReports` at their defaults until you have a reason to change them.

✓ **Gate:** POST `/analysis/run` redirects with success flash; `jq 'length' writable/report_configs.json` returns 6–10; opening any `/reports/{id}` renders a chart.

---

## Phase 3 — Drilldown (optional)

Lets one report be filtered by an entity ID — pick a teacher, see only their classes.

**1. Add `searchByName()` to your entity model** (`find()` is built into `CodeIgniter\Model`):

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

**2. Fill `$drilldown` in `Config/Reports.php`:**

```php
public array $allowedPlaceholders = ['teacher_id' => 'int'];
public array $categories = ['academic_performance', 'teacher_drilldown'];

public ?array $drilldown = [
    'placeholder'    => 'teacher_id',
    'entity_label'   => 'Teacher',
    'category'       => 'teacher_drilldown',
    'model'          => \App\Models\TeacherModel::class,
    'search_columns' => ['first_name', 'last_name', 'department'],
    'search_route'   => '/teachers/search',
    'required_report' => [
        'id'                => 'teacher_class_average',
        'title'             => 'Class Average for Teacher',
        'shape_description' => 'Average score across every class this teacher runs',
        'sql_example'       => "SELECT c.name AS class_name, AVG(g.score) AS avg_score FROM grades g JOIN classes c ON g.class_id = c.id WHERE c.teacher_id = :teacher_id GROUP BY c.id, c.name",
    ],
];
```

**3.** Update the route to match `search_route` (`/teachers/search` → `Teachers::search`); rename `Students.php` to `Teachers.php` if the entity changed (the controller is config-driven — no other edits).

**4.** Click *Regenerate* on `/analysis` so Claude rebuilds against the new prompt.

✓ **Gate:** `curl '/teachers/search?q=Smith' | jq '.[0]'` returns `{id, name, detail, …}`.

---

## Phase 4 — Production checklist

- **Auth** in front of `/analysis*` (admin only — burns API credit and runs cached SQL).
- **Read-only DB user** for `$tables` + `INFORMATION_SCHEMA`. The validator is defence in depth, not a sandbox.
- **Read replica** via `$connectionGroup` so report scans don't hit the primary.
- **Shared cache** (`new ReportConfigModel('/shared/report_configs.json')`) if you autoscale.
- **PII in samples** — `$sampleRowLimit = 0` to disable, or redact in `SchemaInspectorService::getSampleRows()`. Each `/analysis/run` sends N rows from each `$tables` entry to Claude.
- **Queue** `/analysis/run` if the 60 s Guzzle timeout is too tight (CI4 Tasks, Beanstalkd, etc).
- **Rotate** `CLAUDE_API_KEY` on a schedule; never log or bake into images.

---

## Cookbook diffs

**Read replica:** define `public array $analytics = [...]` in `app/Config/Database.php`, then in `Config/Reports.php`:

```diff
- public string $connectionGroup = 'default';
+ public string $connectionGroup = 'analytics';
```

**Redact PII before sending to Claude:** in `SchemaInspectorService::getSampleRows()`, after the `SELECT *`, walk each row and overwrite sensitive columns. Or `$sampleRowLimit = 0` — schema alone is often enough.

**Single-DB integration with no drilldown:** the Phase 2 snippet above is the entire integration. Skip `Students.php`, skip `searchByName()`, skip `/students/search`.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Class App\… not found` | `composer dump-autoload`; if you renamed namespaces, update every `use` line |
| "Claude returned no valid report configurations" | Tail `writable/logs/log-*.php`. Usually Claude referenced a column that doesn't exist or a table outside `$tables` |
| Chart renders empty | `x_axis` / `y_axis` doesn't match a SELECT alias. `jq '.[] \| {report_id, x_axis, y_axis, sql_query}' writable/report_configs.json` to inspect; regenerate or hand-edit |
| `writable/report_configs.json` permission denied | `chmod -R u+w writable/` (same user as logs/sessions) |
| `/analysis/run` 403 | CSRF token missing — include `csrf_field()` or exempt the route |
| `/reports` clashes with an existing route | Prefix to `/ai-reports`; update redirects in `Analysis::runAnalysis` and links in views |

---

## Tests

```bash
docker compose run --rm --entrypoint php app tests/smoke.php          # no DB / no API
docker compose up -d && docker compose exec app php tests/integration.php
```

See `tests/README.md` for variants.