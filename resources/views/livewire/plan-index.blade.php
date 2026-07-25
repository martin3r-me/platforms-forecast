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
                <h1 class="text-lg font-semibold tracking-tight text-[color:var(--nx-text)]">Planungen</h1>
                <p class="text-xs text-[color:var(--nx-muted)] mt-1">
                    Wie Ordner: <span class="text-indigo-600 font-medium">Ordner</span> bündeln Planungen ·
                    <span class="text-emerald-600 font-medium">Blätter</span> erfassen Zahlen ·
                    <span class="text-amber-600 font-medium">Drill-down</span> = ein Feld mit eigener Planung dahinter.
                </p>
            </div>

            @if($total === 0)
                <x-nx-card>
                    <x-nx-empty icon="heroicon-o-presentation-chart-line">
                        <div class="text-[color:var(--nx-text)] font-medium">Noch keine Planungen</div>
                        <div class="mt-1">Lege eine Planung per MCP an (forecast.plan.POST).</div>
                    </x-nx-empty>
                </x-nx-card>
            @endif

            {{-- ═══ Master (Konsolidierungen) — mit ihren Instanzen aufgeklappt ═══ --}}
            @if($masters->isNotEmpty())
                <section>
                    <h2 class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-[color:var(--nx-muted)] mb-2.5">
                        @svg('heroicon-o-folder','w-3.5 h-3.5 text-indigo-500') Ordner
                        <span class="font-normal normal-case tracking-normal text-[color:var(--nx-faint)]">— bündeln untergeordnete Planungen</span>
                    </h2>
                    <div class="space-y-3">
                        @foreach($masters as $master)
                            <div class="rounded-lg border border-[color:var(--nx-line)] bg-[color:var(--nx-surface)] p-2">
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
                    <h2 class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-[color:var(--nx-muted)] mb-2.5">
                        @svg('heroicon-o-document-chart-bar','w-3.5 h-3.5 text-emerald-500') Einzelne Blätter
                        <span class="font-normal normal-case tracking-normal text-[color:var(--nx-faint)]">— eigenständig, erfassen Zahlen</span>
                    </h2>
                    <x-nx-card flush>
                        <div class="divide-y divide-[color:var(--nx-line)]">
                            @foreach($singles as $plan)
                                <x-nx-list-item
                                    :href="route('forecast.plans.show', ['uuid' => $plan->uuid])"
                                    :title="$plan->name"
                                    :subtitle="$plan->planType?->name"
                                    :meta="'v'.$plan->current_version">
                                    <x-slot name="leading">
                                        @svg('heroicon-o-document-chart-bar','w-4 h-4 text-emerald-500')
                                    </x-slot>
                                </x-nx-list-item>
                            @endforeach
                        </div>
                    </x-nx-card>
                </section>
            @endif

            {{-- ═══ Detailpläne (Bausteine — normal per Drill-down erreicht) ═══ --}}
            @if($details->isNotEmpty())
                <section>
                    <h2 class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-[color:var(--nx-muted)] mb-2.5">
                        @svg('heroicon-o-magnifying-glass-plus','w-3.5 h-3.5 text-amber-500') Detailpläne
                        <span class="font-normal normal-case tracking-normal text-[color:var(--nx-faint)]">— hängen an einzelnen Feldern (Drill-down), nicht am Ordnerbaum</span>
                    </h2>
                    <x-nx-card flush class="opacity-90">
                        <div class="divide-y divide-[color:var(--nx-line)]">
                            @foreach($details as $plan)
                                <x-nx-list-item
                                    :href="route('forecast.plans.show', ['uuid' => $plan->uuid])"
                                    :title="$plan->name">
                                    <x-slot name="leading">
                                        @svg('heroicon-o-magnifying-glass-plus','w-4 h-4 text-amber-500')
                                    </x-slot>
                                </x-nx-list-item>
                            @endforeach
                        </div>
                    </x-nx-card>
                </section>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
