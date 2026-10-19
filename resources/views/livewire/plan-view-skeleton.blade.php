{{-- Leichtes Lade-Skelett: rendert OHNE die teure Baum-Konsolidierung, damit der erste
     Seitenaufbau sofort da ist. wire:init="loadGrid" stößt den Folge-Request an, der das
     echte Grid (plan-view.blade.php) rendert. Nur Plan-Metadaten, keine Zellberechnung. --}}
<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Forecast" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Forecast', 'href' => route('forecast.dashboard'), 'icon' => 'presentation-chart-line'],
            ['label' => 'Planungen', 'href' => route('forecast.plans.index')],
            ['label' => $plan->name],
        ]" />
    </x-slot>

    <x-ui-page-container width="full">
        <div wire:init="loadGrid" class="space-y-5" aria-busy="true" aria-label="Planung wird geladen">

            <div class="space-y-5 animate-pulse motion-reduce:animate-none">
                {{-- Kopf --}}
                <div class="space-y-3">
                    <h1 class="text-xl font-semibold tracking-tight text-[var(--nx-text)]">{{ $plan->name }}</h1>
                    <div class="flex flex-wrap gap-2">
                        @foreach(range(1, 4) as $i)
                            <span class="h-5 w-24 rounded-full bg-[var(--nx-hover)]"></span>
                        @endforeach
                    </div>
                </div>

                {{-- KPI-Streifen --}}
                <div class="flex flex-wrap gap-x-8 gap-y-2">
                    @foreach(range(1, 6) as $i)
                        <div class="space-y-1.5">
                            <div class="h-2.5 w-20 rounded bg-[var(--nx-hover)]"></div>
                            <div class="h-5 w-28 rounded bg-[var(--nx-hover)]"></div>
                        </div>
                    @endforeach
                </div>

                {{-- Grid-Skelett --}}
                <div class="rounded-2xl border border-[var(--nx-line)]/60 bg-[var(--nx-surface)] overflow-hidden">
                    <div class="h-11 border-b border-[var(--nx-line)]/50 bg-[var(--nx-hover)]"></div>
                    <div class="divide-y divide-[var(--nx-line)]/30">
                        @php $widths = [42,56,31,60,46,50,35,58,43,48,33,52,38,55]; @endphp
                        @foreach($widths as $w)
                            <div class="flex items-center gap-4 px-4 py-2.5">
                                <div class="h-3 rounded bg-[var(--nx-hover)]" style="width: {{ $w }}%"></div>
                                <div class="ml-auto h-3 w-20 rounded bg-[var(--nx-hover)]"></div>
                                <div class="h-3 w-20 rounded bg-[var(--nx-hover)]"></div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <p class="flex items-center gap-2 text-xs text-[var(--nx-muted)]">
                <svg class="w-3.5 h-3.5 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" stroke-dasharray="44" stroke-linecap="round" opacity="0.4"/>
                </svg>
                Konsolidierung wird berechnet …
            </p>

        </div>
    </x-ui-page-container>
</x-ui-page>
