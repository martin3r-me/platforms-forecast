<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanAnalyzer;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ScanPlanTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.scan.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/scan – Findet, was Aufmerksamkeit braucht: empty_open (leere, offene Zellen = '
            .'To-do), open_rest (Zeilen mit noch nicht verplantem Rest), warnings (Konsolidierungs-Probleme: '
            .'nicht aggregierbar, Einheit/Richtung ungleich), non_additive_master (Faktoren/Quoten am Ordner leer). '
            .'Parameter: plan (uuid), level (year|quarter|month|day, Default month), bucket (Container statt level), '
            .'checks (Teilmenge der obigen Prüfungen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'level' => ['type' => 'string', 'enum' => ['year', 'quarter', 'month', 'day']],
                'bucket' => ['type' => 'string', 'description' => 'Container-Bucket (Alternative zu level).'],
                'checks' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['empty_open', 'open_rest', 'warnings', 'non_additive_master']]],
            ],
            'required' => ['plan'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            $plan = $this->findPlan((string) ($arguments['plan'] ?? ''), $teamId);
            if (! $plan) {
                return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
            }

            $bucket = (string) ($arguments['bucket'] ?? '');
            $level = ! empty($arguments['level']) ? (string) $arguments['level'] : ($bucket === '' ? 'month' : null);
            $a = (new PlanAnalyzer())->analyze($plan, $bucket, $level);

            $checks = ! empty($arguments['checks']) && is_array($arguments['checks'])
                ? array_flip(array_map('strval', $arguments['checks']))
                : ['empty_open' => 1, 'open_rest' => 1, 'warnings' => 1, 'non_additive_master' => 1];

            $labelOf = [];
            foreach ($a['columns'] as $c) {
                $labelOf[$c['bucket']] = $c['label'];
            }
            $isMaster = $a['plan']['is_master'];

            $emptyOpen = [];
            $openRest = [];
            $warnings = [];
            $naMaster = [];

            foreach ($a['rows'] as $row) {
                if (isset($checks['warnings']) && ! empty($row['warnings'])) {
                    $warnings[] = ['row' => $row['key'], 'label' => $row['label'], 'warnings' => $row['warnings']];
                }
                if (isset($checks['non_additive_master']) && $isMaster && $row['non_additive']) {
                    $naMaster[] = ['row' => $row['key'], 'label' => $row['label'], 'unit' => $row['unit']];
                }
                foreach ($row['cells'] as $b => $cell) {
                    if (isset($checks['empty_open']) && $cell['state'] === 'open' && $cell['empty']) {
                        $emptyOpen[] = ['row' => $row['key'], 'label' => $row['label'], 'bucket' => $b, 'bucket_label' => $labelOf[$b] ?? $b];
                    }
                    if (isset($checks['open_rest']) && ($cell['rest'] ?? 0) > 0) {
                        $openRest[] = ['row' => $row['key'], 'label' => $row['label'], 'bucket' => $b, 'bucket_label' => $labelOf[$b] ?? $b, 'rest' => $cell['rest'], 'value' => $cell['value']];
                    }
                }
            }

            return ToolResult::success([
                'plan' => $a['plan'], 'level' => $a['level'],
                'summary' => [
                    'empty_open' => count($emptyOpen),
                    'open_rest' => count($openRest),
                    'warnings' => count($warnings),
                    'non_additive_master' => count($naMaster),
                ],
                'findings' => array_filter([
                    'empty_open' => isset($checks['empty_open']) ? $emptyOpen : null,
                    'open_rest' => isset($checks['open_rest']) ? $openRest : null,
                    'warnings' => isset($checks['warnings']) ? $warnings : null,
                    'non_additive_master' => isset($checks['non_additive_master']) ? $naMaster : null,
                ], fn ($v) => $v !== null),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'scan', 'audit'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
