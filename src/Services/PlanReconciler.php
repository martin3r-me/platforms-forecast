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
 * Rest über den Zeit-Motor) UND berechnete Formel-/Verweis-Zeilen — inkl. Quellen
 * aus ANDEREN Planungen (Konsolidierung / Drill-down). Genutzt von UI, MCP, Exporten.
 */
final class PlanReconciler
{
    /**
     * @return array{plan: array, rows: array<string, array>, rowInfo: array<string, array>}
     */
    public function view(ForecastPlan $plan): array
    {
        return $this->compute($plan, []);
    }

    /**
     * @param  list<int>  $visiting  Zyklus-Schutz (Plan-IDs im aktuellen Pfad)
     */
    private function compute(ForecastPlan $plan, array $visiting): array
    {
        if (in_array($plan->id, $visiting, true)) {
            return ['plan' => $this->planMeta($plan), 'rows' => [], 'rowInfo' => []];
        }
        $visiting[] = $plan->id;

        $byRow = $this->entriesByRow($plan);
        $resolved = $plan->resolvedRows();

        // Zeilen-Metainfo + Quell-Referenzen sammeln
        $rowInfo = [];
        $refPlanIds = [];
        foreach ($resolved as $row) {
            $isFormula = ($row->kind === RowKind::Formula);
            $agg = $row->aggFn();
            $dir = $row->direction instanceof Direction ? $row->direction->value : ($row->direction ?? 'neutral');

            $samePlanKeys = [];
            $refPlans = [];
            if ($isFormula) {
                foreach ($row->sources as $src) {
                    if ($src->source_plan_id === null) {
                        $samePlanKeys[] = $src->source_row_key;
                    } else {
                        $refPlanIds[$src->source_plan_id] = true;
                        $refPlans[] = ['plan_id' => (int) $src->source_plan_id, 'row_key' => $src->source_row_key];
                    }
                }
            }

            $rowInfo[$row->key] = [
                'isFormula' => $isFormula,
                'direction' => $dir,
                'unit' => $row->unit?->symbol,
                'unitCode' => $row->unit?->code,
                'isFactor' => $row->unit?->code === 'FAKTOR',
                'nonAdditive' => $row->unit?->dimension === 'ratio',
                'section' => $row->config['section'] ?? null,
                'quoteBasis' => $row->config['quote_basis'] ?? null,
                'agg' => $agg,
                'sources' => $samePlanKeys,
                'refPlans' => $refPlans,
                'sourceCount' => count($samePlanKeys) + count($refPlans),
                'signMode' => Aggregation::signMode($isFormula, $agg),
                'aggLabel' => $isFormula ? Aggregation::label($agg) : null,
                'warnings' => [],
            ];
        }

        // „Nicht neu-rechenbar" je Formel-Zeile: hängt (transitiv) an einer nicht-additiven
        // Quelle (Faktor/Quote). Am Ordner werden solche Zeilen NICHT neu gerechnet, sondern
        // aus den Kind-Werten summiert (mit leerem Faktor käme sonst Unsinn heraus).
        $nonRecomputable = [];
        foreach ($resolved as $row) {
            $k = $row->key;
            if (! $rowInfo[$k]['isFormula']) {
                $nonRecomputable[$k] = false;
                continue;
            }
            $nr = false;
            foreach ($rowInfo[$k]['sources'] as $s) {
                if (($rowInfo[$s]['nonAdditive'] ?? false) || ($nonRecomputable[$s] ?? false)) {
                    $nr = true;
                    break;
                }
            }
            $nonRecomputable[$k] = $nr;
        }

        // Eingabe-Zeilen reconcilen
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

        // Konsolidierung: Eingabe-Zeilen der Kind-Instanzen aufsummieren
        // (Formel-Zeilen werden danach auf den konsolidierten Eingaben neu gerechnet).
        $children = ForecastPlan::where('parent_plan_id', $plan->id)->get();
        $hasChildren = $children->isNotEmpty();
        foreach ($children as $child) {
            $cv = $this->compute($child, $visiting);
            foreach ($resolved as $row) {
                // ratio-Zeilen (Faktoren/Quoten) sind NICHT additiv (0,3 + 0,3 ≠ 0,6) → nie summieren.
                // Formel-Zeilen werden normal neu gerechnet — AUSSER sie sind nicht neu-rechenbar
                // (hängen an einem Faktor): die werden hier wie Eingaben aus den Kindern summiert.
                $info = $rowInfo[$row->key];
                if (($info['nonAdditive'] ?? false) || ($info['isFormula'] && ! ($nonRecomputable[$row->key] ?? false))) {
                    continue;
                }

                // Einheiten-/Richtungs-Check: inkompatibles Kind NICHT aufsummieren, warnen
                $ci = $cv['rowInfo'][$row->key] ?? null;
                if ($ci && ($ci['unit'] !== $rowInfo[$row->key]['unit'] || $ci['direction'] !== $rowInfo[$row->key]['direction'])) {
                    $rowInfo[$row->key]['warnings'][] = $ci['unit'] !== $rowInfo[$row->key]['unit']
                        ? "{$child->name}: Einheit ".($ci['unit'] ?? '—')." ≠ ".($rowInfo[$row->key]['unit'] ?? '—')
                        : "{$child->name}: Richtung {$ci['direction']} ≠ {$rowInfo[$row->key]['direction']}";
                    continue;
                }

                $cells = $rows[$row->key]['cells'];
                foreach (($cv['rows'][$row->key]['cells'] ?? []) as $b => $c) {
                    if (! isset($cells[$b])) {
                        $cells[$b] = ['level' => $c['level'], 'entered' => false, 'mode' => null, 'value' => 0.0, 'rest' => 0.0, 'derived' => true];
                    }
                    $cells[$b]['value'] = round($cells[$b]['value'] + $c['value'], 4);
                    $cells[$b]['rest'] = round($cells[$b]['rest'] + ($c['rest'] ?? 0), 4);
                }
                ksort($cells);
                $rows[$row->key]['cells'] = $cells;
            }
        }

        // Referenzierte Pläne rekursiv berechnen (mit Cache über $refViews)
        $refViews = [];
        foreach (array_keys($refPlanIds) as $pid) {
            $refPlan = ForecastPlan::find($pid);
            $refViews[$pid] = $refPlan ? $this->compute($refPlan, $visiting) : ['plan' => [], 'rows' => [], 'rowInfo' => []];
        }

        // rowInfo.refPlans mit uuid/name anreichern (für UI-Drill-down)
        foreach ($rowInfo as &$info) {
            foreach ($info['refPlans'] as &$rp) {
                $rv = $refViews[$rp['plan_id']] ?? null;
                $rp['uuid'] = $rv['plan']['uuid'] ?? null;
                $rp['name'] = $rv['plan']['name'] ?? ('Plan #'.$rp['plan_id']);
            }
            unset($rp);
        }
        unset($info);

        // Formel-/Verweis-Zeilen berechnen (Quellen: selbe Planung ODER anderer Plan)
        foreach ($resolved as $row) {
            if (! $rowInfo[$row->key]['isFormula']) {
                continue;
            }
            // Am Ordner: nicht neu-rechenbare Formeln sind schon per Kind-Summe konsolidiert.
            if ($hasChildren && ($nonRecomputable[$row->key] ?? false)) {
                continue;
            }

            $sources = [];
            foreach ($row->sources as $src) {
                if ($src->source_plan_id === null) {
                    $cells = $rows[$src->source_row_key]['cells'] ?? [];
                    $sdir = $rowInfo[$src->source_row_key]['direction'] ?? 'neutral';
                } else {
                    $rv = $refViews[$src->source_plan_id] ?? null;
                    $cells = $rv['rows'][$src->source_row_key]['cells'] ?? [];
                    $sdir = $rv['rowInfo'][$src->source_row_key]['direction'] ?? 'neutral';
                }
                $sources[] = ['cells' => $cells, 'dir' => $sdir, 'weight' => (float) $src->weight];
            }

            $buckets = [];
            foreach ($sources as $s) {
                foreach (array_keys($s['cells']) as $b) {
                    $buckets[$b] = true;
                }
            }

            $agg = $rowInfo[$row->key]['agg'];
            $cells = [];
            foreach (array_keys($buckets) as $b) {
                $vals = array_map(fn ($s) => ($s['cells'][$b]['value'] ?? 0.0) * $s['weight'], $sources);
                $dirs = array_map(fn ($s) => $s['dir'], $sources);
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

        // Plan-Gesamtwert je Zeile (für die Wurzel-Übersicht): Eingabe = Σ Jahres-Zellen;
        // Formel = Aggregation über die Gesamtwerte der Quellen (inkl. Cross-Plan, Ratio korrekt).
        $totals = [];
        foreach ($resolved as $row) {
            $k = $row->key;
            if ($rowInfo[$k]['isFormula'] && $hasChildren && ($nonRecomputable[$k] ?? false)) {
                // Am Ordner wie Eingabe konsolidiert → Gesamtwert = Σ Jahres-Zellen.
                $sum = 0.0;
                foreach ($rows[$k]['cells'] as $c) {
                    if (($c['level'] ?? '') === 'year') {
                        $sum += $c['value'];
                    }
                }
                $totals[$k] = round($sum, 4);
            } elseif ($rowInfo[$k]['isFormula']) {
                $vals = [];
                $dirs = [];
                foreach ($row->sources as $src) {
                    if ($src->source_plan_id === null) {
                        $t = $totals[$src->source_row_key] ?? 0.0;
                        $d = $rowInfo[$src->source_row_key]['direction'] ?? 'neutral';
                    } else {
                        $rv = $refViews[$src->source_plan_id] ?? null;
                        $t = $rv['totals'][$src->source_row_key] ?? 0.0;
                        $d = $rv['rowInfo'][$src->source_row_key]['direction'] ?? 'neutral';
                    }
                    $vals[] = $t * (float) $src->weight;
                    $dirs[] = $d;
                }
                $totals[$k] = round(Aggregation::aggregate($rowInfo[$k]['agg'], $vals, $dirs), 4);
            } else {
                $sum = 0.0;
                foreach ($rows[$k]['cells'] as $c) {
                    if (($c['level'] ?? '') === 'year') {
                        $sum += $c['value'];
                    }
                }
                $totals[$k] = round($sum, 4);
            }
        }

        return ['plan' => $this->planMeta($plan), 'rows' => $rows, 'rowInfo' => $rowInfo, 'totals' => $totals];
    }

    private function planMeta(ForecastPlan $plan): array
    {
        return [
            'uuid'                   => $plan->uuid,
            'name'                   => $plan->name,
            'version'                => $plan->current_version,
            'organization_entity_id' => $plan->organization_entity_id,
            'org_mode'               => $plan->org_mode?->value,
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
