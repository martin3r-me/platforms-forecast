@php
    $fmt = fn ($v) => number_format((float) $v, 0, ',', '.');
@endphp

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

    <x-ui-page-container>
        <div class="space-y-5">

            {{-- Kopf: Name + Zoom-Breadcrumb --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold tracking-tight text-[var(--ui-secondary)]">{{ $plan->name }}</h1>
                    <div class="text-xs text-[var(--ui-muted)]">
                        {{ $plan->planType?->name }} · Version {{ $plan->current_version }}
                        @if($plan->organization_entity_id) · Knoten #{{ $plan->organization_entity_id }} @endif
                    </div>
                </div>

                {{-- Zoom-Breadcrumb --}}
                <div class="flex items-center gap-1 text-sm">
                    @foreach($breadcrumb as $i => $crumb)
                        @if($i > 0)
                            <span class="text-[var(--ui-muted)]">/</span>
                        @endif
                        <button type="button" wire:click="zoom('{{ $crumb['bucket'] }}')"
                            class="px-2 py-0.5 rounded-md hover:bg-[var(--ui-muted-10)] transition-colors
                                {{ $loop->last ? 'font-semibold text-[var(--ui-secondary)]' : 'text-[var(--ui-muted)]' }}">
                            {{ $crumb['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="text-xs text-[var(--ui-muted)] flex items-center gap-4">
                <span>Ebene: <span class="font-medium text-[var(--ui-secondary)]">{{ ucfirst($level) }}</span></span>
                @if($canZoom)
                    <span class="inline-flex items-center gap-1">@svg('heroicon-o-magnifying-glass-plus','w-3.5 h-3.5') Klick auf eine Spalte zoomt hinein</span>
                @endif
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-[var(--ui-primary)]"></span> eingegeben</span>
                <span class="inline-flex items-center gap-1"><span class="text-[10px] font-bold px-1 rounded bg-emerald-500/15 text-emerald-600">+</span> Plus</span>
                <span class="inline-flex items-center gap-1"><span class="text-[10px] font-bold px-1 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">D</span> Detail</span>
            </div>

            {{-- Grid --}}
            <div class="overflow-x-auto rounded-xl border border-[var(--ui-border)]/60 bg-[var(--ui-surface)]">
                <table class="min-w-full text-sm border-separate border-spacing-0">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 bg-[var(--ui-muted-5)] text-left px-4 py-3 font-medium text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 min-w-[180px]">
                                Zeile
                            </th>
                            @if(!empty($summary))
                                <th class="bg-[var(--ui-muted-5)] text-right px-4 py-3 font-semibold text-[var(--ui-secondary)] border-b border-r border-[var(--ui-border)]/60 whitespace-nowrap">
                                    {{ $breadcrumb[count($breadcrumb)-1]['label'] }}
                                    <div class="text-[10px] font-normal text-[var(--ui-muted)]">Ebene gesamt</div>
                                </th>
                            @endif
                            @foreach($columns as $col)
                                <th class="bg-[var(--ui-muted-5)] text-right px-3 py-3 border-b border-[var(--ui-border)]/60 whitespace-nowrap
                                    {{ $canZoom ? 'cursor-pointer hover:bg-[var(--ui-muted-10)]' : '' }}"
                                    @if($canZoom) wire:click="zoom('{{ $col['bucket'] }}')" @endif>
                                    <span class="font-medium text-[var(--ui-secondary)]">{{ $col['label'] }}</span>
                                    @if($canZoom)
                                        @svg('heroicon-o-chevron-right','w-3 h-3 inline text-[var(--ui-muted)]')
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $rowKey => $row)
                            <tr class="group">
                                <td class="sticky left-0 z-10 bg-[var(--ui-surface)] group-hover:bg-[var(--ui-muted-5)] px-4 py-3 border-b border-[var(--ui-border)]/40">
                                    <div class="font-medium text-[var(--ui-secondary)]">{{ $row['label'] }}</div>
                                    <div class="text-[10px] uppercase tracking-wide text-[var(--ui-muted)]">{{ $row['kind'] }}</div>
                                </td>

                                @if(!empty($summary))
                                    @php $s = $summary[$rowKey] ?? ['value'=>0,'rest'=>0]; @endphp
                                    <td class="text-right px-4 py-3 border-b border-r border-[var(--ui-border)]/40 bg-[var(--ui-muted-5)]/40">
                                        <div class="font-semibold text-[var(--ui-secondary)]">{{ $fmt($s['value']) }}</div>
                                        @if(($s['rest'] ?? 0) > 0)
                                            <div class="text-[10px] text-amber-600">Rest {{ $fmt($s['rest']) }}</div>
                                        @endif
                                    </td>
                                @endif

                                @foreach($columns as $col)
                                    @php $cell = $row['cells'][$col['bucket']] ?? null; @endphp
                                    <td class="text-right px-3 py-3 border-b border-[var(--ui-border)]/40 whitespace-nowrap group-hover:bg-[var(--ui-muted-5)]">
                                        @if($cell)
                                            <div class="flex items-center justify-end gap-1.5">
                                                @if($cell['entered'])
                                                    @if($cell['mode'] === 'plus')
                                                        <span class="text-[9px] font-bold px-1 rounded bg-emerald-500/15 text-emerald-600">+</span>
                                                    @else
                                                        <span class="text-[9px] font-bold px-1 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">D</span>
                                                    @endif
                                                @endif
                                                <span class="{{ $cell['entered'] ? 'font-semibold text-[var(--ui-secondary)]' : ($cell['value'] > 0 ? 'text-[var(--ui-secondary)]' : 'text-[var(--ui-muted)]') }}">
                                                    {{ ($cell['value'] == 0 && !$cell['entered']) ? '–' : $fmt($cell['value']) }}
                                                </span>
                                            </div>
                                            @if(($cell['rest'] ?? 0) > 0)
                                                <div class="text-[10px] text-amber-600">Rest {{ $fmt($cell['rest']) }}</div>
                                            @endif
                                        @else
                                            <span class="text-[var(--ui-muted)]">–</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach

                        @if(empty($rows))
                            <tr>
                                <td colspan="99" class="px-4 py-10 text-center text-sm text-[var(--ui-muted)]">
                                    Dieser Typ hat noch keine Zeilen.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
