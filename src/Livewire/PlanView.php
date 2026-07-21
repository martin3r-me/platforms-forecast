<?php

namespace Platform\Forecast\Livewire;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Models\ForecastLockPolicy;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Models\ForecastRowSource;
use Platform\Forecast\Reconciliation\Mode;
use Platform\Forecast\Reconciliation\TimeAxis;
use Platform\Forecast\Services\Aggregation;
use Platform\Forecast\Services\LockService;
use Platform\Forecast\Services\PlanReconciler;

/**
 * Read-only Ansicht einer Planung: Zeilen × Zeit-Grid mit Zoom.
 * Klick auf eine Zeit-Spalte zoomt hinein (Jahr→Monat→Tag→Stunde),
 * Breadcrumb zoomt heraus. Je Zelle: Wert + Rest, Plus/Detail markiert.
 */
class PlanView extends Component
{
    public string $uuid;

    /** Aktuell aufgeklappter Container-Bucket ('' = Wurzel/Jahre). */
    public string $container = '';

    /** Herkunfts-Plan-uuid (für den "Zurück"-Link nach einem Drill-Klick). */
    public ?string $from = null;

    /** Anteils-Ansicht: zeigt je Summen-Block den prozentualen Anteil jeder Zeile. */
    public bool $showShare = false;

