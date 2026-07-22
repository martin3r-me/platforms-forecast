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
        $rowByKey = [];
        foreach ($resolved as $r) {
            $rowByKey[$r->key] = $r;
        }

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
                'timeAgg' => $row->config['time_agg'] ?? (($isFormula && $agg === 'cumulative') ? 'stock' : 'flow'),
                'agg' => $agg,
                'expr' => $isFormula ? ($row->config['expr'] ?? null) : null,
                'sources' => $samePlanKeys,
                'refPlans' => $refPlans,
                'sourceCount' => count($samePlanKeys) + count($refPlans),
                'signMode' => Aggregation::signMode($isFormula, $agg),
                'aggLabel' => $isFormula ? (! empty($row->config['expr']) ? '= Ausdruck' : Aggregation::label($agg)) : null,
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

        // Effektiver Faktor: eine nicht-additive Eingabe-Zeile (Faktor), die in ein Produkt
        // fließt (Umsatz = Gesamt × Anteil), zeigt am Ordner ihren effektiven Wert = Produkt ÷ Basis.
        $effectiveOf = [];
        foreach ($resolved as $row) {
            $k = $row->key;
            if (($rowInfo[$k]['isFormula'] ?? false) || ! ($rowInfo[$k]['nonAdditive'] ?? false)) {
                continue;
            }
            foreach ($resolved as $prow) {
                $pk = $prow->key;
                if (($rowInfo[$pk]['isFormula'] ?? false) && ($rowInfo[$pk]['agg'] ?? '') === 'product') {
                    $srcs = $rowInfo[$pk]['sources'] ?? [];
                    if (count($srcs) === 2 && in_array($k, $srcs, true)) {
                        $effectiveOf[$k] = ['product' => $pk, 'base' => ($srcs[0] === $k ? $srcs[1] : $srcs[0])];
                        $rowInfo[$k]['hasEffective'] = true;
                        break;
                    }
                }
            }
        }

        // Eingabe-Zeilen reconcilen
        $rows = [];
        foreach ($resolved as $row) {
            if ($rowInfo[$row->key]['isFormula']) {
                $rows[$row->key] = ['label' => $row->label, 'kind' => $row->kind->value, 'cells' => []];
                continue;
            }

            $rowEntries = $byRow[$row->key] ?? [];
            $nodes = TimeAxis::build($rowEntries, $rowInfo[$row->key]['timeAgg'] ?? 'flow');
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

        // Formel-/Verweis-Zeilen berechnen — in TOPOLOGISCHER Reihenfolge (Quellen vor Verbrauchern),
        // damit Formeln/Ausdrücke beliebige Zeilen referenzieren dürfen; Zyklen werden erkannt & gemeldet.
        $formulaOrder = $this->orderFormulas($rowInfo, array_fill_keys(array_keys($rowInfo), true));
        foreach ($formulaOrder as $rk) {
            $row = $rowByKey[$rk];
            // Am Ordner: nicht neu-rechenbare Formeln sind schon per Kind-Summe konsolidiert.
            if ($hasChildren && ($nonRecomputable[$row->key] ?? false)) {
                continue;
            }

            // Ausdruck-Zeile (config.expr): freie Formel über [row.key] je Bucket auswerten.
            $expr = $rowInfo[$row->key]['expr'] ?? null;
            if ($expr !== null && $expr !== '') {
                $rows[$row->key]['cells'] = $this->expressionCells((string) $expr, $rows, $rowInfo[$row->key]['warnings'], $rowInfo[$row->key]['timeAgg'] ?? 'flow');

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

            // Fortschreibung/Roll-Forward: laufende Summe über die Zeit — Schluss[t] = Schluss[t-1] + Netto-Fluss[t].
            // Der Anfangsbestand ist einfach eine +Quelle, die am ersten Teilzeitraum erfasst wird.
            if ($agg === 'cumulative') {
                $rows[$row->key]['cells'] = $this->cumulativeCells($sources);
                continue;
            }

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

        // Am Ordner: leere Faktor-Zeilen mit ihrem EFFEKTIVEN Wert füllen (Produkt ÷ Basis
        // aus den konsolidierten Zahlen) — statt leer, weil der eingegebene Faktor nicht additiv ist.
        if ($hasChildren) {
            foreach ($effectiveOf as $fk => $rel) {
                $pCells = $rows[$rel['product']]['cells'] ?? [];
                $bCells = $rows[$rel['base']]['cells'] ?? [];
                $cells = [];
                foreach ($pCells as $b => $pc) {
                    $bv = $bCells[$b]['value'] ?? 0;
                    if ($bv != 0) {
                        $cells[$b] = [
                            'level' => $pc['level'], 'entered' => false, 'mode' => null,
                            'value' => round($pc['value'] / $bv, 6), 'rest' => 0.0, 'derived' => true, 'effective' => true,
                        ];
                    }
                }
                ksort($cells);
                $rows[$fk]['cells'] = $cells;
            }
        }

        // Plan-Gesamtwert je Zeile (für die Wurzel-Übersicht). ZWEI Pässe, damit Formeln,
        // die auf später definierte Eingaben verweisen (z. B. Kategorie = Gesamt × Anteil,
        // wobei „Gesamt" weiter unten steht), korrekte Quell-Totals sehen:
        //   Pass 1: Eingabe-Zeilen = Σ Jahres-Zellen.
        //   Pass 2: Formel-Zeilen (Reihenfolge) = Aggregation über Quell-Totals (Ratio/Cross-Plan korrekt),
        //           bzw. am Ordner nicht-neu-rechenbar = Σ Jahres-Zellen.
        $totals = [];
        $sumYearCells = function (string $k) use ($rows): float {
            $sum = 0.0;
            foreach ($rows[$k]['cells'] as $c) {
                if (($c['level'] ?? '') === 'year') {
                    $sum += $c['value'];
                }
            }
            return round($sum, 4);
        };
        foreach ($resolved as $row) {
            if (! $rowInfo[$row->key]['isFormula']) {
                $totals[$row->key] = $sumYearCells($row->key);
            }
        }
        foreach ($formulaOrder as $k) {
            $row = $rowByKey[$k];
            if (! empty($rowInfo[$k]['expr'])) {
                // Ausdruck: Jahres-Total = Ausdruck auf Jahres-Ebene (bereits als Jahr-Zelle berechnet).
                $cc = $rows[$k]['cells'] ?? [];
                $years = array_filter($cc, fn ($c) => ($c['level'] ?? '') === 'year');
                $totals[$k] = $years ? round(reset($years)['value'], 4) : 0.0;

                continue;
            }
            if ($rowInfo[$k]['agg'] === 'cumulative') {
                // Fortschreibung: Jahres-Total = jüngster Schluss (nicht Aggregation der Quell-Totals).
                $cc = $rows[$k]['cells'] ?? [];
                $years = array_filter($cc, fn ($c) => ($c['level'] ?? '') === 'year');
                $pick = $years ?: $cc;
                $totals[$k] = $pick ? round(end($pick)['value'], 4) : 0.0;
                continue;
            }
            if ($hasChildren && ($nonRecomputable[$k] ?? false)) {
                $totals[$k] = $sumYearCells($k);
                continue;
            }
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
        }

        return ['plan' => $this->planMeta($plan), 'rows' => $rows, 'rowInfo' => $rowInfo, 'totals' => $totals];
    }

    /**
     * Fortschreibung (Roll-Forward): laufende Summe der signierten Quell-Flüsse über die Zeit.
     * Rechnet auf der FEINSTEN vorhandenen Ebene (Netto je Periode → kumulieren) und projiziert
     * den Schluss nach oben (jeder Elternknoten = Schluss seiner jüngsten feinen Periode = Stock).
     *
     * @param  list<array{cells: array, dir: string, weight: float}>  $sources
     * @return array<string, array>
     */
    private function cumulativeCells(array $sources): array
    {
        $rank = ['year' => 0, 'quarter' => 1, 'month' => 2, 'day' => 3, 'hour' => 4];

        // 1) feinste vorhandene Ebene bestimmen
        $finest = -1;
        foreach ($sources as $s) {
            foreach (array_keys($s['cells']) as $b) {
                $r = $rank[TimeLevel::fromKey($b)->value] ?? 2;
                $finest = max($finest, $r);
            }
        }
        if ($finest < 0) {
            return [];
        }
        $finestLevel = array_search($finest, $rank, true);

        // 2) Netto-Fluss je feiner Periode (Zufluss +, Abfluss −, neutral 0)
        $delta = [];
        foreach ($sources as $s) {
            $sign = $s['dir'] === 'expense' ? -1.0 : ($s['dir'] === 'neutral' ? 0.0 : 1.0);
            foreach ($s['cells'] as $b => $c) {
                if (TimeLevel::fromKey($b)->value !== $finestLevel) {
                    continue;
                }
                $delta[$b] = ($delta[$b] ?? 0.0) + $sign * $s['weight'] * ($c['value'] ?? 0.0);
            }
        }
        ksort($delta); // chronologisch

        // 3) kumulieren + Schluss nach oben projizieren (aufsteigend ⇒ jüngster gewinnt am Elternknoten)
        $cells = [];
        $running = 0.0;
        foreach ($delta as $b => $d) {
            $running += $d;
            $val = round($running, 4);
            $k = $b;
            do {
                $cells[$k] = [
                    'level' => TimeLevel::fromKey($k)->value,
                    'entered' => false, 'mode' => null,
                    'value' => $val, 'rest' => 0.0, 'derived' => true,
                ];
                $k = TimeAxis::parentKey($k);
            } while ($k !== null);
        }
        ksort($cells);

        return $cells;
    }

    /**
     * Ausdruck-Zeile: config.expr je Bucket auswerten. Referenzen [row.key] lösen auf den
     * Wert dieser Zeile im selben Bucket auf. Buckets = Vereinigung der referenzierten Zeilen.
     *
     * @param  array<string, array>  $rows
     * @param  list<string>  $warnings  per Referenz — Parse-Fehler wird hier vermerkt
     * @return array<string, array>
     */
    /**
     * Formel-Zeilen topologisch ordnen: Quellen vor Verbrauchern (agg-Quellen UND Ausdruck-Refs).
     * Zyklen werden erkannt und als Warnung an der zyklus-schließenden Zeile vermerkt; die Reihenfolge
     * bleibt best-effort (der Zyklus wird aufgebrochen). Nur selbe-Plan-Referenzen zählen für die Ordnung.
     *
     * @param  array<string,array>  $rowInfo  warnings werden ggf. ergänzt (per Referenz)
     * @param  array<string,bool>   $allKeys  alle existierenden Zeilen-Keys
     * @return list<string>  geordnete Formel-Keys
     */
    private function orderFormulas(array &$rowInfo, array $allKeys): array
    {
        $isFormula = [];
        $deps = [];
        foreach ($rowInfo as $k => $info) {
            if (! ($info['isFormula'] ?? false)) {
                continue;
            }
            $isFormula[$k] = true;
            $d = $info['sources'] ?? [];
            if (! empty($info['expr'])) {
                try {
                    $d = array_merge($d, ExpressionEvaluator::compile((string) $info['expr'])['refs']);
                } catch (\Throwable $e) {
                    // ungültiger Ausdruck: expressionCells meldet den Fehler separat
                }
            }
            $deps[$k] = array_values(array_unique(array_filter($d, fn ($x) => isset($allKeys[$x]))));
        }

        $ordered = [];
        $state = []; // unset/0 = neu, 1 = im Pfad (aktiv), 2 = fertig
        $visit = function (string $k) use (&$visit, &$deps, &$isFormula, &$ordered, &$state, &$rowInfo): void {
            $s = $state[$k] ?? 0;
            if ($s === 2) {
                return;
            }
            if ($s === 1) {
                $rowInfo[$k]['warnings'][] = 'Zirkelbezug erkannt — Formel hängt (indirekt) von sich selbst ab; Ergebnis unzuverlässig.';

                return;
            }
            $state[$k] = 1;
            foreach ($deps[$k] ?? [] as $dep) {
                if (isset($isFormula[$dep])) {
                    $visit($dep);
                }
            }
            $state[$k] = 2;
            $ordered[] = $k;
        };
        foreach (array_keys($isFormula) as $k) {
            $visit($k);
        }

        return $ordered;
    }

    private function expressionCells(string $expr, array $rows, array &$warnings, string $timeAgg): array
    {
        try {
            $compiled = ExpressionEvaluator::compile($expr);
        } catch (\Throwable $e) {
            $warnings[] = 'Ausdruck ungültig: '.$e->getMessage();

            return [];
        }

        $allBuckets = [];
        foreach ($compiled['refs'] as $rk) {
            foreach (array_keys($rows[$rk]['cells'] ?? []) as $b) {
                $allBuckets[$b] = true;
            }
        }
        if ($allBuckets === []) {
            return [];
        }

        $mk = fn (string $b, float $v): array => [
            'level' => TimeLevel::fromKey($b)->value, 'entered' => false, 'mode' => null,
            'value' => round($v, 4), 'rest' => 0.0, 'derived' => true,
        ];
        $evalAt = fn (string $b): float => ExpressionEvaluator::evaluate(
            $compiled,
            fn (string $key): float => (float) ($rows[$key]['cells'][$b]['value'] ?? 0.0)
        );

        // 'recompute': Ausdruck auf JEDER Ebene neu rechnen (Quoten/Margen: Jahres-Marge = Jahr-DB ÷ Jahr-Umsatz).
        if ($timeAgg === 'recompute') {
            $cells = [];
            foreach (array_keys($allBuckets) as $b) {
                $cells[$b] = $mk($b, $evalAt($b));
            }
            ksort($cells);

            return $cells;
        }

        // sonst: NUR auf der feinsten Ebene rechnen, dann direkt hochrollen (flow=Σ · avg=Ø · stock=Schluss).
        // Direkt (nicht über Node), damit negative Werte nicht auf 0 geklemmt werden.
        $rank = ['year' => 0, 'quarter' => 1, 'month' => 2, 'day' => 3, 'hour' => 4];
        $finest = -1;
        foreach (array_keys($allBuckets) as $b) {
            $finest = max($finest, $rank[TimeLevel::fromKey($b)->value] ?? 2);
        }
        $finestLevel = array_search($finest, $rank, true);

        $fine = [];
        foreach (array_keys($allBuckets) as $b) {
            if (TimeLevel::fromKey($b)->value === $finestLevel) {
                $fine[$b] = $evalAt($b);
            }
        }
        ksort($fine); // chronologisch

        $cells = [];
        $byAnc = [];
        foreach ($fine as $b => $v) {
            $cells[$b] = $mk($b, $v);
            $p = TimeAxis::parentKey($b);
            while ($p !== null) {
                $byAnc[$p][] = $v; // aufsteigend ⇒ end()=Schluss, reset()=Eröffnung
                $p = TimeAxis::parentKey($p);
            }
        }
        foreach ($byAnc as $anc => $vals) {
            $agg = match ($timeAgg) {
                'stock' => (float) end($vals),
                'stock_open' => (float) reset($vals),
                'avg' => array_sum($vals) / count($vals),
                default => array_sum($vals), // flow = Σ
            };
            $cells[$anc] = $mk($anc, $agg);
        }
        ksort($cells);

        return $cells;
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
