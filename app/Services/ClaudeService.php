<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ClaudeService
{
    private Client $client;
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        // Try CI4's env() (reads .env + $_ENV/$_SERVER), then fall back to raw getenv()
        // (works for OS env vars not exposed to $_ENV when variables_order is restrictive)
        $this->apiKey = env('CLAUDE_API_KEY', '')
            ?: env('ANTHROPIC_API_KEY', '')
            ?: (getenv('CLAUDE_API_KEY') ?: '')
            ?: (getenv('ANTHROPIC_API_KEY') ?: '');

        $this->model  = env('CLAUDE_MODEL', 'claude-sonnet-4-6');

        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com',
            'timeout'  => 60,
            'headers'  => [
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
        ]);
    }

    public function analyzeSchema(array $schema): string
    {
        if (empty($this->apiKey) || $this->apiKey === 'sk-ant-your-key-here') {
            throw new \RuntimeException('CLAUDE_API_KEY is not configured. Please set it in your .env file.');
        }

        $systemPrompt = <<<SYSTEM
You are a data analyst assistant. You will receive a MySQL database schema with sample data.
Your job is to recommend useful reports that can be generated from this data.
You must respond ONLY with a valid JSON array. Do not include any prose, markdown code fences,
or explanation outside the JSON. The JSON must be parseable by json_decode() in PHP with no pre-processing.
SYSTEM;

        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $userPrompt = <<<USER
Here is the database schema and sample data:

{$schemaJson}

Generate between 6 and 10 report recommendations. Each report must follow this exact JSON structure:

[
  {
    "id": "snake_case_unique_identifier",
    "title": "Human Readable Report Title",
    "description": "One or two sentences describing what this report reveals.",
    "category": "one of: academic_performance | completion_tracking | enrollment_demographics | subject_analysis | student_drilldown",
    "sql": "SELECT ... (valid MySQL 8.0 query using only the tables in the schema above)",
    "chart_type": "one of: bar | line | pie | doughnut | scatter",
    "x_axis": "column name from the SELECT to use as the X axis or label",
    "y_axis": "column name from the SELECT to use as the Y axis or numeric value",
    "parameters": []
  }
]

Rules for the sql field:
- Use ONLY the tables: students, subjects, grades, student_subject_completion
- Always alias computed columns with descriptive snake_case names
- The column names in x_axis and y_axis must exactly match aliases used in the SELECT
- Add LIMIT 50 to queries that could return many rows
- Use only standard MySQL 8.0 syntax
- Every query must start with SELECT

Required: include EXACTLY ONE report in the "student_drilldown" category. This report must:
- Be titled "Subject Performance for Student" (id: "student_subject_performance")
- Show a single student's score across every subject (one bar per subject)
- Use the placeholder :student_id in the WHERE clause (the app binds this at runtime)
- Declare "parameters": ["student_id"] in the JSON spec
- Example SQL shape:
    SELECT s.name AS subject_name, g.score AS score
    FROM grades g
    JOIN subjects s ON g.subject_id = s.id
    WHERE g.student_id = :student_id
    ORDER BY s.name

For all OTHER reports, parameters MUST be the empty array [].
:student_id is the ONLY placeholder allowed; any other placeholder will be rejected.
USER;

        try {
            $response = $this->client->post('/v1/messages', [
                'json' => [
                    'model'      => $this->model,
                    'max_tokens' => 4096,
                    'system'     => $systemPrompt,
                    'messages'   => [
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);

            return $body['content'][0]['text'] ?? '';

        } catch (GuzzleException $e) {
            throw new \RuntimeException('Claude API request failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
