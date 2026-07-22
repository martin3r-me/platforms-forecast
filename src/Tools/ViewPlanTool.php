<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanAnalyzer;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ViewPlanTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.view.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/view – Reconciled Sicht auf einer Zeit-Ebene. Jede Zelle trägt Wert + '
            .'Herkunft/Zustand: open (tippbar) · computed (ƒ, berechnet) · derived (↑, aus Kindern/Detail) · '
            .'locked (🔒, Periode zu). Parameter: plan (uuid), bucket (Container zum Reinzoomen, "" = Jahre) '
            .'ODER level (year|quarter|month|day – flach über die Plan-Jahre), summary (nur KPIs/Totals), '
            .'rows (Liste row_keys zum Filtern).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid.'],
                'bucket' => ['type' => 'string', 'description' => 'Container-Bucket (z. B. "2026", "2026-Q3", "2026-07"). "" = oberste Ebene (Jahre).'],
                'level' => ['type' => 'string', 'enum' => ['year', 'quarter', 'month', 'day'], 'description' => 'Flache Ebene über alle Plan-Jahre (Alternative zu bucket).'],
                'summary' => ['type' => 'boolean', 'description' => 'Nur Plan-Kopf + KPIs (erste 4 Zeilen) + Totals, ohne Zell-Matrix.'],
                'rows' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nur diese row_keys zurückgeben.'],
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

            $a = (new PlanAnalyzer())->analyze(
                $plan,
                (string) ($arguments['bucket'] ?? ''),
                ! empty($arguments['level']) ? (string) $arguments['level'] : null,
            );

            if (! empty($arguments['summary'])) {
                $kpi = array_slice(array_values($a['rows']), 0, 4);

                return ToolResult::success([
                    'plan' => $a['plan'], 'lock' => $a['lock'], 'level' => $a['level'],
                    'columns' => array_map(fn ($c) => ['bucket' => $c['bucket'], 'label' => $c['label'], 'state' => $c['state']], $a['columns']),
                    'kpis' => array_map(fn ($r) => ['key' => $r['key'], 'label' => $r['label'], 'unit' => $r['unit'], 'total' => $r['total']], $kpi),
                    'totals' => array_map(fn ($r) => ['key' => $r['key'], 'label' => $r['label'], 'total' => $r['total']], array_values($a['rows'])),
                ]);
            }

            if (! empty($arguments['rows']) && is_array($arguments['rows'])) {
                $keep = array_flip(array_map('strval', $arguments['rows']));
                $a['rows'] = array_intersect_key($a['rows'], $keep);
            }

            return ToolResult::success($a);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'view', 'read'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
