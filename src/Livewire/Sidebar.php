<?php

namespace Platform\Forecast\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Forecast\Models\ForecastPlan;

/**
 * Modul-Sidebar: statische Navigation + dynamische Auflistung aller Planungen
 * als Baum (Wurzel-Pläne → konsolidierte Kind-Instanzen), damit man von überall
 * direkt in eine Planung springen kann.
 */
class Sidebar extends Component
{
    public function render()
    {
        $user = Auth::user();

        if (! $user) {
            return view('forecast::livewire.sidebar', ['roots' => collect(), 'childrenByParent' => collect()]);
        }

        $teamId = $user->currentTeam?->id ?? $user->current_team_id;

        $plans = ForecastPlan::where('team_id', $teamId)
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'parent_plan_id']);

        $childrenByParent = $plans->groupBy('parent_plan_id');

        // Wurzeln: kein Elternplan ODER Elternplan liegt außerhalb der Sichtbarkeit
        $ids = $plans->pluck('id')->all();
        $roots = $plans->filter(fn ($p) => $p->parent_plan_id === null || ! in_array($p->parent_plan_id, $ids, true))->values();

        return view('forecast::livewire.sidebar', [
            'roots' => $roots,
            'childrenByParent' => $childrenByParent,
        ]);
    }
}
