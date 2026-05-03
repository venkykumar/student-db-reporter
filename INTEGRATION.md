# Integration Guide

How to add this app's **AI-driven report generator** into an existing CodeIgniter 4 project.

> **Audience:** A team that already has a working CI4 application backed by a MySQL student database (their own tables, their own schema), and wants to add Claude-generated reports without forking this repo.
>
> **Outcome:** Three new pages in your app — `/analysis` (run the AI), `/reports` (list of generated reports), `/reports/{id}` (chart + table per report) — plus an optional `/students/search` typeahead for per-student drilldown.

---

## Table of Contents

- [Overview of the integration](#overview-of-the-integration)
- [Prerequisites](#prerequisites)
- [Step 1 — Install Composer dependencies](#step-1--install-composer-dependencies)
- [Step 2 — Copy the service classes](#step-2--copy-the-service-classes)
- [Step 3 — Copy the model](#step-3--copy-the-model)
- [Step 4 — Copy the controllers](#step-4--copy-the-controllers)
- [Step 5 — Copy or adapt the views](#step-5--copy-or-adapt-the-views)
- [Step 6 — Wire routes](#step-6--wire-routes)
- [Step 7 — Set environment variables](#step-7--set-environment-variables)
- [Step 8 — Adapt the Claude prompt to your schema](#step-8--adapt-the-claude-prompt-to-your-schema)
- [Step 9 — Map your schema's column names](#step-9--map-your-schemas-column-names)
- [Step 10 — Test the integration](#step-10--test-the-integration)
- [Security & operational considerations](#security--operational-considerations)
- [What NOT to copy](#what-not-to-copy)
- [Common gotchas](#common-gotchas)

---

## Overview of the integration

You are adding **5–7 PHP files** (3 services + 1 model + 1 controller required, plus 2 optional: `Students` controller for drilldown, `PdfExportService` for PDF), **one or two env vars**, and a handful of view files. Everything else you already have — CI4 framework, autoloader, DB connection, base layout — is reused. **No schema changes to your database.**

```
Your existing CI4 app
        │
        ├── add app/Services/ClaudeService.php           ← Anthropic API call
        ├── add app/Services/SchemaInspectorService.php  ← reads INFORMATION_SCHEMA
        ├── add app/Services/ReportExecutorService.php   ← safe SELECT runner
        ├── add app/Models/ReportConfigModel.php         ← file-backed cache
        ├── add app/Controllers/Analysis.php             ← orchestrator
        ├── add app/Controllers/Reports.php              ← list / view / PDF
        ├── add app/Controllers/Students.php             ← (optional) typeahead
        │
        ├── add CLAUDE_API_KEY to your .env
        └── add 5–6 routes to Config/Routes.php
```

The pipeline at runtime:

```
User clicks "Run Analysis"
   → Analysis::run reads YOUR live schema via INFORMATION_SCHEMA
   → sends schema+samples to Claude
   → Claude returns 6–10 report specs (title, SQL, chart_type)
   → Analysis caches them in writable/report_configs.json (atomic write)
   → User browses /reports, sees each report rendered as a Chart.js chart
```

Claude never sees your real customer data — only the **schema** and a 3-row sample from each table. The SQL it generates is validated (SELECT-only, no DML keywords, restricted placeholders) before execution. Cached specs live as a JSON file in `writable/`, so there are no migrations to run and nothing new in your database.

---

## Prerequisites

| Requirement | Notes |
|---|---|
| CodeIgniter | 4.x (any minor) |
| PHP | 8.1+ recommended (8.2 tested) |
| MySQL | 5.7+ or 8.x — needs `INFORMATION_SCHEMA` access |
| Composer | for two new dependencies |
| Anthropic API key | from https://console.anthropic.com |
| DB user permissions | `SELECT` on `INFORMATION_SCHEMA.COLUMNS`, `INFORMATION_SCHEMA.KEY_COLUMN_USAGE`, and your data tables. Read-only is fine — no writes are needed |
| Writable filesystem | `writable/` must be writable by the PHP user (CI4 already requires this for logs/sessions) |

If your CI4 app uses a non-default namespace (anything other than `App\`), see [Common gotchas](#common-gotchas).

---

## Step 1 — Install Composer dependencies

```bash
composer require guzzlehttp/guzzle:^7.0
composer require dompdf/dompdf:^2.0   # only if you want PDF export
```

If you skip Dompdf, also delete `app/Services/PdfExportService.php` and the `exportPdf()` method in `Reports.php`.

---

## Step 2 — Copy the service classes

Drop these files into your project at the same paths:

| File | Modify? |
|---|---|
| `app/Services/ClaudeService.php` | Yes — see Step 8 (prompt) |
| `app/Services/SchemaInspectorService.php` | No — generic, schema-agnostic |
| `app/Services/ReportExecutorService.php` | No — generic |
| `app/Services/PdfExportService.php` | No (skip if not using PDF) |

`SchemaInspectorService` reads `INFORMATION_SCHEMA` against the **default** CI4 DB connection. If your AI reports should run against a different connection (e.g. a read replica, or an analytics DB), inject a connection name into both this service and `ReportExecutorService` rather than relying on `\Config\Database::connect()`.

---

## Step 3 — Copy the model

Copy `app/Models/ReportConfigModel.php`. It is a **plain PHP class** (does not extend `CodeIgniter\Model`) and reads/writes `writable/report_configs.json` via `file_get_contents` and an atomic temp-file-plus-rename. No DB connection, no schema, no migrations. Nothing to change.

If you want the cache to live somewhere other than `writable/report_configs.json`, pass an absolute path to the constructor:

```php
$reportModel = new ReportConfigModel('/var/lib/myapp/cached_reports.json');
```

The default `WRITEPATH . 'report_configs.json'` resolves correctly inside any standard CI4 app and is what every controller in this codebase uses.

---

## Step 4 — Copy the controllers

| File | Modify? |
|---|---|
| `app/Controllers/Analysis.php` | Maybe — see Step 8 if you change the validator's `$allowedPlaceholders` |
| `app/Controllers/Reports.php` | Maybe — only if your `students` table is named differently or you skip the drilldown |
| `app/Controllers/Students.php` | Optional — only needed for the per-student drilldown typeahead |
| `app/Controllers/BaseController.php` | **Do NOT overwrite** — merge into your own. The new controllers extend `BaseController`; if your project's base controller has different setup, just change the `extends` line in the new controllers |

If you don't want the per-student drilldown:
- Skip `Students.php`.
- In `Reports.php`, remove the `$needsStudentId` branches in `view()` and `exportPdf()`.
- In `Analysis.php`, remove `student_id` from `$allowedPlaceholders` (or set it to `[]` to reject all placeholders).
- In `ClaudeService.php`, delete the entire "Required: include EXACTLY ONE report in the student_drilldown category…" block (see Step 8).

---

## Step 5 — Copy or adapt the views

The donor views are written for **Bootstrap 5 + Chart.js + Bootstrap Icons** loaded via CDN in `app/Views/layout/main.php`. If your existing app already has a base layout, you have two choices:

**Option A — Lift-and-fit:** rename our `layout/main.php` to something like `layout/reports.php` and have only the report-related views extend it. Self-contained, no risk of clashing with your existing UI.

**Option B — Reuse your layout:** in `reports/index.php`, `reports/view.php`, and `analysis/index.php`, change `$this->extend('layout/main')` to your own layout filename, and ensure your base layout already loads Chart.js (or add it to the report views with a `$this->section('scripts')`).

Files to copy:

```
app/Views/layout/main.php              ← see Option A vs B above
app/Views/reports/index.php            ← card grid of all reports
app/Views/reports/view.php             ← chart + table + drilldown picker
app/Views/reports/pdf.php              ← Dompdf print layout (skip if no PDF)
app/Views/analysis/index.php           ← Run / Regenerate buttons
```

`reports/view.php` contains inline JS for the typeahead — if you skip the drilldown, delete the `<script>` block at the bottom.

---

## Step 6 — Wire routes

Add to `app/Config/Routes.php`:

```php
// AI report generator
$routes->get('/reports',                'Reports::index');
$routes->get('/reports/pdf/(:segment)', 'Reports::exportPdf/$1');
$routes->get('/reports/(:segment)',     'Reports::view/$1');

$routes->get('/analysis',               'Analysis::index');
$routes->post('/analysis/run',          'Analysis::run');
$routes->post('/analysis/regenerate',   'Analysis::regenerate');

// Optional — only needed for per-student drilldown
$routes->get('/students/search',        'Students::search');
```

If `/reports` or `/students` clash with existing routes in your app, prefix them: e.g. `/ai-reports`, `/ai-analysis`. Update the redirect targets in `Analysis.php` (search for `'/analysis'` and `'/dashboard'`) and the links in the views accordingly.

---

## Step 7 — Set environment variables

Add to your `.env` (or your secrets manager / CI vault):

```ini
CLAUDE_API_KEY = sk-ant-api03-xxxxxxxxxxxxxx
CLAUDE_MODEL   = claude-sonnet-4-6
```

`ClaudeService` looks up `CLAUDE_API_KEY` first, then falls back to `ANTHROPIC_API_KEY` (the standard name). It also falls back to raw `getenv()` so OS-level env vars work without going through `.env`. Use whichever fits your secret-management practice.

**Never** commit the key. **Never** hardcode it in `app/Config/`. **Never** bake it into a Docker image layer.

---

## Step 8 — Adapt the Claude prompt to your schema

The prompt lives in `app/Services/ClaudeService.php`, in the `analyzeSchema()` method. Two parts are coupled to **this** app's specific table names:

**8a — The allowed-tables line:**

```php
- Use ONLY the tables: students, subjects, grades, student_subject_completion
```

Change this to **your** table names. If your schema is also student-context but the tables are named differently — say `pupils`, `courses`, `assessments`, `enrolments` — list those instead. If you have many more tables but only some are safe for reporting, list only the safe ones.

**8b — The drilldown block** (only if you want per-student reports):

The prompt currently mandates one report titled `"Subject Performance for Student"` with a specific SQL shape:

```sql
SELECT s.name AS subject_name, g.score AS score
FROM grades g
JOIN subjects s ON g.subject_id = s.id
WHERE g.student_id = :student_id
ORDER BY s.name
```

Update the table and column names in that example to match your schema. If your equivalent of "subject" is "course", and your equivalent of "score" lives in a column called `mark`, change the example SQL shape — Claude will follow it.

If you want a drilldown over a **different entity** (per-teacher, per-cohort, per-class), it's more involved:
1. Rename the placeholder from `:student_id` to e.g. `:teacher_id` everywhere.
2. Update `$allowedPlaceholders` in `Analysis::parseAndValidate()`.
3. Replace `Students.php` with `Teachers.php` (or whatever) and the matching `searchByName()`.
4. Update the typeahead `<script>` in `reports/view.php` to hit your new endpoint.

---

## Step 9 — Map your schema's column names

The chart-rendering code expects the columns named in each report's `x_axis` and `y_axis` fields to **match aliases in the SELECT**. The prompt already instructs Claude to use snake_case aliases — if you keep the prompt rules intact, this works automatically.

**Where your schema does need to match assumptions:**

- **Drilldown picker** (`StudentModel::searchByName()`): hardcoded to `first_name`, `last_name`, `email` columns on a `students` table. If your student table uses different column names, update the `select(...)` and `like(...)` calls.
- **Drilldown student lookup** (`Reports::view`, `Reports::exportPdf`): calls `(new StudentModel())->find($studentId)` then accesses `$student['id']` for the PDF filename. Won't break if your PK column has a different name, but the filename suffix may look odd.
- **Schema introspection** (`SchemaInspectorService::inspect()`): reads from `INFORMATION_SCHEMA.COLUMNS` and `INFORMATION_SCHEMA.KEY_COLUMN_USAGE` — works against any MySQL schema as long as the DB user has the standard read access. Filters by `table_schema = DATABASE()` so it only sees the connected database.

---

## Step 10 — Test the integration

Walk through this checklist after wiring everything up:

1. **Composer autoload regenerated?** Run `composer dump-autoload` if you copied PSR-4 files into a non-standard location.
2. **DB user can read INFORMATION_SCHEMA?** `SELECT * FROM information_schema.columns WHERE table_schema = DATABASE() LIMIT 1;` — should return rows.
3. **Hit `/analysis`** — page renders without error.
4. **Click "Run Analysis"** — should redirect to `/dashboard` (or `/reports` if you removed the dashboard) with a flash like *"Analysis complete! 8 reports generated."*
5. **Check the cache file** — `cat writable/report_configs.json | jq 'length'` should print a number between 6 and 10. `jq '.[].report_id'` lists the generated reports.
6. **Visit `/reports`** — card grid renders.
7. **Click a card** — chart and table render. View browser devtools network tab; Chart.js shouldn't 404.
8. **Try the drilldown** — type a student surname into the search box, pick a student, verify the chart updates and the URL has `?student_id=N`.
9. **Click PDF export** — file downloads, opens in a PDF viewer, table is readable in A4 landscape.
10. **Click "Regenerate"** — confirms it calls Claude again and replaces the cache file atomically (the temp-file + rename guarantees no half-state if the API call fails mid-flight; check that no `writable/report_configs.json.tmp` is left behind).

If step 4 fails with a 500 or a flash error, tail your CI4 logs (`writable/logs/log-*.php`) — the orchestrator catches all exceptions and writes them there before redirecting.

This repo also includes automated smoke tests for the demo app. Run the fast
suite and the Docker Compose integration suite with:

```bash
docker compose run --rm --entrypoint php app tests/smoke.php
docker compose up -d
docker compose exec app php tests/integration.php
```

For details, including how to run against already-running containers, see
**[tests/README.md](tests/README.md)**.

---

## Security & operational considerations

| Concern | What this app does | What you should add |
|---|---|---|
| API key leak | Read via `env()`; never logged | Rotate on schedule; store in a secrets manager in prod |
| Claude generates malicious SQL | Validator rejects DML keywords (`DROP`, `DELETE`, `UPDATE`, `INSERT`, `ALTER`, `TRUNCATE`, `GRANT`, `REVOKE`) and unknown `:placeholder` names | The validator is a defence in depth, **not a sandbox**. Use a DB user with **read-only** access to all data tables — the cache lives in a JSON file, so the DB user truly never needs write privileges. A CTE-style `SELECT … INTO OUTFILE` would currently slip through the keyword check; the read-only user blocks that |
| Cache file tampering | `writable/report_configs.json` is plain text on disk — anyone with shell access to the server can edit the cached SQL | Treat `writable/` with the same trust level as the rest of the application code. If your threat model includes attackers with shell access who could modify cached SQL, you have bigger problems — but you can mitigate by running a periodic `Regenerate` to overwrite tampered configs |
| Multiple app instances | Each instance has its own `writable/report_configs.json` — running "Regenerate" on instance A doesn't propagate to instance B | If you autoscale, mount a shared volume on `writable/`, or pass a shared NFS path to `new ReportConfigModel('/shared/report_configs.json')`. Single-instance deployments don't have this issue |
| Anyone can hit `/analysis` and burn API credit | No auth — wide open | Put the route behind your existing auth/role middleware. `/analysis` should typically be admin-only |
| Anyone can hit `/reports/{id}` and run arbitrary cached SQL against your DB | The SQL was generated by Claude and validated — but it can still query any data in the listed tables | If your DB contains sensitive PII, restrict `/reports` to roles that are already cleared to see all student data |
| Long-running Claude API call blocks the request thread | 60 s timeout configured in `ClaudeService` | Move `Analysis::run` to a queue (CI4 Tasks, Beanstalkd, etc.) if you find users hitting timeouts |
| PII in the schema sample sent to Claude | `SELECT * FROM {table} LIMIT 3` is sent on every analysis — this includes real data from the first 3 rows of every table | Either redact in `SchemaInspectorService::inspect()` (replace email/PII columns with placeholders before sending), or run analysis only against a non-prod DB whose schema mirrors prod |
| Cost | Each analysis call is a single Claude request, ~$0.01–0.05 depending on schema size | Don't expose `/analysis/run` to anonymous traffic; rate-limit if you do |

---

## What NOT to copy

- **`docker/mysql/init.sql`** — that's the demo seed data. You have your own DB.
- **`docker-compose.yml` / `Dockerfile`** — you have your own deployment.
- **`app/Controllers/Dashboard.php`** and `app/Models/{Student,Subject,Grade,Completion}Model.php` — these are demo-specific. The dashboard's KPI cards (`countTotal()`, `avgScoreBySubject()`, `statusDistribution()`) are hardcoded to this app's schema. Your existing app probably has its own dashboard already.
- **`composer.json` / `composer.lock`** — merge dependencies into your own (just `guzzlehttp/guzzle` and optionally `dompdf/dompdf`), don't replace.
- **`app/Config/*.php` files** — you have your own. The only exception is the routes block in Step 6, which you merge into your existing `Routes.php`.

---

## Common gotchas

**"Class App\Services\ClaudeService not found"** — Run `composer dump-autoload`. If your CI4 app uses a non-default namespace (e.g. `Acme\` instead of `App\`), update the `namespace` and `use` lines at the top of every copied PHP file to match.

**"Claude returned no valid report configurations"** — Tail `writable/logs/log-*.php`. Most common cause: the validator rejected every report because Claude's SQL referenced a table not in your allowed-tables list, or used a column that doesn't exist in your schema. Fix the prompt (Step 8a).

**Chart renders empty** — `x_axis` or `y_axis` in the cached config doesn't match a column in the SELECT. Inspect the cache file: `jq '.[] | select(.report_id == "…") | {x_axis, y_axis, sql_query}' writable/report_configs.json`. Either regenerate (the prompt rules tell Claude to use snake_case aliases that match) or hand-edit the JSON file directly.

**`writable/report_configs.json` permission denied** — The PHP user (typically `www-data`, `apache`, or `nginx`) needs write access to `writable/`. CI4 already requires this for logs/sessions; if logs are working but the cache file isn't, your deploy probably has stricter ACLs on subpaths. Run `chmod -R u+w writable/` or check your deploy scripts.

**Drilldown picker shows no results** — `StudentModel::searchByName()` queries `first_name` / `last_name` columns. If your schema uses `given_name` / `family_name` (or any other naming), update those columns in the model and the `groupStart()` block.

**`/analysis/run` redirects with "Reports already generated"** — That's by design: the first call populates the cache, subsequent calls no-op unless you click "Regenerate" (which calls `Analysis::regenerate` → `runAnalysis(true)` and forces a refresh).

**"SQLSTATE[42S02]: Base table or view not found: 'information_schema.columns'"** — Your DB user lacks access to `INFORMATION_SCHEMA`. Grant it: `GRANT SELECT ON information_schema.* TO 'your_user'@'%';`.

**PDF export shows broken layout** — Dompdf supports CSS 2.1 only. No flexbox, no grid, no JS. The donor `pdf.php` view is already inline-CSS-only — if you customise it, stick to plain `<table>` layout.

**You changed the namespace and now `env()` returns null for `CLAUDE_API_KEY`** — `env()` is a CI4 global helper, namespace-independent. The issue is more likely that `.env` isn't being read (CI4 loads it from project root via `system/Config/DotEnv.php`). Verify with `var_dump(getenv('CLAUDE_API_KEY'));` in a test route.
