<?php

namespace Platform\Forecast\Services;

use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Reconciliation\Mode;
use Platform\Forecast\Reconciliation\Node;
use Platform\Forecast\Reconciliation\TimeAxis;

/**
 * Übersetzt die gespeicherten (sparse) Zellen einer Planung in die reconciled
 * Sicht (Wert + Rest je Bucket) — über den Zeit-Motor. Reine Leselogik.
 */
final class PlanReconciler
{
    /**
     * Vollständige reconciled Sicht einer Planung (alle Zeilen, alle belegten Buckets).
     *
     * @return array{plan: array, rows: array<string, array>}
     */
    public function view(ForecastPlan $plan): array
    {
        $byRow = $this->entriesByRow($plan);

        $rows = [];
        foreach ($plan->resolvedRows() as $row) {
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

            $rows[$row->key] = [
                'label' => $row->label,
                'kind'  => $row->kind->value,
                'cells' => $cells,
            ];
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
        ];
    }

    /**
     * Reconciled Wert + Rest einer einzelnen (row, bucket)-Zelle.
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
