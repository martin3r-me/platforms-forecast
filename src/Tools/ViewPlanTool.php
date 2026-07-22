<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\NumberRounding;
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
            .'rows (Liste row_keys zum Filtern), round (Nachkommastellen für board-fertige, AUFGEHENDE Zahlen '
            .'— bei Fluss-Zeilen so gerundet, dass Σ der Spalten = gerundete Summe, Largest-Remainder).';
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
                'round' => ['type' => 'integer', 'description' => 'Auf N Nachkommastellen runden, aufgehend (Σ Spalten = gerundete Summe). Weglassen = exakt.'],
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

            if (array_key_exists('round', $arguments) && is_numeric($arguments['round'])) {
                $a = $this->applyRounding($a, (int) $arguments['round']);
            }

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

    /**
     * Rundet Zellen + Totals auf $decimals Stellen. Fluss-Zeilen (time_agg=flow, additiv) werden
     * mit Largest-Remainder gerundet, damit die Spalten zur gerundeten Summe aufgehen; alle
     * anderen (Bestände, Quoten, Ø) je Zelle unabhängig gerundet.
     */
    private function applyRounding(array $a, int $decimals): array
    {
        $buckets = array_map(fn ($c) => $c['bucket'], $a['columns']);
        foreach ($a['rows'] as &$row) {
            $foots = ($row['time_agg'] ?? 'flow') === 'flow' && ! ($row['non_additive'] ?? false);
            if ($foots) {
                $idx = [];
                $vals = [];
                foreach ($buckets as $b) {
                    if (isset($row['cells'][$b]) && $row['cells'][$b]['value'] !== null) {
                        $idx[] = $b;
                        $vals[] = (float) $row['cells'][$b]['value'];
                    }
                }
                $rounded = NumberRounding::largestRemainder($vals, $decimals);
                foreach ($idx as $i => $b) {
                    $row['cells'][$b]['value'] = $rounded[$i];
                }
            } else {
                foreach ($buckets as $b) {
                    if (isset($row['cells'][$b]) && $row['cells'][$b]['value'] !== null) {
                        $row['cells'][$b]['value'] = round((float) $row['cells'][$b]['value'], $decimals);
                    }
                }
            }
            if (($row['total'] ?? null) !== null) {
                $row['total'] = round((float) $row['total'], $decimals);
            }
        }
        unset($row);

        return $a;
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'view', 'read'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
