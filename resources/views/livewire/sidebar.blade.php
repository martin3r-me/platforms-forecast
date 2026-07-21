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

    {{-- Abschnitt: Planungen nach Rolle gruppiert (Instanzen erreicht man drinnen im Kontext) --}}
    <div x-show="!collapsed">
        @php
            $sbIcon = fn ($r) => match ($r) {
                'master' => ['heroicon-o-square-3-stack-3d', 'text-indigo-500'],
                'detail' => ['heroicon-o-magnifying-glass-plus', 'text-amber-500'],
                default => ['heroicon-o-chart-bar-square', 'text-[var(--ui-primary)]'],
            };
            $groups = [
                ['Konsolidierungen', $roots->filter(fn ($r) => ($planRole[$r->id] ?? '') === 'master')],
                ['Einzelpläne', $roots->filter(fn ($r) => in_array($planRole[$r->id] ?? 'single', ['single', 'instance'], true))],
                ['Detailpläne', $roots->filter(fn ($r) => ($planRole[$r->id] ?? '') === 'detail')],
            ];
        @endphp
        @if($roots->isEmpty())
            <x-ui-sidebar-list label="Planungen">
                <div class="px-3 py-2 text-xs italic text-[var(--ui-muted)]">Noch keine Planungen</div>
            </x-ui-sidebar-list>
        @endif
        @foreach($groups as [$label, $items])
            @if($items->isNotEmpty())
                <x-ui-sidebar-list label="{{ $label }}">
                    @foreach($items as $root)
                        @php [$ic, $col] = $sbIcon($planRole[$root->id] ?? 'single'); @endphp
                        <x-ui-sidebar-item :href="route('forecast.plans.show', ['uuid' => $root->uuid])">
                            @svg($ic, 'w-4 h-4 '.$col)
                            <span class="ml-2 text-sm truncate">{{ $root->name }}</span>
                        </x-ui-sidebar-item>
                    @endforeach
                </x-ui-sidebar-list>
            @endif
        @endforeach
    </div>

    {{-- Collapsed: Icons-only --}}
    <div x-show="collapsed" class="px-2 py-2 border-b border-[var(--ui-border)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('forecast.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-home', 'w-5 h-5')
            </a>
            <a href="{{ route('forecast.plans.index') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-5)]">
                @svg('heroicon-o-table-cells', 'w-5 h-5')
            </a>
        </div>
    </div>
</div>
