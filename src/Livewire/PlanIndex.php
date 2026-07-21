<?php

namespace Platform\Forecast\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Models\ForecastRowSource;

/**
 * Read-only Übersicht aller Planungen des aktuellen Teams — als Hierarchie:
 * Master (mit ihren Instanzen aufgeklappt) · Einzelpläne · Detailpläne.
 */
class PlanIndex extends Component
{
    public function render()
    {
        $teamId = Auth::user()->currentTeam->id;

        $plans = ForecastPlan::where('team_id', $teamId)
            ->with('planType')
            ->orderBy('name')
            ->get();

        $childrenByParent = $plans->groupBy('parent_plan_id');
        $ids = $plans->pluck('id')->all();

        $detailSourceIds = ForecastRowSource::whereNotNull('source_plan_id')
            ->pluck('source_plan_id')->map(fn ($x) => (int) $x)->unique()->all();

        $planRole = [];
        foreach ($plans as $p) {
            $hasChildren = ($childrenByParent[$p->id] ?? collect())->isNotEmpty();
            $hasParent = $p->parent_plan_id && in_array($p->parent_plan_id, $ids, true);
            $planRole[$p->id] = $hasChildren ? 'master'
                : ($hasParent ? 'instance'
                    : (in_array($p->id, $detailSourceIds, true) ? 'detail' : 'single'));
        }

        $drillConsumerIds = DB::table('forecast_row_sources as rs')
            ->join('forecast_rows as r', 'r.id', '=', 'rs.row_id')
            ->whereNotNull('rs.source_plan_id')
            ->whereNotNull('r.plan_id')
            ->pluck('r.plan_id')->map(fn ($x) => (int) $x)->unique()->all();

        $roots = $plans->filter(fn ($p) => $p->parent_plan_id === null || ! in_array($p->parent_plan_id, $ids, true))->values();

        return view('forecast::livewire.plan-index', [
            'masters' => $roots->filter(fn ($p) => $planRole[$p->id] === 'master')->values(),
            'singles' => $roots->filter(fn ($p) => in_array($planRole[$p->id], ['single', 'instance'], true))->values(),
            'details' => $roots->filter(fn ($p) => $planRole[$p->id] === 'detail')->values(),
            'childrenByParent' => $childrenByParent,
            'planRole' => $planRole,
            'drillConsumerIds' => $drillConsumerIds,
            'total' => $plans->count(),
        ])->layout('platform::layouts.app');
    }
}
