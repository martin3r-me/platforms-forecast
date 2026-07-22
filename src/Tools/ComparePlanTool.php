<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Services\PlanAnalyzer;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ComparePlanTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.compare.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/compare – Vergleicht Zahlen. mode=plan: Plan gegen einen anderen (against uuid), '
            .'Totals je Zeile + Δ. mode=children: ein Ordner gegen seine Kind-Planungen (Aufschlüsselung je Zeile). '
            .'mode=time: innerhalb des Plans jede Periode gegen die Vorperiode (Δ) auf der gewählten level. '
            .'Parameter: plan (uuid), mode (plan|children|time), against (uuid, nur mode=plan), '
            .'level (für mode=time, Default month), rows (row_keys filtern).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'mode' => ['type' => 'string', 'enum' => ['plan', 'children', 'time']],
                'against' => ['type' => 'string', 'description' => 'Vergleichs-Plan-uuid (mode=plan).'],
                'level' => ['type' => 'string', 'enum' => ['year', 'quarter', 'month', 'day'], 'description' => 'mode=time.'],
                'rows' => ['type' => 'array', 'items' => ['type' => 'string']],
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
            $mode = (string) ($arguments['mode'] ?? 'time');
            $analyzer = new PlanAnalyzer();
            $rowsFilter = ! empty($arguments['rows']) && is_array($arguments['rows']) ? array_flip(array_map('strval', $arguments['rows'])) : null;
            $delta = fn ($a, $b) => ['a' => $a, 'b' => $b, 'abs' => round((float) $a - (float) $b, 4), 'pct' => ((float) $b != 0.0) ? round(((float) $a - (float) $b) / abs((float) $b) * 100, 2) : null];

            if ($mode === 'plan') {
                $other = $this->findPlan((string) ($arguments['against'] ?? ''), $teamId);
                if (! $other) {
                    return ToolResult::error('Vergleichs-Plan (against) nicht gefunden.', 'AGAINST_NOT_FOUND');
                }
                $ra = $analyzer->analyze($plan)['rows'];
                $rb = $analyzer->analyze($other)['rows'];
                $out = [];
                foreach ($ra as $key => $row) {
                    if ($rowsFilter && ! isset($rowsFilter[$key])) {
                        continue;
                    }
                    $out[] = ['row' => $key, 'label' => $row['label'], 'unit' => $row['unit']] + $delta($row['total'], $rb[$key]['total'] ?? 0);
                }

                return ToolResult::success([
                    'mode' => 'plan',
                    'a' => ['uuid' => $plan->uuid, 'name' => $plan->name],
                    'b' => ['uuid' => $other->uuid, 'name' => $other->name],
                    'rows' => $out,
                ]);
            }

            if ($mode === 'children') {
                $children = ForecastPlan::where('parent_plan_id', $plan->id)->get();
                if ($children->isEmpty()) {
                    return ToolResult::error('Planung hat keine Kind-Planungen.', 'NO_CHILDREN');
                }
                $parentRows = $analyzer->analyze($plan)['rows'];
                $childTotals = [];
                foreach ($children as $ch) {
                    $childTotals[$ch->uuid] = ['name' => $ch->name, 'rows' => $analyzer->analyze($ch)['rows']];
                }
                $out = [];
                foreach ($parentRows as $key => $row) {
                    if ($rowsFilter && ! isset($rowsFilter[$key])) {
                        continue;
                    }
                    $parts = [];
                    foreach ($childTotals as $uuid => $ct) {
                        $parts[] = ['uuid' => $uuid, 'name' => $ct['name'], 'total' => $ct['rows'][$key]['total'] ?? null];
                    }
                    $out[] = ['row' => $key, 'label' => $row['label'], 'unit' => $row['unit'], 'consolidated' => $row['total'], 'children' => $parts];
                }

                return ToolResult::success([
                    'mode' => 'children',
                    'parent' => ['uuid' => $plan->uuid, 'name' => $plan->name],
                    'rows' => $out,
                ]);
            }

            // mode = time: Periode vs. Vorperiode
            $level = ! empty($arguments['level']) ? (string) $arguments['level'] : 'month';
            $a = $analyzer->analyze($plan, '', $level);
            $buckets = array_map(fn ($c) => $c['bucket'], $a['columns']);
            $labels = [];
            foreach ($a['columns'] as $c) {
                $labels[$c['bucket']] = $c['label'];
            }
            $out = [];
            foreach ($a['rows'] as $key => $row) {
                if ($rowsFilter && ! isset($rowsFilter[$key])) {
                    continue;
                }
                $series = [];
                $prev = null;
                foreach ($buckets as $b) {
                    $v = $row['cells'][$b]['value'] ?? 0;
                    $series[] = [
                        'bucket' => $b, 'label' => $labels[$b] ?? $b, 'value' => $v,
                        'delta' => $prev === null ? null : $delta($v, $prev)['abs'],
                        'pct' => $prev === null ? null : (((float) $prev != 0.0) ? round(((float) $v - (float) $prev) / abs((float) $prev) * 100, 2) : null),
                    ];
                    $prev = $v;
                }
                $out[] = ['row' => $key, 'label' => $row['label'], 'unit' => $row['unit'], 'series' => $series];
            }

            return ToolResult::success(['mode' => 'time', 'plan' => ['uuid' => $plan->uuid, 'name' => $plan->name], 'level' => $level, 'rows' => $out]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'compare', 'analysis'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
