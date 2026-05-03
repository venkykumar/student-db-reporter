# Integration Guide

Add this app's **AI-driven report generator** to an existing CodeIgniter 4 project.

> **Outcome:** three new pages — `/analysis`, `/reports`, `/reports/{id}` — that ask Claude to look at your live MySQL schema, generate 6–10 reports, and render each one as a Chart.js chart and table. Optionally a `/students/search` typeahead for per-entity drilldown.

---

## Is this for me? (30 seconds)

**Fits if:** you have a CI4 4.x app on MySQL 5.7+/8.x, you can install two Composer packages, and you have an Anthropic API key.

**You'll get:** Claude reads your `INFORMATION_SCHEMA` plus a 3-row sample per table, returns 6–10 report specs, and the app caches them in a JSON file under `writable/`. No new database tables, no migrations.

**Not in scope:** auth, multi-tenancy, scheduled regeneration, write queries (the SQL validator rejects DML).

```
┌─────────────────┐    schema + samples    ┌──────────┐
│ Your MySQL DB   │ ─────────────────────▶ │  Claude  │
└─────────────────┘                        └────┬─────┘
        ▲                                       │ JSON: 6–10 report specs
        │ validated SELECT only                  ▼
        │                                ┌──────────────────────┐
        └────────────────── runs the SQL │ writable/            │
                                         │   report_configs.json│
                                         └──────────┬───────────┘
                                                    ▼
                                            /reports renders each
                                            spec as a chart + table
```

---

## Prerequisites

- [ ] CodeIgniter 4.x
- [ ] PHP 8.1+ (8.2 tested)
- [ ] MySQL 5.7+ or 8.x with `INFORMATION_SCHEMA` read access for the app's DB user
- [ ] Composer
- [ ] Anthropic API key — https://console.anthropic.com
- [ ] `writable/` is writable by the PHP user (CI4 already needs this)

The reporter reads from your existing CI4 DB connection (configured in `app/Config/Database.php`). By default it uses the `default` connection group; override via `Config\Reports::$connectionGroup` to point at a read replica or analytics DB. **No new connection setup is required** if your app already talks to MySQL.

---

## Phase 1 — Install (≈10 min)

### 1.1 Composer

```bash
composer require guzzlehttp/guzzle:^7.0
composer require dompdf/dompdf:^2.0   # only if you want PDF export
```

> **Gotcha — non-default namespace.** If your CI4 app uses `Acme\` instead of `App\`, update the `namespace` and `use` lines in every PHP file you copy in step 1.2.

### 1.2 Copy files

| File | Required? | Notes |
|---|---|---|
| `app/Config/Reports.php` | ✓ required | **The only file you'll edit. Phase 2 lives here.** |
| `app/Services/ClaudeService.php` | ✓ required | Reads tables / drilldown / placeholders from `Config\Reports` |
| `app/Services/SchemaInspectorService.php` | ✓ required | Samples tables listed in `Config\Reports::$tables` |
| `app/Services/ReportExecutorService.php` | ✓ required | Validates SELECT-only; appends LIMIT; binds named params |
| `app/Models/ReportConfigModel.php` | ✓ required | File-backed cache for `writable/report_configs.json` (no DB) |
| `app/Controllers/Analysis.php` | ✓ required | Orchestrates: schema → Claude → cache |
| `app/Controllers/Reports.php` | ✓ required | List / view / PDF |
| `app/Views/reports/index.php` | ✓ required | Card grid |
| `app/Views/reports/view.php` | ✓ required | Chart + table + (optional) entity picker |
| `app/Views/analysis/index.php` | ✓ required | Run / Regenerate buttons |
| `app/Controllers/Students.php` | ○ optional | Drilldown typeahead. Skip if `Config\Reports::$drilldown = null` |
| `app/Services/PdfExportService.php` | ○ optional | Skip if no PDF |
| `app/Views/reports/pdf.php` | ○ optional | Skip if no PDF |
| `app/Views/layout/main.php` | △ adapt | Replace with your own layout — see 1.2a |
| `app/Controllers/BaseController.php` | ✗ do NOT overwrite | Merge into yours; the new controllers extend `BaseController` |
| `app/Models/StudentModel.php` | △ adapt | See Phase 3.2 — your own entity model with `find()` and `searchByName()` |
| `app/Controllers/Dashboard.php`, demo `*Model.php`, `docker/`, `tests/` | ✗ skip | Demo-only |

#### 1.2a Layout

The donor views extend `layout/main` (Bootstrap 5 + Chart.js + Bootstrap Icons via CDN). Two options:

- **Lift-and-fit:** copy `layout/main.php` as `layout/reports.php` and have only the report views extend it.
- **Reuse yours:** in `reports/index.php`, `reports/view.php`, `analysis/index.php`, change `$this->extend('layout/main')` to your layout. Make sure your layout loads Chart.js (or wrap it in `$this->section('scripts')`).

### 1.3 Routes

Add to `app/Config/Routes.php`:

```php
$routes->get('/reports',                'Reports::index');
$routes->get('/reports/pdf/(:segment)', 'Reports::exportPdf/$1');
$routes->get('/reports/(:segment)',     'Reports::view/$1');

