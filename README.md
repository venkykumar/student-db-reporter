# Student DB Reporter

A self-contained PHP CodeIgniter 4 application that connects to any MySQL student database, uses the **Claude AI API** to automatically inspect the schema and generate tailored reports, and renders them as an interactive Bootstrap 5 dashboard with Chart.js charts and PDF export.

> **Intended audience:** This README is written for a developer who wants to understand the architecture and adapt the pattern into their own PHP/CodeIgniter project — not just run this demo.

---

## Table of Contents

- [What it does](#what-it-does)
- [Architecture overview](#architecture-overview)
- [Project structure](#project-structure)
- [Database connection setup](#database-connection-setup)
- [LLM API key setup](#llm-api-key-setup)
- [The AI pipeline explained](#the-ai-pipeline-explained)
- [Running the app](#running-the-app)
  - [Option A — Docker (recommended)](#option-a--docker-recommended)
  - [Option B — Local (no Docker)](#option-b--local-no-docker)
- [Running tests](#running-tests)
- [Integrating into your own CI4 project](#integrating-into-your-own-ci4-project)
- [URL reference](#url-reference)
- [Tech stack](#tech-stack)

---

## What it does

1. Connects to a MySQL database containing student, subject, grade, and completion data
2. On demand, introspects the live schema (table names, columns, types, foreign keys, sample rows) and sends it to Claude
3. Claude returns a JSON list of recommended reports — each with a title, description, SQL query, and chart type
4. The app caches those report specs, executes the SQL, and renders each report as a chart + data table
5. One of the generated reports is a **per-student drilldown** — the user picks a student via a typeahead search, the app binds `:student_id` into the cached SQL at runtime, and Chart.js renders that student's scores across every subject
6. Any report can be exported to PDF with one click

---

## Architecture overview

```
┌─────────────────────────────────────────────────────────────┐
│                        Browser                              │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP
┌──────────────────────▼──────────────────────────────────────┐
│                  CodeIgniter 4 App                          │
│                                                             │
│  Controllers                                                │
│  ├── Dashboard   → KPI cards, summary charts                │
│  ├── Reports     → list / view / PDF export                 │
│  ├── Analysis    → triggers AI pipeline                     │
│  └── Students    → JSON typeahead for drilldown picker      │
│                                                             │
│  Services                                                   │
│  ├── SchemaInspectorService  → reads INFORMATION_SCHEMA     │
│  ├── ClaudeService           → calls Anthropic API (Guzzle) │
│  ├── ReportExecutorService   → runs Claude-generated SQL    │
│  └── PdfExportService        → Dompdf wrapper               │
│                                                             │
│  Models                                                     │
│  ├── StudentModel, SubjectModel, GradeModel, CompletionModel│
│  └── ReportConfigModel  → caches Claude specs to JSON file  │
└───────────┬─────────────────────────┬───────────────────────┘
            │ MySQLi          │ file  │ HTTPS
            │                 ▼       │
            │     ┌──────────────────────┐
            │     │ writable/            │
            │     │   report_configs.json│◄── Claude output cached here
            │     └──────────────────────┘
┌───────────▼───────────┐   ┌─────────▼─────────────┐
│   MySQL 8.0           │   │  Anthropic Claude API  │
│   student_db          │   │  claude-sonnet-4-6     │
│  ┌─────────────────┐  │   └───────────────────────┘
│  │ students        │  │
│  │ subjects        │  │
│  │ grades          │  │
│  │ student_subject │  │
│  │   _completion   │  │
│  └─────────────────┘  │
└───────────────────────┘
```

### Key design decisions

| Decision | Rationale |
|----------|-----------|
| Claude output cached as a JSON file in `writable/report_configs.json` | Survives container restarts, no schema pollution in the integrator's DB, no DDL/migration needed, atomic writes via temp-file+rename. CI4 already requires `writable/` to be writable for logs/sessions, so no extra permission setup |
| SQL generated by Claude, validated before execution | Flexible — reports adapt to any schema. Validation blocks DML keywords to prevent destructive queries |
| No official Anthropic PHP SDK | None exists; Guzzle is the CI4 community standard HTTP client and the raw call is ~15 lines |
| PDF uses Dompdf with inline CSS only | Dompdf supports CSS 2.1; no external stylesheets, no JavaScript. Tables render reliably in A4 landscape |

---

## Project structure

```
student-db-reporter/
│
├── docker-compose.yml          # Orchestrates db + app containers
├── Dockerfile                  # php:8.2-apache + composer + extensions
├── .env                        # Local secrets — never commit this
├── .env.example                # Committed template
│
├── docker/
│   ├── mysql/
│   │   └── init.sql            # Schema DDL + stored procedure seed data
│   └── app/
│       └── entrypoint.sh       # Runs composer install then apache
│
├── app/
│   ├── Config/
│   │   ├── App.php             # Base URL and session config
│   │   ├── Database.php        # DB connection defaults (overridden by .env)
│   │   ├── Routes.php          # All URL routes defined here
│   │   └── Paths.php           # Points CI4 to the vendor/ directory
│   │
│   ├── Controllers/
│   │   ├── Dashboard.php       # Loads KPIs + summary chart data
│   │   ├── Reports.php         # List, view, and PDF-export reports (binds :student_id)
│   │   ├── Analysis.php        # Runs/regenerates Claude analysis
│   │   └── Students.php        # JSON typeahead endpoint for the drilldown picker
│   │
│   ├── Models/
│   │   ├── StudentModel.php    # Also has searchByName() for the typeahead
│   │   ├── SubjectModel.php
│   │   ├── GradeModel.php      # Also has avgScoreBySubject() for dashboard chart
│   │   ├── CompletionModel.php # Also has statusDistribution() for dashboard chart
│   │   └── ReportConfigModel.php  # File-backed cache (writable/report_configs.json)
│   │
│   ├── Services/
│   │   ├── ClaudeService.php           # Anthropic API via Guzzle HTTP
│   │   ├── SchemaInspectorService.php  # INFORMATION_SCHEMA introspection
│   │   ├── ReportExecutorService.php   # Safe SELECT runner with LIMIT guard
│   │   └── PdfExportService.php        # Dompdf A4 landscape PDF stream
│   │
│   └── Views/
│       ├── layout/main.php     # Bootstrap 5 shell: sidebar + topbar + flash msgs
│       ├── dashboard/index.php # KPI cards + Chart.js bar + doughnut
│       ├── reports/
│       │   ├── index.php       # Card grid of all reports
│       │   ├── view.php        # Chart.js chart + data table
│       │   └── pdf.php         # Print layout: inline CSS only, no JS
│       └── analysis/index.php  # Run / Regenerate AI analysis
│
├── public/
│   ├── index.php               # CI4 front controller
│   └── .htaccess               # mod_rewrite for clean URLs
│
└── writable/                   # CI4 logs, cache, sessions (git-ignored content)
```

---

## Database connection setup

### Where it is configured

**`app/Config/Database.php`** sets the default values:

```php
public array $default = [
    'hostname' => 'db',           // Docker service name
    'username' => 'student_user',
    'password' => 'secret',
    'database' => 'student_db',
    'DBDriver' => 'MySQLi',
    'port'     => 3306,
    // ...
];
```

These defaults are **overridden at runtime** by your `.env` file using CI4's built-in environment variable format:

```ini
# .env
database.default.hostname = db
database.default.database = student_db
database.default.username = student_user
database.default.password = secret
database.default.port     = 3306
```

CI4 automatically maps `database.default.*` keys in `.env` to the corresponding array keys in `Database::$default` — no custom constructor code needed.

### In Docker Compose

The `.env` file is present in the project root which is bind-mounted into the container at `/var/www/html`. CI4 reads it from there on every request.

```yaml
# docker-compose.yml
services:
  app:
    volumes:
      - .:/var/www/html   # includes .env
```

The `db` service name (`db`) is used as the hostname because Docker Compose puts both containers on the same internal network (`student_net`), so `db` resolves to the MySQL container's IP automatically.

### Adapting to an existing database

To point this at your own MySQL instance instead of the Docker one, just update `.env`:

```ini
database.default.hostname = your-db-host.example.com
database.default.database = your_database
database.default.username = your_user
database.default.password = your_password
database.default.port     = 3306
```

No code changes required.

---

## LLM API key setup

`ClaudeService::__construct()` (`app/Services/ClaudeService.php:18-21`) tries four sources in order: `env('CLAUDE_API_KEY')`, `env('ANTHROPIC_API_KEY')`, `getenv('CLAUDE_API_KEY')`, `getenv('ANTHROPIC_API_KEY')`. The explicit `getenv()` fallback exists because CI4's `env()` only sees `$_ENV`/`$_SERVER`, which can miss shell-exported variables when PHP's `variables_order` is restrictive.

Three ways to provide the key, pick whichever fits your secret-management practice:

- **`.env` file** — `CLAUDE_API_KEY=sk-ant-api03-...`. Simplest for local dev. `.env` is gitignored; commit `.env.example` instead.
- **Shell env var** — `export ANTHROPIC_API_KEY=sk-ant-api03-...` before `docker compose up`. Good when secrets live in a CI/CD vault or your shell profile.
- **Docker Compose pass-through** — declare `ANTHROPIC_API_KEY: ${ANTHROPIC_API_KEY}` in the `app` service's `environment` block (already done in `docker-compose.yml`); the value is read from your shell at container start. Keeps the key out of the repo.

**Never** put the key in: the `Dockerfile` (it would leak into image layers visible to anyone running `docker history`), committed `app/Config/` files, or hardcoded `docker-compose.yml` values.

---

## The AI pipeline explained

This is the core of the application. Here is the full flow when you click "Run Analysis":

```
POST /analysis/run
        │
        ▼
Analysis::run()
        │
        ├─► SchemaInspectorService::inspect()
        │        Queries INFORMATION_SCHEMA.COLUMNS for all tables/columns/types
        │        Queries INFORMATION_SCHEMA.KEY_COLUMN_USAGE for foreign keys
        │        Runs SELECT * FROM {table} LIMIT 3 for each table (sample data)
        │        Returns structured PHP array
        │
        ├─► ClaudeService::analyzeSchema($schema)
        │        json_encodes the schema array
        │        POSTs to https://api.anthropic.com/v1/messages
        │        System prompt: "respond ONLY with a valid JSON array"
        │        User prompt: schema JSON + report spec format + SQL rules
        │        Returns raw text from Claude (should be a JSON array)
        │
        ├─► Analysis::parseAndValidate($rawJson)
        │        Strips any accidental markdown code fences
        │        json_decode() — throws if invalid
        │        Checks each item has required keys: id, title, sql
        │        Rejects any SQL containing DROP/DELETE/UPDATE/INSERT/ALTER/TRUNCATE/GRANT/REVOKE
        │        Allows ONLY :student_id as a placeholder; rejects any other :name
        │        Normalises the parameters[] field; rejects SQL that uses a placeholder
        │        without declaring it in parameters[]
        │        Returns validated array of report configs
        │
        └─► ReportConfigModel::replaceAll($configs)
                 json_encode the validated configs
                 file_put_contents to writable/report_configs.json.tmp (with LOCK_EX)
                 rename() the temp file over the live file (atomic on POSIX)

Redirect → /dashboard
```

When a report is viewed:

```
GET /reports/{report_id}[?student_id=N]
        │
        ▼
Reports::view($reportId)
        │
        ├─► ReportConfigModel::findByReportId()  — loads the cached spec
        │
        ├─► If parameters[] contains "student_id":
        │        Read ?student_id=N from the query string
        │        If absent, render the page with a typeahead picker (no chart yet)
        │        If present, look up the student and bind it for the SQL run
        │
        ├─► ReportExecutorService::execute($sql, $params)
        │        Verifies query starts with SELECT
        │        Appends LIMIT 200 if no LIMIT present
        │        Binds :student_id positionally if declared
        │        Runs query, returns result rows as array
        │
        └─► View: reports/view.php
                 Extracts x_axis and y_axis columns from rows
                 Passes to Chart.js as JSON-encoded arrays
                 Renders data table below the chart
```

### Per-student drilldown

The `student_drilldown` category is special: Claude is instructed to emit exactly one
report with a `:student_id` placeholder and `"parameters": ["student_id"]` declared.

- The reports list (`/reports`) shows the drilldown card alongside the others.
- Opening it without a `student_id` query parameter renders a typeahead search box
  that hits `GET /students/search?q=...` (`Students::search` →
  `StudentModel::searchByName()`) and returns up to 20 matching students as JSON.
- Selecting a student navigates to `/reports/student_subject_performance?student_id=N`,
  where `Reports::view` binds the id into the SQL via `ReportExecutorService`.
- PDF export at `/reports/pdf/student_subject_performance?student_id=N` works the
  same way; without `student_id` it 404s.

This is the only placeholder the validator allows — any other `:name` in
Claude's SQL causes the report to be rejected during validation.

---

## Running the app

### Option A — Docker (recommended)

The simplest path. Docker handles PHP, Apache, MySQL, and all extensions automatically.

**Prerequisites:** Docker Desktop (or Docker Engine + Compose plugin)

```bash
# 1. Copy and configure environment
cp .env.example .env
# Edit .env — set CLAUDE_API_KEY to your Anthropic key

# 2. Build and start
docker compose up --build
```

Wait ~30 seconds for MySQL to finish seeding. You'll see `ready for connections` in the logs.

```bash
# 3. Verify seed data loaded
docker compose exec db mysql -u student_user -psecret student_db \
  -e "SELECT COUNT(*) as students FROM students; SELECT COUNT(*) as grades FROM grades;"
# Expected: 100 students, 1000 grades
```

Visit **http://localhost:8080** and click **Run AI Analysis**.

**Ports**

| Service | Local port | Notes |
|---------|------------|-------|
| PHP app | 8080 | http://localhost:8080 |
| MySQL | 3307 | Use 127.0.0.1:3307 in a DB GUI — avoids clashing with a local MySQL on 3306 |

**Stopping and resetting**

```bash
# Stop (DB data is preserved in a named volume)
docker compose down

# Full reset — wipes all data and re-seeds from scratch
docker compose down -v && docker compose up --build
```

---

### Option B — Local (no Docker)

Run directly on your machine if you already have PHP and MySQL installed.

**Prerequisites**

- PHP 8.2+ with extensions: `pdo`, `pdo_mysql`, `mysqli`, `intl`, `zip`
- MySQL 8.0+ running locally
- Composer
- Apache or Nginx with `mod_rewrite` (or use PHP's built-in dev server)

**Check your PHP extensions**

```bash
php -m | grep -E 'pdo|mysqli|intl|zip'
```

On macOS with Homebrew: `brew install php` covers all required extensions.

**1. Install Composer dependencies**

```bash
composer install
```

**2. Create and seed the database**

```bash
# Connect to your local MySQL and run the init script
mysql -u root -p < docker/mysql/init.sql
```

This creates the `student_db` database, all tables, and seeds 100 students, 10 subjects, 1000 grades, and 1000 completion records using a stored procedure.

If your local MySQL root user has no password:
```bash
mysql -u root < docker/mysql/init.sql
```

**3. Configure `.env` for local MySQL**

```bash
cp .env.example .env
```

Edit `.env` and update the database section to point at your local MySQL:

```ini
database.default.hostname = 127.0.0.1
database.default.database = student_db
database.default.username = root
database.default.password = your_local_password
database.default.port     = 3306

CLAUDE_API_KEY = sk-ant-api03-your-key-here
```

**4. Start the PHP built-in dev server**

```bash
php -S localhost:8080 -t public/
```

Visit **http://localhost:8080** and click **Run AI Analysis**.

> **Note:** PHP's built-in server is fine for development. If you use Apache, ensure `mod_rewrite` is enabled — the `public/.htaccess` file handles CI4's URL routing. If you see 404s on routes with the built-in server, add `public string $uriProtocol = 'PATH_INFO';` to `app/Config/App.php`.

---

## Running tests

This repo includes lightweight smoke tests under `tests/`.

```bash
docker compose run --rm --entrypoint php app tests/smoke.php
docker compose up -d
docker compose exec app php tests/integration.php
```

For the full command reference, including already-running containers and direct
`docker exec` usage, see **[tests/README.md](tests/README.md)**.

---

## Integrating into your own CI4 project

For a step-by-step recipe — files to copy, prompt customization, route wiring, security considerations, common gotchas — see **[INTEGRATION.md](INTEGRATION.md)**.

Quick orientation:

- **Copy:** the three services (`ClaudeService`, `SchemaInspectorService`, `ReportExecutorService`), `ReportConfigModel`, and the `Analysis` + `Reports` controllers. Add the `Students` controller and the typeahead JS in `reports/view.php` only if you want the per-entity drilldown.
- **Install:** `composer require guzzlehttp/guzzle:^7.0` (and `dompdf/dompdf:^2.0` if you want PDF export).
- **Configure:** `CLAUDE_API_KEY` in your `.env`, plus the routes block from `app/Config/Routes.php`.
- **Customize the prompt:** edit the allowed-tables line in `ClaudeService::analyzeSchema()` to list your tables, and adjust the drilldown SQL example if your schema differs from the demo's `students/subjects/grades`.

No DB schema changes are needed — Claude's output is cached as a JSON file under `writable/`.

---

## URL reference

| Method | URL | Controller | Description |
|--------|-----|------------|-------------|
| GET | `/` | `Dashboard::index` | KPI cards + summary charts |
| GET | `/dashboard` | `Dashboard::index` | Same as above |
| GET | `/reports` | `Reports::index` | Card grid of all reports |
| GET | `/reports/{id}` | `Reports::view` | Single report: chart + table (accepts `?student_id=N` for drilldown) |
| GET | `/reports/pdf/{id}` | `Reports::exportPdf` | Download as PDF (accepts `?student_id=N` for drilldown) |
| GET | `/analysis` | `Analysis::index` | Run/Regenerate page |
| POST | `/analysis/run` | `Analysis::run` | Trigger Claude analysis |
| POST | `/analysis/regenerate` | `Analysis::regenerate` | Clear cache + re-run |
| GET | `/students/search?q=...` | `Students::search` | JSON typeahead for the drilldown student picker |

---

## Tech stack

| Component | Library / Version |
|-----------|------------------|
| Framework | CodeIgniter 4.5+ |
| PHP | 8.2 |
| Database | MySQL 8.0 |
| AI | Anthropic Claude (`claude-sonnet-4-6`) via REST API |
| HTTP client | Guzzle 7 |
| PDF export | Dompdf 2.x |
| Frontend | Bootstrap 5.3, Chart.js 4.4, Bootstrap Icons |
| Container | Docker Compose (mysql:8.0 + php:8.2-apache) |
