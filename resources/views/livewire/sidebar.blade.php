{{--
    Sidebar View
    Modul-spezifische Sidebar
    
    WICHTIG FÜR LLMs:
    - Wird automatisch in der Haupt-Sidebar eingebunden
    - Verwendet x-ui-sidebar-list und x-ui-sidebar-item Komponenten
    - Unterstützt collapsed/expanded Zustand
    - Kann dynamische Listen enthalten
    
    ANPASSUNGEN:
    - Füge modul-spezifische Navigation hinzu
    - Implementiere dynamische Listen (z.B. aus Datenbank)
    - Füge Toggle-Funktionen hinzu
--}}

<div>
    {{-- Modul Header --}}
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--ui-secondary)] uppercase border-b border-[var(--ui-border)] mb-2">
        Forecast
    </div>
    
    {{-- Abschnitt: Allgemein --}}
    <x-ui-sidebar-list label="Allgemein">
        <x-ui-sidebar-item :href="route('forecast.dashboard')">
            @svg('heroicon-o-home', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Dashboard</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('forecast.plans.index')">
            @svg('heroicon-o-table-cells', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Planungen</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('forecast.settings')">
            @svg('heroicon-o-cog-6-tooth', 'w-4 h-4 text-[var(--ui-secondary)]')
            <span class="ml-2 text-sm">Einstellungen</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('forecast.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-home', 'w-5 h-5')
            </a>
        </div>
    </div>

    {{-- BEISPIEL: Dynamische Liste (auskommentiert) --}}
    {{--
    <x-ui-sidebar-list label="Dynamische Liste">
        @foreach($entities as $entity)
            <x-ui-sidebar-item :href="route('forecast.entities.show', ['entity' => $entity])">
                @svg('heroicon-o-cube', 'w-4 h-4 text-[var(--ui-secondary)]')
                <span class="ml-2 text-sm">{{ $entity->name }}</span>
            </x-ui-sidebar-item>
        @endforeach
    </x-ui-sidebar-list>
    --}}
</div>