    /** Delta-Ansicht: Veränderung je Zelle zur vorherigen Spalte (absolut + %). */
    public bool $showDelta = false;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
        $this->from = request()->query('from');
        // Validierung + Team-Scope
        $this->plan();
    }

    public function zoom(string $bucket): void
    {
        $this->container = $bucket;
    }

    public function toggleShare(): void
    {
        $this->showShare = ! $this->showShare;
    }

    public function toggleDelta(): void
    {
        $this->showDelta = ! $this->showDelta;
    }

    protected function plan(): ForecastPlan
    {
        return ForecastPlan::where('team_id', Auth::user()->currentTeam->id)
            ->where('uuid', $this->uuid)
            ->firstOrFail();
    }

    public function render()
    {
        $plan = $this->plan();
        $view = (new PlanReconciler())->view($plan);
        $rows = $view['rows'];
        $rowInfo = $view['rowInfo'];
        $totals = $view['totals'] ?? [];

        // Master (konsolidiert Kind-Instanzen): Eingabe-Zeilen sind hier abgeleitet,
        // kein „offener Rest" — Rest-/Verbindlich-Anzeige wird für diese unterdrückt.
        $isMaster = $plan->children()->exists();
        $childCount = $isMaster ? $plan->children()->count() : 0;
        // Zähler-Aufschlüsselung: direkte Sub-Master + Blatt-Instanzen (unterste Ebene)
        $subMasterCount = 0;
        $leafCount = 0;

        $columns = $this->columns($plan);
        $level = $this->childLevel($this->container);
        $breadcrumb = $this->breadcrumb();

        // Roh-Eingaben je Zeile (für "verbindlich verplant"-Berechnung)
        $entriesByRow = [];
        foreach ($plan->entries()->get() as $e) {
            $entriesByRow[$e->row_key][] = [
                'key' => $e->bucket_key,
                'value' => (float) $e->value,
                'mode' => $e->mode instanceof Mode ? $e->mode : Mode::from((string) $e->mode),
            ];
        }

        // Pro Zeile: Container-Wert (Rest kaskadiert implizit durch leere Ebenen),
        // "verbindlich verplant" (committed = gepinnte Blatt-Werte + Plus), offener
        // Rest = Wert − committed, und die gleichmäßige Verteilung auf leere Spalten.
        $meta = [];
        foreach ($rows as $rk => $r) {
            if ($rowInfo[$rk]['isFormula']) {
                continue; // Formel-Meta unten
            }
            $cells = $r['cells'];
            $rowEntries = $entriesByRow[$rk] ?? [];

            $implied = false;
            $spread = 0.0;

            if ($this->container === '') {
                $value = $totals[$rk] ?? 0.0;   // Plan-Gesamtwert (systemseitig)
            } else {
                $reconciled = $cells[$this->container]['value'] ?? 0;
                if ($reconciled > 0) {
                    $value = $reconciled;
                } else {
                    $value = $this->impliedInto($plan, $cells, $this->container);
                    $implied = $value > 0;
                }

                $storedSum = 0.0;
                $empty = 0;
                foreach ($columns as $col) {
                    $cv = $cells[$col['bucket']]['value'] ?? 0;
                    if ($cv > 0) {
                        $storedSum += $cv;
                    } else {
                        $empty++;
                    }
                }
                $distribute = max(0, $value - $storedSum);
                $spread = ($empty > 0 && $distribute > 0) ? $distribute / $empty : 0.0;
            }

            $committed = $this->committedFor($rowEntries, $this->container);
            $rest = max(0, $value - $committed);

            // "verbindlich verplant" je sichtbarer Spalte (für konsistente Zell-Reste)
            $cellCommitted = [];
            foreach ($columns as $col) {
                $cellCommitted[$col['bucket']] = $this->committedFor($rowEntries, $col['bucket']);
            }

            $meta[$rk] = [
                'value' => $value,
                'committed' => $committed,
                'rest' => $rest,
                'implied' => $implied,
                'spread' => $spread,
                'cellCommitted' => $cellCommitted,
            ];
        }

        // Master: konsolidierte Eingabe-Zeilen voll „verbindlich" (kein offener Rest, keine ≈-Verteilung)
        if ($isMaster) {
            foreach ($meta as $rk => &$m) {
                $m['committed'] = $m['value'];
                $m['rest'] = 0.0;
                $m['spread'] = 0.0;
                $m['cellCommitted'] = [];
            }
            unset($m);
        }

        // Formel-Zeilen: systemseitig berechnete Werte (aus PlanReconciler); leere
        // Anzeige-Spalten über den verteilten Rest der Quellen (gleiche Aggregation).
        $formulaCells = [];
        foreach ($rows as $rk => $r) {
            if (! $rowInfo[$rk]['isFormula']) {
                continue;
            }
            $agg = $rowInfo[$rk]['agg'];
            $sources = $rowInfo[$rk]['sources'];
            $dirs = array_map(fn ($s) => $rowInfo[$s]['direction'] ?? 'neutral', $sources);

            $cells = [];
            foreach ($columns as $col) {
                $b = $col['bucket'];
                if (isset($r['cells'][$b])) {
                    $cells[$b] = $r['cells'][$b]['value'];                      // systemseitig reconciled
                } else {
                    $vals = array_map(fn ($s) => $meta[$s]['spread'] ?? 0.0, $sources);
                    $cells[$b] = Aggregation::aggregate($agg, $vals, $dirs);    // verteilter Rest
                }
            }
            $formulaCells[$rk] = $cells;

            if ($this->container === '') {
                $sv = $totals[$rk] ?? 0.0;                       // Plan-Gesamtwert
            } elseif (isset($r['cells'][$this->container])) {
                $sv = $r['cells'][$this->container]['value'];
            } else {
                $sv = Aggregation::aggregate($agg, array_map(fn ($s) => $meta[$s]['value'] ?? 0.0, $sources), $dirs);
            }
            $meta[$rk] = ['value' => $sv, 'committed' => $sv, 'rest' => 0.0, 'implied' => false, 'spread' => 0.0, 'cellCommitted' => []];
        }

        // Anteils-Verteilung: je Summen-Block der prozentuale Anteil jeder Mitglied-Zeile
        // pro Spalte (Zusammensetzung; bei einer Summe ergeben die Teile immer 100 %).
        $share = [];
        foreach ($rows as $sk => $r) {
            if (! ($rowInfo[$sk]['isFormula'] ?? false) || ($rowInfo[$sk]['agg'] ?? '') !== 'sum') {
                continue;
            }
            foreach ($columns as $col) {
                $b = $col['bucket'];
                $total = $formulaCells[$sk][$b] ?? ($r['cells'][$b]['value'] ?? 0);
                if ($total == 0) {
                    continue;
                }
                foreach ($rowInfo[$sk]['sources'] as $m) {
                    $mv = $rows[$m]['cells'][$b]['value'] ?? 0;
                    $share[$m][$b] = $mv / $total * 100;
                }
            }
        }

        // Delta zur Vorperiode: Veränderung je Zelle zur vorherigen Spalte (absolut + %)
        $colVal = function (string $rk, string $b) use ($rows, $rowInfo, $formulaCells, $meta) {
            if ($rowInfo[$rk]['isFormula'] ?? false) {
                return (float) ($formulaCells[$rk][$b] ?? 0);
            }
            $c = $rows[$rk]['cells'][$b] ?? null;
            if ($c && ($c['entered'] || $c['value'] != 0)) {
                return (float) $c['value'];
            }
            return (float) ($meta[$rk]['spread'] ?? 0);
        };
        $delta = [];
        foreach ($rows as $rk => $r) {
            $prev = null;
            foreach ($columns as $col) {
                $v = $colVal($rk, $col['bucket']);
                if ($prev !== null) {
                    $abs = $v - $prev;
                    $delta[$rk][$col['bucket']] = ['abs' => $abs, 'pct' => $prev != 0 ? $abs / abs($prev) * 100 : null];
                }
                $prev = $v;
            }
        }

        // Bezugsgrößen-Quote: Zeile mit quoteBasis zeigt „Betrag ÷ Referenz-Zeile" je Spalte.
        // Verallgemeinerung von „% Anteil" (Referenz frei wählbar, nicht nur Summen-Block).
        $quote = [];
        foreach ($rows as $rk => $r) {
            $basisKey = $rowInfo[$rk]['quoteBasis'] ?? null;
            if (! $basisKey || ! isset($rows[$basisKey])) {
                continue;
            }
            foreach ($columns as $col) {
                $b = $col['bucket'];
                $ref = $colVal($basisKey, $b);
                if ($ref != 0) {
                    $quote[$rk][$b] = $colVal($rk, $b) / $ref * 100;
                }
            }
        }

        // „Hat feineres Detail"-Marker je Zelle: existiert eine gespeicherte Zelle auf einer
        // feineren Ebene innerhalb dieser Spalte? (echtes Detail, nicht nur verteilter Rest)
        $timeDetail = [];
        foreach ($rows as $rk => $r) {
            $keys = array_keys($r['cells']);
            foreach ($columns as $col) {
                $b = $col['bucket'];
                $has = false;
                foreach ($keys as $k) {
                    if ($this->isDescendant($k, $b)) {
                        $has = true;
                        break;
                    }
                }
                $timeDetail[$rk][$b] = $has;
            }
        }

        // „Nur teilweise Detail"-Warnung je Formel-Zelle: auf einer feineren Ebene haben
        // manche Bestandteile eine Zelle, andere (nur Jahr erfasst) nicht → Kennzahl unvollständig.
        $partial = [];
        foreach ($rows as $rk => $r) {
            if (! ($rowInfo[$rk]['isFormula'] ?? false)) {
                continue;
            }
            $srcs = $rowInfo[$rk]['sources'] ?? [];
            if (count($srcs) < 2) {
                continue;
            }
            foreach ($columns as $col) {
                $b = $col['bucket'];
                if (TimeLevel::fromKey($b) === TimeLevel::Year) {
                    continue; // Jahr gilt als vollständig
                }
                $present = 0;
                $absent = 0;
                $srcPartial = false;
                foreach ($srcs as $s) {
                    if (isset($rows[$s]['cells'][$b]) && ($rows[$s]['cells'][$b]['value'] ?? 0) != 0) {
                        $present++;
                    } else {
                        $absent++;
                    }
                    // Propagation: ist eine (früher berechnete) Formel-Quelle selbst unvollständig?
                    if ($partial[$s][$b] ?? false) {
                        $srcPartial = true;
                    }
                }
                $partial[$rk][$b] = ($present > 0 && $absent > 0) || $srcPartial;
            }
        }

        // Zeit-Sperre aus entkoppelter Policy (Plan-Policy → Team-Default → Legacy → Code-Default)
        $policy = $plan->lockPolicy ?? ForecastLockPolicy::resolveDefault($plan->team_id);
        $lock = array_merge(
            ['period_level' => 'month', 'lead_days' => 40, 'grace_days' => 10],
            $policy ? $policy->toRule() : [],
            (array) ($plan->metadata['lock'] ?? []),
        );
        $lock['policy_name'] = $policy?->name;
        $now = now();
        $colStatus = [];
        foreach ($columns as $col) {
            $colStatus[$col['bucket']] = LockService::status($col['bucket'], $lock, $now);
        }

        // Navigation: Ahnen-Kette (Konsolidierung hoch), Kinder, Detail-Pläne, Herkunft
        $parentPlan = $plan->parentPlan;
        $childPlans = $plan->children()->orderBy('name')->get();
        $fromPlan = $this->from
            ? ForecastPlan::where('team_id', $plan->team_id)->where('uuid', $this->from)->first()
            : null;

        $ancestors = [];
        $p = $plan->parentPlan;
        while ($p) {
            $ancestors[] = $p;
            $p = $p->parentPlan;
        }
        $ancestors = array_reverse($ancestors); // Wurzel zuerst

        // Team-Pläne + Rollen: Master (hat Kinder) · Instanz (hat Elternplan) · Detail (wird referenziert) · Einzel
        $allPlans = ForecastPlan::where('team_id', $plan->team_id)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'parent_plan_id', 'plan_type_id']);
        $childrenByParent = $allPlans->groupBy('parent_plan_id');
        $planIds = $allPlans->pluck('id')->all();
        $ancestorIds = array_map(fn ($a) => $a->id, $ancestors);
        $ancestorIds[] = $plan->id;

        // Zähler-Aufschlüsselung des Masters: direkte Sub-Master + Blatt-Instanzen gesamt
        if ($isMaster) {
            foreach (($childrenByParent[$plan->id] ?? collect()) as $dk) {
                if (($childrenByParent[$dk->id] ?? collect())->isNotEmpty()) {
                    $subMasterCount++;
                }
            }
            $countLeaves = function ($id) use (&$countLeaves, $childrenByParent) {
                $kids = $childrenByParent[$id] ?? collect();
                if ($kids->isEmpty()) {
                    return 1;
                }
                $n = 0;
                foreach ($kids as $k) {
                    $n += $countLeaves($k->id);
                }
                return $n;
            };
            $leafCount = $countLeaves($plan->id);
        }

        $detailSourceIds = ForecastRowSource::whereNotNull('source_plan_id')
            ->pluck('source_plan_id')->map(fn ($x) => (int) $x)->unique()->all();
        $planRole = [];
        foreach ($allPlans as $pp) {
            $hasChildren = ($childrenByParent[$pp->id] ?? collect())->isNotEmpty();
            $hasParent = $pp->parent_plan_id && in_array($pp->parent_plan_id, $planIds, true);
            $planRole[$pp->id] = $hasChildren ? 'master'
                : ($hasParent ? 'instance'
                    : (in_array($pp->id, $detailSourceIds, true) ? 'detail' : 'single'));
        }
        $selfRole = $planRole[$plan->id] ?? 'single';

        // Für die Doku-Zeile eines Detailplans: in welchen Plänen wird er per Drill-down genutzt?
        $usedIn = [];
        if ($selfRole === 'detail') {
            $consumerIds = DB::table('forecast_row_sources as rs')
                ->join('forecast_rows as r', 'r.id', '=', 'rs.row_id')
                ->where('rs.source_plan_id', $plan->id)
                ->whereNotNull('r.plan_id')
                ->pluck('r.plan_id')->unique()->all();
            $usedIn = $allPlans->whereIn('id', $consumerIds)->pluck('name')->values()->all();
        }

        // Kontext = verbundene Komponente des aktuellen Plans (Konsolidierung ∪ Drill-down)
        $adj = [];
        $addEdge = function ($a, $b) use (&$adj) {
            if ($a && $b && $a !== $b) {
                $adj[$a][$b] = true;
                $adj[$b][$a] = true;
            }
        };
        foreach ($allPlans as $pp) {
            if ($pp->parent_plan_id && in_array($pp->parent_plan_id, $planIds, true)) {
                $addEdge($pp->id, $pp->parent_plan_id);
            }
        }
        $plansByType = $allPlans->groupBy('plan_type_id');
        $drillConsumerIds = []; // Pläne, die einen Drill-down HABEN (eine Zeile aus Detailplan)
        foreach (DB::table('forecast_row_sources as rs')
            ->join('forecast_rows as r', 'r.id', '=', 'rs.row_id')
            ->whereNotNull('rs.source_plan_id')
            ->select('rs.source_plan_id', 'r.plan_id', 'r.plan_type_id')->get() as $e) {
            $detailId = (int) $e->source_plan_id;
            if (! in_array($detailId, $planIds, true)) {
                continue;
            }
            if ($e->plan_id && in_array((int) $e->plan_id, $planIds, true)) {
                $addEdge((int) $e->plan_id, $detailId);
                $drillConsumerIds[(int) $e->plan_id] = true;
            } elseif ($e->plan_type_id) {
                foreach (($plansByType[$e->plan_type_id] ?? []) as $cp) {
                    $addEdge($cp->id, $detailId);
                    $drillConsumerIds[$cp->id] = true;
                }
            }
        }
        $componentSet = [$plan->id => true];
        $queue = [$plan->id];
        while ($queue) {
            $n = array_shift($queue);
            foreach (array_keys($adj[$n] ?? []) as $m) {
                if (empty($componentSet[$m])) {
                    $componentSet[$m] = true;
                    $queue[] = $m;
                }
            }
        }
        $context = $allPlans->filter(fn ($pp) => isset($componentSet[$pp->id]));

        // Konsolidierungs-Wurzeln im Kontext (Master ohne Elternplan im Kontext) → Baum;
        // übrige Kontext-Pläne (Detail/Einzel) → separate Liste.
        $contextRoots = $context->filter(function ($pp) use ($childrenByParent, $componentSet) {
            $hasChildrenInCtx = ($childrenByParent[$pp->id] ?? collect())->contains(fn ($c) => isset($componentSet[$c->id]));
            $parentInCtx = $pp->parent_plan_id && isset($componentSet[$pp->parent_plan_id]);
            return $hasChildrenInCtx && ! $parentInCtx;
        })->values();
        $inTree = [];
        $collect = function ($id) use (&$collect, &$inTree, $childrenByParent, $componentSet) {
            $inTree[$id] = true;
            foreach (($childrenByParent[$id] ?? []) as $c) {
                if (isset($componentSet[$c->id]) && empty($inTree[$c->id])) {
                    $collect($c->id);
                }
            }
        };
        foreach ($contextRoots as $r) {
            $collect($r->id);
        }
        $contextOther = $context->filter(fn ($pp) => empty($inTree[$pp->id]))->values();

        return view('forecast::livewire.plan-view', [
            'parentPlan' => $parentPlan,
            'childPlans' => $childPlans,
            'fromPlan' => $fromPlan,
            'ancestors' => $ancestors,
            'contextRoots' => $contextRoots,
            'contextOther' => $contextOther,
            'childrenByParent' => $childrenByParent,
            'componentSet' => $componentSet,
            'planRole' => $planRole,
            'selfRole' => $selfRole,
            'usedIn' => $usedIn,
            'drillConsumerIds' => array_keys($drillConsumerIds),
            'ancestorIds' => $ancestorIds,
            'isMaster' => $isMaster,
            'childCount' => $childCount,
            'subMasterCount' => $subMasterCount,
            'leafCount' => $leafCount,
            'timeDetail' => $timeDetail,
            'partial' => $partial,
            'delta' => $delta,
            'showDelta' => $this->showDelta,
            'share' => $share,
            'quote' => $quote,
            'showShare' => $this->showShare,
            'plan' => $plan,
            'rows' => $rows,
            'columns' => $columns,
            'level' => $level,
            'levelLabel' => $this->levelLabelDe($level),
            'scopeCaption' => $this->scopeCaption($level),
            'meta' => $meta,
            'rowInfo' => $rowInfo,
            'formulaCells' => $formulaCells,
            'breadcrumb' => $breadcrumb,
            'zoomed' => $this->container !== '',
            'canZoom' => $level !== 'hour',
            'lock' => $lock,
            'colStatus' => $colStatus,
        ])->layout('platform::layouts.app');
    }

    /**
     * Impliziter Wert, der in einen (selbst leeren) Container fließt — der Rest
     * höherer Ebenen kaskadiert gleichmäßig nach unten, bis er hier ankommt.
     */
    protected function impliedInto(ForecastPlan $plan, array $cells, string $container): float
    {
        $chain = [];
        $k = $container;
        while ($k !== null) {
            $chain[] = $k;
            $k = TimeAxis::parentKey($k);
        }
        $chain[] = ''; // Wurzel
        $chain = array_reverse($chain); // ['', Jahr, …, Container]

        $value = null; // null = via gespeicherten Wert
        for ($i = 0; $i < count($chain) - 1; $i++) {
            $parent = $chain[$i];
            $child = $chain[$i + 1];

            $siblings = $parent === '' ? $this->years($plan) : $this->childBuckets($parent, $plan);

            if ($parent === '') {
                $parentValue = 0.0;
                foreach ($siblings as $s) {
                    $parentValue += $cells[$s]['value'] ?? 0;
                }
            } else {
                $parentValue = $value ?? ($cells[$parent]['value'] ?? 0);
            }

            $storedSum = 0.0;
            $empty = 0;
            foreach ($siblings as $s) {
                $sv = $cells[$s]['value'] ?? 0;
                if ($sv > 0) {
                    $storedSum += $sv;
                } else {
                    $empty++;
                }
            }
            $distribute = max(0, $parentValue - $storedSum);

            $childReconciled = $cells[$child]['value'] ?? 0;
            $value = $childReconciled > 0 ? null : (($empty > 0) ? $distribute / $empty : 0.0);
        }

        return $value ?? ($cells[$container]['value'] ?? 0);
    }

    /**
     * "Verbindlich verplant" innerhalb eines Containers: gepinnte Blatt-Detail-Werte
     * (Detail-Eingaben ohne feinere Eingabe darunter) + alle Plus-Eingaben.
     *
     * @param  list<array{key:string, value:float, mode:Mode}>  $rowEntries
     */
    protected function committedFor(array $rowEntries, string $container): float
    {
        $sum = 0.0;
        foreach ($rowEntries as $e) {
            if (! $this->within($e['key'], $container)) {
                continue;
            }
            if ($e['mode'] === Mode::Plus) {
                $sum += $e['value'];
                continue;
            }
            $leaf = true;
            foreach ($rowEntries as $e2) {
                if ($e2['key'] !== $e['key'] && $this->isDescendant($e2['key'], $e['key'])) {
                    $leaf = false;
                    break;
                }
            }
            if ($leaf) {
                $sum += $e['value'];
            }
        }

        return $sum;
    }

    protected function within(string $key, string $container): bool
    {
        return $container === '' || $key === $container || $this->isDescendant($key, $container);
    }

    protected function isDescendant(string $key, string $ancestor): bool
    {
        $k = TimeAxis::parentKey($key);
        while ($k !== null) {
            if ($k === $ancestor) {
                return true;
            }
            $k = TimeAxis::parentKey($k);
        }

        return false;
    }

    protected function levelLabelDe(string $level): string
    {
        return match ($level) {
            'year' => 'Jahr',
            'quarter' => 'Quartal',
            'month' => 'Monat',
            'day' => 'Tag',
            'hour' => 'Stunde',
            default => ucfirst($level),
        };
    }

    /** Beantwortet "was siehst du gerade". */
    protected function scopeCaption(string $level): string
    {
        if ($this->container === '') {
            return 'Jahresübersicht';
        }

        $plural = match ($level) {
            'quarter' => 'Quartale',
            'month' => 'Monate',
            'day' => 'Tage',
            'hour' => 'Stunden',
            default => $level,
        };
        $prep = $level === 'hour' ? 'am' : 'in';

        return "{$plural} {$prep} {$this->label($this->container)}";
    }

    /** @return list<array{bucket:string,label:string}> */
    protected function columns(ForecastPlan $plan): array
    {
        $buckets = $this->childBuckets($this->container, $plan);

        return array_map(fn (string $b) => [
            'bucket' => $b,
            'label' => $this->label($b),
        ], $buckets);
    }

    protected function childLevel(string $container): string
    {
        if ($container === '') {
            return 'year';
        }
        return match (TimeLevel::fromKey($container)) {
            TimeLevel::Year => 'quarter',
            TimeLevel::Quarter => 'month',
            TimeLevel::Month => 'day',
            TimeLevel::Day => 'hour',
            TimeLevel::Hour => 'hour',
        };
    }

    /** @return list<string> */
    protected function childBuckets(string $container, ForecastPlan $plan): array
    {
        if ($container === '') {
            return $this->years($plan);
        }

        $level = TimeLevel::fromKey($container);

        if ($level === TimeLevel::Year) {
            return array_map(fn ($q) => "{$container}-Q{$q}", range(1, 4));
        }
        if ($level === TimeLevel::Quarter) {
            [$y, $q] = explode('-Q', $container);
            $start = ((int) $q - 1) * 3 + 1;
            return array_map(fn ($m) => sprintf('%s-%02d', $y, $m), [$start, $start + 1, $start + 2]);
        }
        if ($level === TimeLevel::Month) {
            [$y, $m] = explode('-', $container);
            $days = Carbon::create((int) $y, (int) $m, 1)->daysInMonth;
            return array_map(fn ($d) => sprintf('%s-%02d', $container, $d), range(1, $days));
        }
        if ($level === TimeLevel::Day) {
            return array_map(fn ($h) => sprintf('%sT%02d', $container, $h), range(0, 23));
        }

        return []; // Stunde = Blatt
    }

    /** @return list<string> */
    protected function years(ForecastPlan $plan): array
    {
        $set = [];
        if ($plan->period_start && $plan->period_end) {
            for ($y = (int) $plan->period_start->format('Y'); $y <= (int) $plan->period_end->format('Y'); $y++) {
                $set[] = (string) $y;
            }
        }
        foreach ($plan->entries()->pluck('bucket_key') as $bk) {
            $set[] = substr((string) $bk, 0, 4);
        }
        $set = array_values(array_unique($set));
        if (! $set) {
            $set = [(string) now()->year];
        }
        sort($set);

        return $set;
    }

    /** @return list<array{bucket:string,label:string}> */
    protected function breadcrumb(): array
    {
        $crumbs = [['bucket' => '', 'label' => 'Alle']];
        if ($this->container === '') {
            return $crumbs;
        }

        $chain = [];
        $k = $this->container;
        while ($k !== null) {
            $chain[] = $k;
            $k = TimeAxis::parentKey($k);
        }
        foreach (array_reverse($chain) as $b) {
            $crumbs[] = ['bucket' => $b, 'label' => $this->label($b)];
        }

        return $crumbs;
    }

    protected function label(string $bucket): string
    {
        return match (TimeLevel::fromKey($bucket)) {
            TimeLevel::Year => $bucket,
            TimeLevel::Quarter => str_replace('-', ' ', $bucket), // "2026-Q3" → "2026 Q3"
            TimeLevel::Month => Carbon::createFromFormat('Y-m', $bucket)->translatedFormat('M Y'),
            TimeLevel::Day => Carbon::createFromFormat('Y-m-d', $bucket)->translatedFormat('D d.'),
            TimeLevel::Hour => substr($bucket, strpos($bucket, 'T') + 1).':00',
        };
    }
}
