<?php

namespace Platform\Forecast\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Forecast\Models\ForecastLockPolicy;
use Platform\Forecast\Models\ForecastPlanType;
use Platform\Forecast\Models\ForecastUnit;

/**
 * Read-only Einstellungen: zeigt die entkoppelten Konfig-Modelle (Einheiten,
 * Sperr-Regeln, Plan-Typen) und die System-Vokabulare als Index-Seiten.
 */
class Settings extends Component
{
    public string $section = 'overview';

    public function mount(?string $section = null): void
    {
        $this->section = in_array($section, ['units', 'lock-policies', 'plan-types', 'vocabulary'], true)
            ? $section
            : 'overview';
    }

    public function render()
    {
        $teamId = Auth::user()->currentTeam->id;
        $data = ['section' => $this->section];

        $teamScope = fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id');

        if ($this->section === 'units') {
            $data['units'] = ForecastUnit::where($teamScope)->orderBy('dimension')->orderBy('sort_order')->get();
        } elseif ($this->section === 'lock-policies') {
            $data['policies'] = ForecastLockPolicy::where($teamScope)->orderByDesc('is_default')->orderBy('name')->get();
        } elseif ($this->section === 'plan-types') {
            $data['types'] = ForecastPlanType::where('team_id', $teamId)->withCount(['rows', 'plans'])->orderBy('name')->get();
        } elseif ($this->section === 'vocabulary') {
            $data['vocab'] = $this->vocabulary();
        }

        // Zähler für die Übersicht
        $data['counts'] = [
            'units' => ForecastUnit::where($teamScope)->count(),
            'policies' => ForecastLockPolicy::where($teamScope)->count(),
            'types' => ForecastPlanType::where('team_id', $teamId)->count(),
        ];

        return view('forecast::livewire.settings', $data)->layout('platform::layouts.app');
    }

    /** @return array<string, array<int, array{code:string, label:string}>> */
    protected function vocabulary(): array
    {
        return [
            'Zeilen-Arten' => [
                ['code' => 'input', 'label' => 'Eingabe (frei)'],
                ['code' => 'formula', 'label' => 'Formel (berechnet, read-only)'],
            ],
            'Richtungen' => [
                ['code' => 'income', 'label' => 'Ertrag (+)'],
                ['code' => 'expense', 'label' => 'Aufwand (−)'],
                ['code' => 'neutral', 'label' => 'Messgröße (ohne Vorzeichen)'],
            ],
            'Aggregationen' => [
                ['code' => 'sum', 'label' => 'Σ Summe'],
                ['code' => 'net', 'label' => '± Netto (Ertrag − Aufwand)'],
                ['code' => 'ratio', 'label' => '% Marge (a / b × 100)'],
                ['code' => 'avg', 'label' => 'Ø Mittelwert'],
                ['code' => 'median', 'label' => 'Median'],
                ['code' => 'min', 'label' => 'Minimum'],
                ['code' => 'max', 'label' => 'Maximum'],
                ['code' => 'count', 'label' => 'Anzahl (≠ 0)'],
                ['code' => 'product', 'label' => '∏ Produkt'],
            ],
            'Zeit-Ebenen' => [
                ['code' => 'year', 'label' => 'Jahr'],
                ['code' => 'quarter', 'label' => 'Quartal'],
                ['code' => 'month', 'label' => 'Monat'],
                ['code' => 'day', 'label' => 'Tag'],
                ['code' => 'hour', 'label' => 'Stunde'],
            ],
            'Eingabe-Modi' => [
                ['code' => 'detail', 'label' => 'Verteilen (fixer Teilwert, Rest verteilt sich)'],
                ['code' => 'plus', 'label' => 'Plus (kommt zusätzlich dazu)'],
            ],
        ];
    }
}
