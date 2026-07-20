<?php

namespace Platform\Forecast\Services;

use Platform\Forecast\Enums\Direction;
use Platform\Forecast\Enums\RowKind;
use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Reconciliation\Mode;
use Platform\Forecast\Reconciliation\Node;
use Platform\Forecast\Reconciliation\TimeAxis;

/**
 * Systemseitiger Rechenweg einer Planung: reconciled Eingabe-Zeilen (Plus/Detail/
 * Rest über den Zeit-Motor) UND berechnete Formel-Zeilen (Aggregation über andere
 * Zeilen je Bucket). Diese Sicht nutzen UI, MCP und Exporte gleichermaßen.
 */
final class PlanReconciler
{
    /**
     * @return array{plan: array, rows: array<string, array>, rowInfo: array<string, array>}
     */
    public function view(ForecastPlan $plan): array
    {
        $byRow = $this->entriesByRow($plan);
        $resolved = $plan->resolvedRows();

        // Zeilen-Metainfo (Richtung, Einheit, Formel)
        $rowInfo = [];
        foreach ($resolved as $row) {
            $cfg = $row->config ?? [];
            $isFormula = ($row->kind === RowKind::Formula);
            $agg = $cfg['agg'] ?? 'sum';
            $dir = $row->direction instanceof Direction ? $row->direction->value : ($row->direction ?? 'neutral');
            $rowInfo[$row->key] = [
                'isFormula' => $isFormula,
                'direction' => $dir,
                'unit' => $row->unit?->symbol,
                'agg' => $agg,
                'sources' => $cfg['sources'] ?? [],
                'signMode' => Aggregation::signMode($isFormula, $agg),
                'aggLabel' => $isFormula ? Aggregation::label($agg) : null,
            ];
        }

        // Eingabe-Zeilen reconcilen (Formel-Zeilen zunächst leer)
        $rows = [];
        foreach ($resolved as $row) {
            if ($rowInfo[$row->key]['isFormula']) {
                $rows[$row->key] = ['label' => $row->label, 'kind' => $row->kind->value, 'cells' => []];
                continue;
            }

            $rowEntries = $byRow[$row->key] ?? [];
            $nodes = TimeAxis::build($rowEntries);
            $enteredMode = [];
            foreach ($rowEntries as $re) {
                $enteredMode[$re['key']] = $re['mode'];
            }

            $cells = [];
            foreach ($nodes as $key => $node) {
                $cells[$key] = [
                    'level'   => TimeLevel::fromKey($key)->value,
                    'entered' => isset($enteredMode[$key]),
                    'mode'    => isset($enteredMode[$key]) ? $enteredMode[$key]->value : null,
                    'value'   => round($node->value(), 4),
                    'rest'    => round($node->rest(), 4),
                ];
            }
            ksort($cells);
            $rows[$row->key] = ['label' => $row->label, 'kind' => $row->kind->value, 'cells' => $cells];
        }

        // Formel-Zeilen berechnen (in Reihenfolge; Formel-auf-Formel möglich)
        foreach ($resolved as $row) {
            if (! $rowInfo[$row->key]['isFormula']) {
                continue;
            }
            $sources = $rowInfo[$row->key]['sources'];
            $agg = $rowInfo[$row->key]['agg'];
            $dirs = array_map(fn ($s) => $rowInfo[$s]['direction'] ?? 'neutral', $sources);

            // Vereinigung der Buckets aller Quellen
            $buckets = [];
            foreach ($sources as $s) {
                foreach (array_keys($rows[$s]['cells'] ?? []) as $b) {
                    $buckets[$b] = true;
                }
            }

            $cells = [];
            foreach (array_keys($buckets) as $b) {
                $vals = array_map(fn ($s) => $rows[$s]['cells'][$b]['value'] ?? 0.0, $sources);
                $cells[$b] = [
                    'level'   => TimeLevel::fromKey($b)->value,
                    'entered' => false,
                    'mode'    => null,
                    'value'   => round(Aggregation::aggregate($agg, $vals, $dirs), 4),
                    'rest'    => 0.0,
                    'derived' => true,
                ];
            }
            ksort($cells);
            $rows[$row->key]['cells'] = $cells;
        }

        return [
            'plan' => [
                'uuid'                   => $plan->uuid,
                'name'                   => $plan->name,
                'version'                => $plan->current_version,
                'organization_entity_id' => $plan->organization_entity_id,
                'org_mode'               => $plan->org_mode?->value,
            ],
            'rows' => $rows,
            'rowInfo' => $rowInfo,
        ];
    }

    /**
     * Reconciled Wert + Rest einer einzelnen (row, bucket)-Zelle (Eingabe-Zeilen).
     *
     * @return array{value: float, rest: float}
     */
    public function cell(ForecastPlan $plan, string $rowKey, string $bucketKey): array
    {
        $entries = $this->entriesByRow($plan)[$rowKey] ?? [];
        $nodes = TimeAxis::build($entries);
        $node = $nodes[$bucketKey] ?? new Node($bucketKey);

        return ['value' => round($node->value(), 4), 'rest' => round($node->rest(), 4)];
    }

    /**
     * @return array<string, list<array{key:string, value:float, mode:Mode}>>
     */
    private function entriesByRow(ForecastPlan $plan): array
    {
        $byRow = [];
        foreach ($plan->entries()->get() as $e) {
            $byRow[$e->row_key][] = [
                'key'   => $e->bucket_key,
                'value' => (float) $e->value,
                'mode'  => $e->mode instanceof Mode ? $e->mode : Mode::from((string) $e->mode),
            ];
        }
        return $byRow;
    }
}