$routes->get('/analysis',               'Analysis::index');
$routes->post('/analysis/run',          'Analysis::run');
$routes->post('/analysis/regenerate',   'Analysis::regenerate');

// Optional — only if you keep the drilldown
$routes->get('/students/search',        'Students::search');
```

> **Gotcha — route conflicts.** If `/reports` or `/students` clash with existing routes, prefix them (`/ai-reports`, `/ai-analysis`). Update the redirect targets in `Analysis::runAnalysis` (search for `'/dashboard'` and `'/analysis'`) and links in the views.

### 1.4 Environment

Add to `.env`:

```ini
CLAUDE_API_KEY = sk-ant-api03-xxxxxxxxxxxxxx
CLAUDE_MODEL   = claude-sonnet-4-6
```

`ClaudeService` looks up `CLAUDE_API_KEY` first, then `ANTHROPIC_API_KEY`, falling back to `getenv()` for OS-level vars.

### 1.5 CSRF

The two POST routes (`/analysis/run`, `/analysis/regenerate`) require a CSRF token. The donor `analysis/index.php` already calls `csrf_field()`, so if CI4's CSRF filter is enabled (default), it works. Two scenarios to watch:

- **Custom analysis form:** include `<?= csrf_field() ?>` inside `<form>`.
- **Curl/script-driven analysis:** add the routes to your `Filters::$globals` exemption list, or send the token header.

### ✓ Phase 1 gates

```bash
php spark routes | grep -E '(analysis|reports|students)'   # 6 or 7 routes
curl -fI http://localhost:8080/analysis                    # HTTP/200
composer dump-autoload                                     # if you copied files manually
```

> **Gotcha — `Class App\… not found`.** Run `composer dump-autoload`. If you renamed namespaces, update every `use` line in the copied files.

---

## Phase 2 — Point it at your schema (≈15 min)

This is the only configuration step. Open `app/Config/Reports.php` and edit four properties.

```php
<?php
namespace Config;

class Reports
{
    /** Tables Claude may read AND that get sampled for the prompt. */
    public array $tables = [
        'pupils',          // ← your tables here
        'courses',
        'assessments',
    ];

    /** CI4 DB connection group. 'default' uses app/Config/Database.php's default group. */
    public string $connectionGroup = 'default';

    /** Rows per table sent as sample data. 0 disables sampling. */
    public int $sampleRowLimit = 3;

    /** LIMIT auto-appended to any generated SELECT lacking one. */
    public int $maxRowsPerReport = 200;

    /** Range Claude is asked to produce. */
    public int $minReports = 6;
    public int $maxReports = 10;

    /** Categories Claude may assign. The drilldown's category must appear here. */
    public array $categories = [
        'academic_performance', 'completion_tracking',
        'enrollment_demographics', 'subject_analysis',
    ];

    /** :placeholder name => 'int'|'string' cast. Empty array disables drilldown. */
    public array $allowedPlaceholders = [];

    /** Drilldown picker. Set to null to skip the entire drilldown surface. */
    public ?array $drilldown = null;     // ← null for now; come back in Phase 3
}
```

> **Gotcha — schema-coupled categories.** The donor's defaults include `student_drilldown`. If you set `$drilldown = null`, drop it from `$categories` (or keep it harmless). If you keep drilldown but rename the entity, change the `category` value inside `$drilldown` too.

> **Gotcha — DB user permissions.** The user needs `SELECT` on `INFORMATION_SCHEMA.COLUMNS`, `INFORMATION_SCHEMA.KEY_COLUMN_USAGE`, **and** every table in `$tables`. Read-only is fine — no writes are needed anywhere.

### ✓ Phase 2 gates

```bash
# Verify your DB user can read INFORMATION_SCHEMA
mysql -u <app_user> -p -e "SELECT COUNT(*) FROM information_schema.columns
                            WHERE table_schema = DATABASE();"

