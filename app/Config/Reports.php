<?php

namespace Config;

/**
 * Schema-coupled configuration for the AI report generator.
 *
 * Edit this single file to point the reporter at a different schema.
 * Every service and controller in app/Services and app/Controllers
 * reads its schema-specific values from here.
 */
class Reports
{
    /**
     * Tables Claude is allowed to read.
     *
     * Used both as the prompt's allowed-tables list AND as the set of
     * tables sampled by SchemaInspectorService. List only read-safe
     * data tables. Never include user/auth/audit tables.
     *
     * @var list<string>
     */
    public array $tables = [
        'students',
        'subjects',
        'grades',
        'student_subject_completion',
    ];

    /**
     * CI4 database connection group to read from.
     *
     * Defaults to 'default', i.e. whatever your app/Config/Database.php
     * has set as $defaultGroup. Point this at a read replica or a
     * dedicated analytics group to keep AI report queries off the
     * primary write connection.
     */
    public string $connectionGroup = 'default';

    /** Rows per table sent to Claude as sample data. 0 disables sampling. */
    public int $sampleRowLimit = 3;

    /** Auto-appended LIMIT for any generated SELECT that lacks one. */
    public int $maxRowsPerReport = 200;

    /** Range Claude is asked to produce. */
    public int $minReports = 6;
    public int $maxReports = 10;

    /**
     * Categories Claude may assign to a generated report.
     *
     * @var list<string>
     */
    public array $categories = [
        'academic_performance',
        'completion_tracking',
        'enrollment_demographics',
        'subject_analysis',
        'student_drilldown',
    ];

    /**
     * Allowed :placeholder names in report SQL, mapped to PHP cast type.
     *
     * Map: name => 'int' | 'string'. Empty array disables every
     * parameterised report (no drilldown, no per-entity filtering).
     *
     * @var array<string, 'int'|'string'>
     */
    public array $allowedPlaceholders = [
        'student_id' => 'int',
    ];

    /**
     * Drilldown configuration for per-entity reports.
     *
     * Set to null to disable the entire drilldown surface — the
     * Students controller, the typeahead, the per-entity required
     * report, and the conditional view branches. The /reports/{id}
     * page works either way; null just removes the picker.
     *
     * Fields:
     *   placeholder       — :name used in report SQL (must also appear in allowedPlaceholders)
     *   entity_label      — display name shown in the picker label
     *   category          — Claude category for the required drilldown report
     *   model             — fully-qualified CodeIgniter Model class for the entity
     *                       (must define find($id) and searchByName($q, $limit))
     *   search_columns    — columns the typeahead considers a match
     *   search_route      — front-end fetch URL for the typeahead picker
     *   required_report   — shape Claude must produce for the drilldown report
     *
     * @var array{
     *   placeholder: string,
     *   entity_label: string,
     *   category: string,
     *   model: class-string,
     *   search_columns: list<string>,
     *   search_route: string,
     *   required_report: array{id: string, title: string, shape_description: string, sql_example: string}
     * }|null
     */
    public ?array $drilldown = [
        'placeholder'    => 'student_id',
        'entity_label'   => 'Student',
        'category'       => 'student_drilldown',
        'model'          => \App\Models\StudentModel::class,
        'search_columns' => ['first_name', 'last_name', 'email'],
        'search_route'   => '/students/search',
        'required_report' => [
            'id'                => 'student_subject_performance',
            'title'             => 'Subject Performance for Student',
            'shape_description' => "Show a single student's score across every subject (one bar per subject)",
            'sql_example'       => <<<SQL
    SELECT s.name AS subject_name, g.score AS score
    FROM grades g
    JOIN subjects s ON g.subject_id = s.id
    WHERE g.student_id = :student_id
    ORDER BY s.name
SQL,
        ],
    ];
}