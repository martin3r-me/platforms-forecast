<?php

/**
 * Sidebar Livewire Component
 * 
 * Modul-spezifische Sidebar.
 * 
 * WICHTIG FÜR LLMs:
 * - Wird automatisch in der Haupt-Sidebar eingebunden
 * - Zeigt modul-spezifische Navigation
 * - Kann dynamische Listen enthalten (z.B. aus Datenbank)
 * 
 * ANPASSUNGEN:
 * - Füge modul-spezifische Logik hinzu
 * - Lade dynamische Daten (z.B. Projekte, Listen)
 * - Implementiere Toggle-Funktionen
 */

namespace Platform\Forecast\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

class Sidebar extends Component
{
    /**
     * Render-Methode
     * 
     * Gibt die Sidebar-View zurück.
     */
    public function render()
    {
        $user = auth()->user();

        if (!$user) {
            return view('forecast::livewire.sidebar', []);
        }

        /**
         * BEISPIEL: Dynamische Daten laden
         * 
         * $entities = YourModel::where('team_id', $user->currentTeam->id)
         *     ->orderBy('name')
         *     ->get();
         */

        return view('forecast::livewire.sidebar', [
            // Füge hier deine Daten hinzu
        ]);
    }
}