# Run the analysis (admin-only in production!)
curl -X POST http://localhost:8080/analysis/run -d "<csrf>"
# Expect a 302 redirect with success flash like "Analysis complete! 8 reports generated."

# Inspect the cache
jq 'length, .[].report_id' writable/report_configs.json

# Render the first report
curl -fI "http://localhost:8080/reports/$(jq -r '.[0].report_id' writable/report_configs.json)"
```

> **Gotcha — "Claude returned no valid report configurations".** Tail `writable/logs/log-*.php`. Most common cause: Claude's SQL referenced a column that doesn't exist or a table not in `$tables`. Either tighten `$tables` or set `$sampleRowLimit` higher so Claude has more context.

> **Gotcha — chart renders empty.** The cached `x_axis` or `y_axis` doesn't match a SELECT alias. Inspect the cache (`jq '.[] | {id: .report_id, x: .x_axis, y: .y_axis, sql: .sql_query}' writable/report_configs.json`), regenerate, or hand-edit.

---

## Phase 3 — Add drilldown (optional)

A drilldown lets a single report be parameterised by entity ID — pick a student, see only their grades. Skip this phase for read-only dashboard reports.

### 3.1 Decide your entity

Pick the noun. Examples:
- Demo: `student` (placeholder `student_id`)
- Other: `teacher`, `cohort`, `class`, `pupil`

### 3.2 Add searchByName() to your model

The drilldown picker calls two methods on your CI4 Model:

- `find($id)` — built into `CodeIgniter\Model`, free.
- `searchByName(string $q, int $limit): array` — you write this.

Reference shape (from `app/Models/StudentModel.php`):

```php
public function searchByName(string $q, int $limit = 20): array
{
    $q = trim($q);
    if ($q === '') return [];

    return $this->select('id, first_name, last_name, email')
        ->groupStart()
            ->like('first_name', $q, 'after')
            ->orLike('last_name',  $q, 'after')
        ->groupEnd()
        ->orderBy('last_name', 'ASC')
        ->limit($limit)
        ->find();
}
```

### 3.3 Fill the drilldown config

Back in `app/Config/Reports.php`:

```php
public array $allowedPlaceholders = [
    'teacher_id' => 'int',
];

public array $categories = [
    'academic_performance', 'subject_analysis', 'teacher_drilldown',
];

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
        'shape_description' => "Average score across every class this teacher runs",
        'sql_example'       => <<<SQL
    SELECT c.name AS class_name, AVG(g.score) AS avg_score
    FROM grades g
    JOIN classes c ON g.class_id = c.id
    WHERE c.teacher_id = :teacher_id
    GROUP BY c.id, c.name
    ORDER BY c.name
SQL,
    ],
];
```

### 3.4 Wire the typeahead route

Either copy `app/Controllers/Students.php` and rename to `Teachers.php` (it's already config-driven — the only schema reference is the model class via `Config\Reports::$drilldown['model']`), or just rename the route's path to match what you set in `search_route`:

```php
$routes->get('/teachers/search', 'Teachers::search');
```

### 3.5 Regenerate

The cached `report_configs.json` was built against the old prompt — run `Regenerate` from `/analysis` (or POST to `/analysis/regenerate`) so Claude rebuilds against the new drilldown spec.

### ✓ Phase 3 gates

```bash
curl 'http://localhost:8080/teachers/search?q=Smith' | jq '.[0] | {id, name, detail}'
# Expect rows with id, name, detail keys.

