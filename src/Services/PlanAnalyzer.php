<?php

namespace Platform\Forecast\Services;

use Illuminate\Support\Carbon;
use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Models\ForecastLockPolicy;
use Platform\Forecast\Models\ForecastPlan;

/**
 * Lese-/Analyse-Schicht über dem PlanReconciler — für MCP-Tools, Exporte, Reporting.
 *
 * Der Reconciler liefert die reconciled Werte (Eingabe + Formel + Ordner-Konsolidierung +
 * Drill-down) je Zeile/Bucket. Dieser Service projiziert daraus eine Zeit-Ebene (Spalten
 * mit Sperr-Status) und reichert jede Zelle um ihren Feld-Zustand + Herkunft an — dieselbe
 * Semantik wie die UI, nur als Daten: offen · berechnet (ƒ) · abgeleitet (↑) · zu (🔒).
 */
final class PlanAnalyzer
{
    /**
     * @param  ?array  $view  optional vorberechnete Reconciler-Sicht (spart Doppelrechnung)
     * @return array{plan:array, lock:array, level:string, container:string, columns:list<array>, rows:array<string,array>}
     */
    public function analyze(ForecastPlan $plan, string $container = '', ?string $forceLevel = null, ?array $view = null): array
    {
        $view ??= (new PlanReconciler())->view($plan);
        $isMaster = ForecastPlan::where('parent_plan_id', $plan->id)->exists();
        $lock = $this->lockRule($plan);
        $now = now();

        if ($forceLevel !== null && $forceLevel !== '') {
            $buckets = $this->bucketsAtLevel($plan, $forceLevel);
            $level = $forceLevel;
        } else {
            $buckets = $this->childBuckets($container, $plan);
            $level = $this->childLevel($container);
        }

        $columns = [];
        $lockByBucket = [];
        foreach ($buckets as $b) {
            $status = LockService::status($b, $lock, $now);
            $columns[] = ['bucket' => $b, 'label' => $this->label($b), 'state' => $status['state'], 'days' => $status['days']];
            $lockByBucket[$b] = $status['state'];
        }

        $rows = [];
        foreach ($view['rows'] as $key => $row) {
            $info = $view['rowInfo'][$key] ?? [];
            $isF = (bool) ($info['isFormula'] ?? false);
            $hasRef = ! empty($info['refPlans']);
            $cells = [];
            foreach ($buckets as $b) {
                $c = $row['cells'][$b] ?? null;
                $closed = ($lockByBucket[$b] ?? 'open') === 'closed';
                $state = $isF ? 'computed'
                    : ((($isMaster && ! $isF) || $hasRef) ? 'derived'
                    : ($closed ? 'locked' : 'open'));
                $entered = (bool) ($c['entered'] ?? false);
                $value = $c !== null ? $c['value'] : null;
                $cells[$b] = [
                    'value' => $value,
                    'entered' => $entered,
                    'mode' => $c['mode'] ?? null,
                    'derived' => (bool) ($c['derived'] ?? false),
                    'effective' => (bool) ($c['effective'] ?? false),
                    'rest' => round((float) ($c['rest'] ?? 0), 4),
                    'state' => $state,
                    'empty' => $c === null || (! $entered && (float) ($value ?? 0) === 0.0),
                ];
            }
            $rows[$key] = [
                'key' => $key,
                'label' => $row['label'],
                'kind' => $row['kind'],
                'unit' => $info['unit'] ?? null,
                'unit_code' => $info['unitCode'] ?? null,
                'direction' => $info['direction'] ?? null,
                'section' => $info['section'] ?? null,
                'is_formula' => $isF,
                'is_factor' => (bool) ($info['isFactor'] ?? false),
                'non_additive' => (bool) ($info['nonAdditive'] ?? false),
                'time_agg' => $info['timeAgg'] ?? 'flow',
                'agg' => $info['agg'] ?? null,
                'agg_label' => $info['aggLabel'] ?? null,
                'source_count' => $info['sourceCount'] ?? 0,
                'ref_plans' => array_map(fn ($r) => [
                    'uuid' => $r['uuid'] ?? null, 'name' => $r['name'] ?? null, 'row_key' => $r['row_key'] ?? null,
                ], $info['refPlans'] ?? []),
                'warnings' => array_values($info['warnings'] ?? []),
                'total' => $view['totals'][$key] ?? null,
                'cells' => $cells,
            ];
        }

        return [
            'plan' => [
                'uuid' => $plan->uuid,
                'name' => $plan->name,
                'version' => $plan->current_version,
                'is_master' => $isMaster,
                'role' => $isMaster ? 'ordner' : 'blatt',
                'plan_type' => $plan->planType?->name,
                'organization_entity_id' => $plan->organization_entity_id,
            ],
            'lock' => [
                'policy' => $lock['policy_name'] ?? null,
                'period_level' => $lock['period_level'],
                'lead_days' => $lock['lead_days'],
                'grace_days' => $lock['grace_days'],
            ],
            'level' => $level,
            'container' => $container,
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /** Wirksame Sperr-Regel: Plan-Policy → Team-Default → Code-Default → Plan-Metadata. */
    public function lockRule(ForecastPlan $plan): array
    {
        $policy = $plan->lockPolicy ?? ForecastLockPolicy::resolveDefault($plan->team_id);
        $lock = array_merge(
            ['period_level' => 'month', 'lead_days' => 40, 'grace_days' => 10],
            $policy ? $policy->toRule() : [],
            (array) ($plan->metadata['lock'] ?? []),
        );
        $lock['policy_name'] = $policy?->name;

        return $lock;
    }

    // ─────────── Zeit-Ebenen-Helfer (Semantik gespiegelt aus PlanView) ───────────

    public function childLevel(string $container): string
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
    public function childBuckets(string $container, ForecastPlan $plan): array
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

        return [];
    }

    /** Alle Buckets einer Ebene über die Plan-Jahre (flache Sicht für Agenten). @return list<string> */
    public function bucketsAtLevel(ForecastPlan $plan, string $level): array
    {
        $years = $this->years($plan);
        $out = [];
        foreach ($years as $y) {
            $out = array_merge($out, match ($level) {
                'year' => [$y],
                'quarter' => array_map(fn ($q) => "{$y}-Q{$q}", range(1, 4)),
                'month' => array_map(fn ($m) => sprintf('%s-%02d', $y, $m), range(1, 12)),
                'day' => array_reduce(range(1, 12), function ($carry, $m) use ($y) {
                    $days = Carbon::create((int) $y, $m, 1)->daysInMonth;

                    return array_merge($carry, array_map(fn ($d) => sprintf('%s-%02d-%02d', $y, $m, $d), range(1, $days)));
                }, []),
                default => [$y],
            });
        }

        return $out;
    }

    /** @return list<string> */
    public function years(ForecastPlan $plan): array
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

    public function label(string $bucket): string
    {
        return match (TimeLevel::fromKey($bucket)) {
            TimeLevel::Year => $bucket,
            TimeLevel::Quarter => str_replace('-', ' ', $bucket),
            TimeLevel::Month => Carbon::createFromFormat('Y-m', $bucket)->translatedFormat('M Y'),
            TimeLevel::Day => Carbon::createFromFormat('Y-m-d', $bucket)->translatedFormat('D d.'),
            TimeLevel::Hour => substr($bucket, strpos($bucket, 'T') + 1).':00',
        };
    }
}
