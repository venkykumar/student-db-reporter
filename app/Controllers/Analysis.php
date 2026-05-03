<?php

namespace App\Controllers;

use App\Models\ReportConfigModel;
use App\Services\ClaudeService;
use App\Services\SchemaInspectorService;
use Config\Reports as ReportsConfig;

class Analysis extends BaseController
{
    public function index(): string
    {
        $reportModel = new ReportConfigModel();
        $reports     = $reportModel->findAll();

        return view('analysis/index', [
            'reports'    => $reports,
            'hasReports' => count($reports) > 0,
            'pageTitle'  => 'AI Analysis',
        ]);
    }

    public function run(): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->runAnalysis(false);
    }

    public function regenerate(): \CodeIgniter\HTTP\RedirectResponse
    {
        return $this->runAnalysis(true);
    }

    private function runAnalysis(bool $force): \CodeIgniter\HTTP\RedirectResponse
    {
        $reportModel = new ReportConfigModel();

        if (!$force && $reportModel->hasReports()) {
            return redirect()->to('/analysis')->with('info', 'Reports already generated. Use Regenerate to refresh.');
        }

        try {
            $inspector = new SchemaInspectorService();
            $schema    = $inspector->inspect();

            $claude   = new ClaudeService();
            $rawJson  = $claude->analyzeSchema($schema);

            $configs = $this->parseAndValidate($rawJson);

            if (empty($configs)) {
                return redirect()->to('/analysis')->with('error', 'Claude returned no valid report configurations.');
            }

            $reportModel->replaceAll($configs);

            return redirect()->to('/dashboard')->with('success', 'Analysis complete! ' . count($configs) . ' reports generated.');

        } catch (\Exception $e) {
            log_message('error', 'Analysis failed: ' . $e->getMessage());
            return redirect()->to('/analysis')->with('error', 'Analysis failed: ' . $e->getMessage());
        }
    }

    private function parseAndValidate(string $rawJson): array
    {
        $json = trim($rawJson);

        // Strip markdown code fences if present
        $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
        $json = preg_replace('/\s*```$/', '', $json);

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Claude did not return a valid JSON array. Raw: ' . substr($rawJson, 0, 500));
        }

        $config              = new ReportsConfig();
        $required            = ['id', 'title', 'sql'];
        $forbidden           = ['DROP', 'DELETE', 'UPDATE', 'INSERT', 'ALTER', 'TRUNCATE', 'GRANT', 'REVOKE'];
        $allowedPlaceholders = array_keys($config->allowedPlaceholders);
        $valid               = [];

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $missing = array_diff($required, array_keys($item));
            if (!empty($missing)) {
                log_message('warning', 'Skipping report missing keys: ' . implode(', ', $missing));
                continue;
            }

            $sqlUpper = strtoupper($item['sql']);
            $hasForbidden = false;
            foreach ($forbidden as $keyword) {
                if (str_contains($sqlUpper, $keyword)) {
                    log_message('warning', "Skipping report '{$item['id']}': SQL contains forbidden keyword '{$keyword}'");
                    $hasForbidden = true;
                    break;
                }
            }
            if ($hasForbidden) {
                continue;
            }

            // Validate placeholders: only :student_id is allowed
            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $item['sql'], $matches);
            $placeholdersUsed = array_unique($matches[1] ?? []);
            $disallowed = array_diff($placeholdersUsed, $allowedPlaceholders);
            if (!empty($disallowed)) {
                log_message('warning', "Skipping report '{$item['id']}': uses disallowed placeholders " . implode(', ', $disallowed));
                continue;
            }

            // Normalize parameters field; ensure declared parameters match what SQL uses
            $declared = isset($item['parameters']) && is_array($item['parameters'])
                ? array_values(array_filter($item['parameters'], 'is_string'))
                : [];
            $declared = array_values(array_intersect($declared, $allowedPlaceholders));

            // If SQL uses placeholders, declared must include them
            if (!empty($placeholdersUsed) && array_diff($placeholdersUsed, $declared)) {
                log_message('warning', "Skipping report '{$item['id']}': SQL uses :placeholder but parameters[] doesn't declare it");
                continue;
            }

            $item['parameters'] = $declared;
            $valid[] = $item;
        }

        return $valid;
    }
}
