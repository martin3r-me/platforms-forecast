<?php

namespace Platform\Forecast\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Forecast\Models\ForecastPlan;

/**
 * Read-only Übersicht aller Planungen des aktuellen Teams.
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

        return view('forecast::livewire.plan-index', [
            'plans' => $plans,
        ])->layout('platform::layouts.app');
    }
}
