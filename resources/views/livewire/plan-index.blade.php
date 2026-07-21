<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Forecast" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Forecast', 'href' => route('forecast.dashboard'), 'icon' => 'presentation-chart-line'],
            ['label' => 'Planungen'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-6 max-w-4xl">
            <div>
                <h1 class="text-lg font-semibold tracking-tight text-[var(--ui-secondary)]">Planungen</h1>
                <p class="text-xs text-[var(--ui-muted)] mt-1">
                    Wie Ordner: <span class="text-indigo-600 font-medium">Ordner</span> bündeln Planungen ·
                    <span class="text-emerald-600 font-medium">Blätter</span> erfassen Zahlen ·
                    <span class="text-amber-600 font-medium">Drill-down</span> = ein Feld mit eigener Planung dahinter.
                </p>
            </div>

            @if($total === 0)
                <div class="rounded-xl border border-dashed border-[var(--ui-border)] p-10 text-center">
                    <div class="mx-auto w-12 h-12 rounded-xl bg-[var(--ui-primary)]/10 flex items-center justify-center mb-3">
                        @svg('heroicon-o-presentation-chart-line','w-6 h-6 text-[var(--ui-primary)]')
                    </div>
                    <div class="text-sm text-[var(--ui-secondary)] font-medium">Noch keine Planungen</div>
                    <div class="text-xs text-[var(--ui-muted)] mt-1">Lege eine Planung per MCP an (forecast.plan.POST).</div>
                </div>
            @endif

            {{-- ═══ Master (Konsolidierungen) — mit ihren Instanzen aufgeklappt ═══ --}}
            @if($masters->isNotEmpty())
                <section>
                    <h2 class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2.5">
                        @svg('heroicon-o-folder','w-3.5 h-3.5 text-indigo-500') Ordner
                        <span class="font-normal normal-case tracking-normal text-[var(--ui-muted)]/70">— bündeln untergeordnete Planungen</span>
                    </h2>
                    <div class="space-y-3">
                        @foreach($masters as $master)
                            <div class="rounded-xl border border-[var(--ui-border)]/60 bg-[var(--ui-surface)] p-2">
                                @include('forecast::livewire.partials.nav-plan-node', [
                                    'node' => $master,
                                    'depth' => 0,
                                    'currentUuid' => '',
                                    'ancestorIds' => [],
                                    'childrenByParent' => $childrenByParent,
                                    'planRole' => $planRole,
                                    'componentSet' => [],
                                    'drillConsumerIds' => $drillConsumerIds,
                                ])
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ═══ Einzelpläne ═══ --}}
            @if($singles->isNotEmpty())
                <section>
                    <h2 class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2.5">
                        @svg('heroicon-o-document-chart-bar','w-3.5 h-3.5 text-emerald-500') Einzelne Blätter
                        <span class="font-normal normal-case tracking-normal text-[var(--ui-muted)]/70">— eigenständig, erfassen Zahlen</span>
                    </h2>
                    <div class="overflow-hidden rounded-xl border border-[var(--ui-border)]/60 divide-y divide-[var(--ui-border)]/40">
                        @foreach($singles as $plan)
                            <a href="{{ route('forecast.plans.show', ['uuid' => $plan->uuid]) }}" wire:navigate
                               class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-[var(--ui-muted-5)] transition-colors">
                                <div class="min-w-0 flex items-center gap-2">
                                    @svg('heroicon-o-document-chart-bar','w-4 h-4 text-emerald-500 shrink-0')
                                    <div class="min-w-0">
                                        <div class="font-medium text-[var(--ui-secondary)] truncate">{{ $plan->name }}</div>
                                        <div class="text-xs text-[var(--ui-muted)]">{{ $plan->planType?->name }}</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 flex-shrink-0">
                                    <span class="text-xs text-[var(--ui-muted)]">v{{ $plan->current_version }}</span>
                                    @svg('heroicon-o-chevron-right','w-4 h-4 text-[var(--ui-muted)]')
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ═══ Detailpläne (Bausteine — normal per Drill-down erreicht) ═══ --}}
            @if($details->isNotEmpty())
                <section>
                    <h2 class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2.5">
                        @svg('heroicon-o-magnifying-glass-plus','w-3.5 h-3.5 text-amber-500') Detailpläne
                        <span class="font-normal normal-case tracking-normal text-[var(--ui-muted)]/70">— hängen an einzelnen Feldern (Drill-down), nicht am Ordnerbaum</span>
                    </h2>
                    <div class="overflow-hidden rounded-xl border border-[var(--ui-border)]/50 divide-y divide-[var(--ui-border)]/40 opacity-90">
                        @foreach($details as $plan)
                            <a href="{{ route('forecast.plans.show', ['uuid' => $plan->uuid]) }}" wire:navigate
                               class="flex items-center justify-between gap-4 px-4 py-2.5 hover:bg-[var(--ui-muted-5)] transition-colors">
                                <div class="min-w-0 flex items-center gap-2">
                                    @svg('heroicon-o-magnifying-glass-plus','w-4 h-4 text-amber-500 shrink-0')
                                    <span class="text-sm text-[var(--ui-secondary)] truncate">{{ $plan->name }}</span>
                                </div>
                                @svg('heroicon-o-chevron-right','w-4 h-4 text-[var(--ui-muted)]')
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
