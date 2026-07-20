{{--
    Forecast — Dashboard

    Leeres Modul-Grundgerüst mit funktionsfähiger Page-Shell:
    Navbar, Actionbar, linke Sidebar (sidebar-Slot) und rechte Sidebar (activity-Slot).
    Inhalt folgt — hier steht bewusst nur ein Platzhalter.
--}}

<x-ui-page>
    {{-- Navbar --}}
    <x-slot name="navbar">
        <x-ui-page-navbar title="Forecast" />
    </x-slot>

    {{-- Actionbar --}}
    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Forecast', 'icon' => 'presentation-chart-line'],
        ]" />
    </x-slot>

    {{-- Hauptinhalt --}}
    <x-ui-page-container>
        <div class="flex flex-col items-center justify-center text-center py-24">
            <div class="flex items-center justify-center w-14 h-14 rounded-2xl bg-[var(--ui-primary)]/10 mb-5">
                @svg('heroicon-o-presentation-chart-line', 'w-7 h-7 text-[var(--ui-primary)]')
            </div>
            <h1 class="text-xl font-semibold tracking-tight text-[var(--ui-secondary)] mb-2">
                Forecast
            </h1>
            <p class="text-sm text-[var(--ui-muted)] max-w-md">
                Abstrakte, zahlengetriebene Planungen. Das Modul ist angelegt und leer —
                die Planungs-UI folgt.
            </p>
        </div>
    </x-ui-page-container>

    {{-- Linke Sidebar --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" width="w-80" :defaultOpen="true">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)] mb-3">Planungen</h3>
                    <div class="text-sm text-[var(--ui-muted)]">Noch keine Planungen vorhanden.</div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechte Sidebar --}}
    <x-slot name="activity">
        <x-ui-page-sidebar title="Aktivitäten" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--ui-muted)] mb-3">Letzte Aktivitäten</h3>
                    <div class="text-sm text-[var(--ui-muted)]">Keine Aktivitäten verfügbar.</div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
