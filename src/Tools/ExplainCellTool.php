<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanReconciler;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ExplainCellTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.explain.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/explain – Erklärt, wie eine Zahl zustande kommt. Für eine Formelzeile + Bucket '
            .'den Rechenbaum: Aggregation + speisende Zeilen mit ihren Werten (rekursiv, depth-begrenzt). Für eine '
            .'Eingabezeile: der Wert + die zugrunde liegenden Einträge. Parameter: plan (uuid), row (row_key), '
            .'bucket (z. B. "2026", "2026-07"), depth (Formel-Tiefe, Default 2).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'row' => ['type' => 'string', 'description' => 'row_key der Zeile.'],
                'bucket' => ['type' => 'string', 'description' => 'Bucket (Jahr "2026", Monat "2026-07", …).'],
                'depth' => ['type' => 'integer', 'description' => 'Wie tief Formeln aufgeklappt werden (Default 2).'],
            ],
            'required' => ['plan', 'row', 'bucket'],
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
            $depth = (int) ($arguments['depth'] ?? 2);

            $view = (new PlanReconciler())->view($plan);
            if (! isset($view['rows'][$rowKey])) {
                return ToolResult::error('Zeile nicht gefunden.', 'ROW_NOT_FOUND');
            }

            // Quell-Struktur (mit Gewichten) aus den aufgelösten Zeilen.
            $srcMap = [];
            foreach ($plan->resolvedRows() as $r) {
                if ($r->kind->value === 'formula') {
                    $srcMap[$r->key] = array_map(fn ($s) => [
                        'key' => $s->source_row_key,
                        'plan_id' => $s->source_plan_id,
                        'weight' => (float) $s->weight,
                    ], $r->sources);
                }
            }

            $tree = $this->explain($rowKey, $bucket, $view, $srcMap, $depth);

            return ToolResult::success([
                'plan' => ['uuid' => $plan->uuid, 'name' => $plan->name],
                'bucket' => $bucket,
                'explanation' => $tree,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    private function explain(string $key, string $bucket, array $view, array $srcMap, int $depth): array
    {
        $info = $view['rowInfo'][$key] ?? [];
        $row = $view['rows'][$key] ?? [];
        $cell = $row['cells'][$bucket] ?? null;
        $node = [
            'row' => $key,
            'label' => $row['label'] ?? $key,
            'value' => $cell['value'] ?? null,
            'unit' => $info['unit'] ?? null,
            'kind' => ($info['isFormula'] ?? false) ? 'formula' : 'input',
        ];

        if (! ($info['isFormula'] ?? false)) {
            $node['origin'] = ($cell['derived'] ?? false) ? 'abgeleitet (Kinder/Detail)' : (($cell['entered'] ?? false) ? 'eingegeben' : 'leer/verteilt');
            $node['entered'] = (bool) ($cell['entered'] ?? false);

            return $node;
        }

        $node['agg'] = $info['agg'] ?? null;
        $node['agg_label'] = $info['aggLabel'] ?? null;
        $operands = [];
        foreach (($srcMap[$key] ?? []) as $s) {
            if ($s['plan_id'] !== null) {
                $operands[] = ['row' => $s['key'], 'weight' => $s['weight'], 'note' => 'aus anderem Plan (#'.$s['plan_id'].') — via forecast.view.GET dort auflösen'];

                continue;
            }
            if ($depth > 1 && ($view['rowInfo'][$s['key']]['isFormula'] ?? false)) {
                $sub = $this->explain($s['key'], $bucket, $view, $srcMap, $depth - 1);
                $sub['weight'] = $s['weight'];
                $operands[] = $sub;
            } else {
                $sc = $view['rows'][$s['key']]['cells'][$bucket] ?? null;
                $operands[] = [
                    'row' => $s['key'],
                    'label' => $view['rows'][$s['key']]['label'] ?? $s['key'],
                    'value' => $sc['value'] ?? null,
                    'direction' => $view['rowInfo'][$s['key']]['direction'] ?? null,
                    'weight' => $s['weight'],
                ];
            }
        }
        $node['operands'] = $operands;

        return $node;
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'explain', 'analysis'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
