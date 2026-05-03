<?php

namespace App\Services;

use Config\Reports as ReportsConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ClaudeService
{
    private Client $client;
    private string $apiKey;
    private string $model;
    private ReportsConfig $config;

    public function __construct(?ReportsConfig $config = null)
    {
        // Try CI4's env() (reads .env + $_ENV/$_SERVER), then fall back to raw getenv()
        // (works for OS env vars not exposed to $_ENV when variables_order is restrictive)
        $this->apiKey = env('CLAUDE_API_KEY', '')
            ?: env('ANTHROPIC_API_KEY', '')
            ?: (getenv('CLAUDE_API_KEY') ?: '')
            ?: (getenv('ANTHROPIC_API_KEY') ?: '');

        $this->model  = env('CLAUDE_MODEL', 'claude-sonnet-4-6');
        $this->config = $config ?? new ReportsConfig();

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

        $userPrompt = $this->buildUserPrompt($schema);

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

    private function buildUserPrompt(array $schema): string
    {
        $schemaJson  = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $minReports  = $this->config->minReports;
        $maxReports  = $this->config->maxReports;
        $categories  = implode(' | ', $this->config->categories);
        $tablesList  = implode(', ', $this->config->tables);
        $drilldown   = $this->buildDrilldownBlock();
        $placeholder = $this->buildPlaceholderRules();

        return <<<USER
Here is the database schema and sample data:

{$schemaJson}

Generate between {$minReports} and {$maxReports} report recommendations. Each report must follow this exact JSON structure:

[
  {
    "id": "snake_case_unique_identifier",
    "title": "Human Readable Report Title",
    "description": "One or two sentences describing what this report reveals.",
    "category": "one of: {$categories}",
    "sql": "SELECT ... (valid MySQL 8.0 query using only the tables in the schema above)",
    "chart_type": "one of: bar | line | pie | doughnut | scatter",
    "x_axis": "column name from the SELECT to use as the X axis or label",
    "y_axis": "column name from the SELECT to use as the Y axis or numeric value",
    "parameters": []
  }
]

Rules for the sql field:
- Use ONLY the tables: {$tablesList}
- Always alias computed columns with descriptive snake_case names
- The column names in x_axis and y_axis must exactly match aliases used in the SELECT
- Add LIMIT 50 to queries that could return many rows
- Use only standard MySQL 8.0 syntax
- Every query must start with SELECT
{$drilldown}
{$placeholder}
USER;
    }

    private function buildDrilldownBlock(): string
    {
        if ($this->config->drilldown === null) {
            return '';
        }

        $d           = $this->config->drilldown;
        $r           = $d['required_report'];
        $placeholder = $d['placeholder'];
        $category    = $d['category'];
        $title       = $r['title'];
        $id          = $r['id'];
        $shape       = $r['shape_description'];
        $sqlExample  = $r['sql_example'];

        return <<<DRILL


Required: include EXACTLY ONE report in the "{$category}" category. This report must:
- Be titled "{$title}" (id: "{$id}")
- {$shape}
- Use the placeholder :{$placeholder} in the WHERE clause (the app binds this at runtime)
- Declare "parameters": ["{$placeholder}"] in the JSON spec
- Example SQL shape:
{$sqlExample}
DRILL;
    }

    private function buildPlaceholderRules(): string
    {
        $names = array_keys($this->config->allowedPlaceholders);

        if (empty($names)) {
            return "\n\nReports MUST NOT contain any :placeholder tokens. parameters MUST be the empty array [].";
        }

        if (count($names) === 1) {
            $only = $names[0];
            return "\n\nFor all OTHER reports, parameters MUST be the empty array [].\n:{$only} is the ONLY placeholder allowed; any other placeholder will be rejected.";
        }

        $list = implode(', ', array_map(static fn(string $n): string => ":{$n}", $names));
        return "\n\nFor all OTHER reports, parameters MUST be the empty array [].\nAllowed placeholders: {$list}. Any other placeholder will be rejected.";
    }
}