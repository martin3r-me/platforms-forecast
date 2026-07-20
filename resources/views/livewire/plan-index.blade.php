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
        <div class="space-y-4">
            <h1 class="text-lg font-semibold tracking-tight text-[var(--ui-secondary)]">Planungen</h1>

            @if($plans->isEmpty())
                <div class="rounded-xl border border-dashed border-[var(--ui-border)] p-10 text-center">
                    <div class="mx-auto w-12 h-12 rounded-xl bg-[var(--ui-primary)]/10 flex items-center justify-center mb-3">
                        @svg('heroicon-o-presentation-chart-line','w-6 h-6 text-[var(--ui-primary)]')
                    </div>
                    <div class="text-sm text-[var(--ui-secondary)] font-medium">Noch keine Planungen</div>
                    <div class="text-xs text-[var(--ui-muted)] mt-1">Lege eine Planung per MCP an (forecast.plan.POST).</div>
                </div>
            @else
                <div class="overflow-hidden rounded-xl border border-[var(--ui-border)]/60 divide-y divide-[var(--ui-border)]/40">
                    @foreach($plans as $plan)
                        <a href="{{ route('forecast.plans.show', ['uuid' => $plan->uuid]) }}" wire:navigate
                           class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-[var(--ui-muted-5)] transition-colors">
                            <div class="min-w-0">
                                <div class="font-medium text-[var(--ui-secondary)] truncate">{{ $plan->name }}</div>
                                <div class="text-xs text-[var(--ui-muted)]">
                                    {{ $plan->planType?->name }}
                                    @if($plan->organization_entity_id) · Knoten #{{ $plan->organization_entity_id }} @endif
                                    · {{ ucfirst($plan->org_mode?->value ?? 'detail') }}
                                </div>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <span class="text-xs text-[var(--ui-muted)]">v{{ $plan->current_version }}</span>
                                @svg('heroicon-o-chevron-right','w-4 h-4 text-[var(--ui-muted)]')
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </x-ui-page-container>
</x-ui-page>
