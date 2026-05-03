# Tests

## Run Both Test Suites

From the repository root, start the app stack and run both suites:

```sh
docker compose run --rm --entrypoint php app tests/smoke.php
docker compose up -d
docker compose exec app php tests/integration.php
```

If the Compose containers are already running, use:

```sh
docker compose exec app php tests/smoke.php
docker compose exec app php tests/integration.php
```

## Fast Smoke Tests

Run the dependency-free smoke checks from the repository root:

```sh
php tests/smoke.php
```

Or inside Docker Compose:

```sh
docker compose run --rm --entrypoint php app tests/smoke.php
```

These checks avoid MySQL, Claude, Docker, and a web server. They verify PHP
syntax, expected route registration, cached report config shape, file-backed
report cache behavior, and the AI report validator's basic safe/unsafe handling.

## Running With Docker Compose

## Integration Smoke Tests

The integration smoke tests require the Compose app and database services to be
running:

```sh
docker compose up -d
```

Then execute the tests inside the running `app` service:

```sh
docker compose exec app php tests/integration.php
```

The integration checks verify:

- MySQL schema and seed data
- Dashboard/report database joins
- Public HTTP pages
- Student typeahead JSON
- Report chart axis columns versus SQL result columns
- PDF export bytes for regular and student drilldown reports

`tests/integration.php` temporarily writes deterministic report configs to
`writable/report_configs.json` and restores the previous file when it exits.
That keeps the test independent of Claude-generated cached reports.

You can override connection defaults if needed:

```sh
SMOKE_BASE_URL=http://127.0.0.1 \
SMOKE_DB_HOST=db \
SMOKE_DB_USER=student_user \
SMOKE_DB_PASSWORD=secret \
SMOKE_DB_NAME=student_db \
SMOKE_DB_PORT=3306 \
docker compose exec app php tests/integration.php
```

If you are not sure what the Compose service is called, list service names:

```sh
docker compose config --services
```

This project defines `app` and `db` services in `docker-compose.yml`; use `app`
for the PHP test command.

To see running Compose containers:

```sh
docker compose ps
```

If you are using plain `docker ps`, that shows container names instead of
Compose service names. You can still run the tests directly against a running
container:

```sh
docker exec -it <container-name> php tests/integration.php
```

If the app container is not running and you only want the fast smoke checks,
start a one-off PHP container:

```sh
docker compose run --rm --entrypoint php app tests/smoke.php
```