REPORT_ID=$(jq -r '.[] | select(.parameters | length > 0) | .report_id' writable/report_configs.json | head -1)
curl -fI "http://localhost:8080/reports/${REPORT_ID}?teacher_id=1"
# 200, page renders the picker pre-populated.
```

---

## Phase 4 — Production hardening

| Concern | What the app does | What you should add |
|---|---|---|
| API key leak | Read via `env()`; never logged | Rotate; store in a secrets manager |
| Malicious SQL | Validator rejects DML keywords + unknown placeholders. SELECT-only enforced at execute time | Defence in depth, **not a sandbox**. Use a **read-only DB user** for the app's data tables — the cache is a JSON file so the DB never needs write privileges |
| Cache tampering | `writable/report_configs.json` is plain text on disk | Treat `writable/` as trusted. Periodic `Regenerate` overwrites tampering |
| Multiple instances | Each instance owns its own cache file | Mount a shared volume or pass an absolute path: `new ReportConfigModel('/shared/report_configs.json')` |
| Anyone can hit `/analysis` and burn API credit | No auth — wide open | Put the route behind your auth filter. Admin-only in most cases |
| Anyone can run cached SQL | Validator is pre-execution; the cached SQL still queries any data in `$tables` | Restrict `/reports` to roles cleared to see all rows in `$tables` |
| Long Claude call blocks the request | 60 s Guzzle timeout | Move `Analysis::run` to a queue (CI4 Tasks, Beanstalkd) if users hit timeouts |
| AI report queries hit the primary DB | Uses `$connectionGroup = 'default'` | Define a read-replica or analytics group in `app/Config/Database.php` and set `Config\Reports::$connectionGroup` to its name. Keeps long aggregate scans off the write primary |
| PII in schema sample | `SELECT * FROM {table} LIMIT N` — first N rows hit Claude | Either set `$sampleRowLimit = 0`, or redact in `SchemaInspectorService::getSampleRows()`, or run analysis only against a non-prod DB whose schema mirrors prod |
| Cost | ~$0.01–$0.05 per analysis call | Don't expose `/analysis/run` to anonymous traffic; rate-limit |

---

## Cookbook

### "I have a `pupils` / `courses` / `assessments` schema, no drilldown"

```php
// app/Config/Reports.php  — diff vs default
- 'students', 'subjects', 'grades', 'student_subject_completion',
+ 'pupils', 'courses', 'assessments',

- public array $categories = [
-     'academic_performance', 'completion_tracking',
-     'enrollment_demographics', 'subject_analysis', 'student_drilldown',
- ];
+ public array $categories = [
+     'academic_performance', 'completion_tracking',
+     'enrollment_demographics',
+ ];

- public array $allowedPlaceholders = [ 'student_id' => 'int' ];
+ public array $allowedPlaceholders = [];

- public ?array $drilldown = [ ... ];
+ public ?array $drilldown = null;
```

That's the entire integration. Skip `Students.php`, skip `searchByName`, skip the `/students/search` route.

### "I want a per-teacher drilldown instead of per-student"

See Phase 3 above. The only file edit beyond `Config/Reports.php` is adding `searchByName()` to `TeacherModel`.

### "I want reports to hit a read replica, not my primary DB"

In `app/Config/Database.php`, define a second group alongside `default`:

```php
public array $analytics = [
    'DSN'      => '',
    'hostname' => 'replica.internal',
    'username' => 'analytics_ro',
    'password' => '...',
    'database' => 'student_db',
    'DBDriver' => 'MySQLi',
    // ... rest mirrors $default
];
```

Then in `app/Config/Reports.php`:

```diff
- public string $connectionGroup = 'default';
+ public string $connectionGroup = 'analytics';
```

`SchemaInspectorService` and `ReportExecutorService` will both pick it up automatically — no other code changes.

### "I want to redact PII before it goes to Claude"

Edit `SchemaInspectorService::getSampleRows()` — after the `SELECT *`, walk the rows and replace `email` / `phone` / `dob` columns with `'***'` (or set `$sampleRowLimit = 0` to skip sampling entirely; the schema alone is often enough).

---

## Reference

### `Config\Reports` field reference

| Field | Type | Default | Read by |
|---|---|---|---|
| `$tables` | `list<string>` | `['students','subjects','grades','student_subject_completion']` | `SchemaInspectorService`, `ClaudeService` |
| `$connectionGroup` | `string` | `'default'` | `SchemaInspectorService`, `ReportExecutorService` |
| `$sampleRowLimit` | `int` | `3` | `SchemaInspectorService` |
| `$maxRowsPerReport` | `int` | `200` | `ReportExecutorService` |
| `$minReports` / `$maxReports` | `int` / `int` | `6` / `10` | `ClaudeService` |
| `$categories` | `list<string>` | 5 demo categories | `ClaudeService` |
| `$allowedPlaceholders` | `array<string,'int'\|'string'>` | `['student_id'=>'int']` | `Analysis`, `ReportExecutorService`, `ClaudeService` |
| `$drilldown` | `array\|null` | student drilldown | `Reports`, `Students`, `ClaudeService` |

### Generated report JSON shape

```json
{
  "id": "snake_case_unique_identifier",
  "title": "Human Readable Report Title",
  "description": "...",
  "category": "one of $categories",
  "sql": "SELECT ...",
  "chart_type": "bar | line | pie | doughnut | scatter",
  "x_axis": "alias from SELECT",
  "y_axis": "alias from SELECT",
  "parameters": []
}
```

### Tests bundled with this repo

```bash
docker compose run --rm --entrypoint php app tests/smoke.php   # no DB / no API
docker compose up -d && docker compose exec app php tests/integration.php
```

See `tests/README.md` for running against pre-existing containers.