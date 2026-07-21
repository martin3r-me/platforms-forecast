<?php

namespace Platform\Forecast\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Models\ForecastRowSource;

/**
 * Modul-Sidebar: statische Navigation + Auflistung der Top-Level-Planungen
 * (Master & Einzelpläne). Kind-Instanzen erreicht man drinnen über die
 * kontext-Sidebar der Planung. Rolle je Plan über ein Icon gekennzeichnet.
 */
class Sidebar extends Component
{
    public function render()
    {
        $user = Auth::user();

        if (! $user) {
            return view('forecast::livewire.sidebar', ['roots' => collect(), 'planRole' => []]);
        }

        $teamId = $user->currentTeam?->id ?? $user->current_team_id;

        $plans = ForecastPlan::where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'parent_plan_id']);

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

        // Nur Parent/Top-Level: Master + Einzel-/Detailpläne ohne Elternplan (keine Instanzen)
        $roots = $plans->filter(fn ($p) => $p->parent_plan_id === null || ! in_array($p->parent_plan_id, $ids, true))->values();

        return view('forecast::livewire.sidebar', [
            'roots' => $roots,
            'planRole' => $planRole,
        ]);
    }
}
