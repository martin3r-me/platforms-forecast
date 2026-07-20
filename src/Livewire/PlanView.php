<?php

namespace Platform\Forecast\Livewire;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
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

        // Container-Zusammenfassung (eigener Wert + Rest je Zeile)
        $summary = [];
        if ($this->container !== '') {
            foreach ($rows as $rk => $r) {
                $summary[$rk] = $r['cells'][$this->container] ?? ['value' => 0, 'rest' => 0];
            }
        }

        return view('forecast::livewire.plan-view', [
            'plan' => $plan,
            'rows' => $rows,
            'columns' => $columns,
            'level' => $level,
            'breadcrumb' => $breadcrumb,
            'summary' => $summary,
            'canZoom' => $level !== 'hour',
        ])->layout('platform::layouts.app');
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
            TimeLevel::Year => 'month',
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
            return array_map(fn ($m) => sprintf('%s-%02d', $container, $m), range(1, 12));
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
            TimeLevel::Month => Carbon::createFromFormat('Y-m', $bucket)->translatedFormat('M Y'),
            TimeLevel::Day => Carbon::createFromFormat('Y-m-d', $bucket)->translatedFormat('D d.'),
            TimeLevel::Hour => substr($bucket, strpos($bucket, 'T') + 1).':00',
        };
    }
}
