<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanAnalyzer;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class SeriesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.series.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/series – Eine Zeile als Zeitreihe über eine Ebene: Wert je Bucket + Δ zur '
            .'Vorperiode + Anteil am Reihen-Summe. Ideal für Trend/Chart. Parameter: plan (uuid), row (row_key), '
            .'level (year|quarter|month|day, Default month), bucket (Container statt level).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'row' => ['type' => 'string'],
                'level' => ['type' => 'string', 'enum' => ['year', 'quarter', 'month', 'day']],
                'bucket' => ['type' => 'string', 'description' => 'Container-Bucket (Alternative zu level).'],
            ],
            'required' => ['plan', 'row'],
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
            $rowKey = (string) ($arguments['row'] ?? '');
            $bucket = (string) ($arguments['bucket'] ?? '');
            $level = ! empty($arguments['level']) ? (string) $arguments['level'] : ($bucket === '' ? 'month' : null);

            $a = (new PlanAnalyzer())->analyze($plan, $bucket, $level);
            if (! isset($a['rows'][$rowKey])) {
                return ToolResult::error('Zeile nicht gefunden.', 'ROW_NOT_FOUND');
            }
            $row = $a['rows'][$rowKey];

            $sum = 0.0;
            foreach ($a['columns'] as $c) {
                $sum += (float) ($row['cells'][$c['bucket']]['value'] ?? 0);
            }

            $points = [];
            $prev = null;
            foreach ($a['columns'] as $c) {
                $b = $c['bucket'];
                $v = (float) ($row['cells'][$b]['value'] ?? 0);
                $points[] = [
                    'bucket' => $b,
                    'label' => $c['label'],
                    'value' => $row['cells'][$b]['value'] ?? null,
                    'state' => $row['cells'][$b]['state'] ?? null,
                    'delta' => $prev === null ? null : round($v - $prev, 4),
                    'pct' => ($prev === null || (float) $prev === 0.0) ? null : round(($v - $prev) / abs($prev) * 100, 2),
                    'share' => $sum != 0.0 ? round($v / $sum * 100, 2) : null,
                ];
                $prev = $v;
            }

            return ToolResult::success([
                'plan' => ['uuid' => $plan->uuid, 'name' => $plan->name],
                'row' => ['key' => $rowKey, 'label' => $row['label'], 'unit' => $row['unit'], 'is_formula' => $row['is_formula']],
                'level' => $a['level'],
                'sum' => round($sum, 4),
                'points' => $points,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'series', 'trend'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
