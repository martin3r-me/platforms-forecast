<?php

namespace Platform\Forecast\Livewire;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Forecast\Enums\Direction;
use Platform\Forecast\Enums\RowKind;
use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Reconciliation\TimeAxis;
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

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
        // Validierung + Team-Scope
        $this->plan();
    }

    public function zoom(string $bucket): void
    {
        $this->container = $bucket;
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
            $cells = $r['cells'];
            $rowEntries = $entriesByRow[$rk] ?? [];

            $implied = false;
            $spread = 0.0;

            if ($this->container === '') {
                $value = 0.0;
                foreach ($columns as $col) {
                    $value += $cells[$col['bucket']]['value'] ?? 0;
                }
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

        // Zeilen-Metainfo (Richtung, Einheit, Formel)
        $rowModels = $plan->resolvedRows()->keyBy('key');
        $rowInfo = [];
        foreach ($rows as $rk => $r) {
            $m = $rowModels->get($rk);
            $cfg = $m?->config ?? [];
            $isFormula = ($m?->kind === RowKind::Formula);
            $agg = $cfg['agg'] ?? 'sum';
            $dir = $m?->direction instanceof Direction ? $m->direction->value : ($m?->direction ?? 'neutral');
            $rowInfo[$rk] = [
                'isFormula' => $isFormula,
                'direction' => $dir,
                'unit' => $m?->unit?->symbol,
                'agg' => $agg,
                'sources' => $cfg['sources'] ?? [],
                'signMode' => ($isFormula && $agg === 'net') ? 'net' : 'direction',
                'aggLabel' => $isFormula ? $this->aggLabel($agg) : null,
            ];
        }

        // Formula-Zeilen berechnen (read-only, aggregieren andere Zeilen je Bucket)
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
                $vals = array_map(fn ($s) => $this->displayVal($s, $col['bucket'], $rows, $meta, $formulaCells), $sources);
                $cells[$col['bucket']] = $this->aggregate($agg, $vals, $dirs);
            }
            $formulaCells[$rk] = $cells;

            $svals = array_map(fn ($s) => $meta[$s]['value'] ?? 0, $sources);
            $summary = $this->aggregate($agg, $svals, $dirs);
            $meta[$rk] = [
                'value' => $summary, 'committed' => $summary, 'rest' => 0.0,
                'implied' => false, 'spread' => 0.0, 'cellCommitted' => [],
            ];
        }

        return view('forecast::livewire.plan-view', [
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
        ])->layout('platform::layouts.app');
    }

    /** Angezeigter Wert einer Quell-Zeile an einem Bucket (belegt oder ≈ verteilt). */
    protected function displayVal(string $src, string $bucket, array $rows, array $meta, array $formulaCells): float
    {
        if (isset($formulaCells[$src])) {
            return $formulaCells[$src][$bucket] ?? 0.0;
        }
        $v = $rows[$src]['cells'][$bucket]['value'] ?? 0;
        return $v > 0 ? $v : ($meta[$src]['spread'] ?? 0.0);
    }

    /**
     * Generische Aggregation über andere Zellen.
     *
     * @param  list<float>   $vals
     * @param  list<string>  $dirs  Richtungen der Quellen (für "net")
     */
    protected function aggregate(string $fn, array $vals, array $dirs = []): float
    {
        if (empty($vals)) {
            return 0.0;
        }
        return match ($fn) {
            'net' => array_sum(array_map(
                fn ($v, $d) => $d === 'expense' ? -$v : ($d === 'neutral' ? 0 : $v),
                $vals,
                $dirs + array_fill(0, count($vals), 'income'),
            )),
            'avg' => array_sum($vals) / count($vals),
            'median' => $this->median($vals),
            'min' => min($vals),
            'max' => max($vals),
            'count' => (float) count(array_filter($vals, fn ($v) => $v != 0)),
            'product' => array_product($vals),
            default => array_sum($vals), // sum
        };
    }

    /** @param list<float> $vals */
    protected function median(array $vals): float
    {
        sort($vals);
        $n = count($vals);
        $mid = intdiv($n, 2);

        return $n % 2 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
    }

    protected function aggLabel(string $agg): string
    {
        return match ($agg) {
            'net' => '± Netto',
            'avg' => 'Ø Mittel',
            'median' => 'Median',
            'min' => 'Min',
            'max' => 'Max',
            'count' => 'Anzahl',
            'product' => '∏ Produkt',
            default => 'Σ Summe',
        };
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
