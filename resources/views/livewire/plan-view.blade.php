@php
    $fmt = fn ($v) => number_format((float) $v, 0, ',', '.');
    $rowsShown = collect($rows);
    $kpiRows = $rowsShown->take(4);
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
        <div class="space-y-6">

            {{-- ═══════════ Kontext-Kopf: wer bin ich, wo bin ich ═══════════ --}}
            <div class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold tracking-tight text-[var(--ui-secondary)]">{{ $plan->name }}</h1>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">
                                @svg('heroicon-o-squares-2x2','w-3 h-3') {{ $plan->planType?->name }}
                            </span>
                            @if($plan->organization_entity_id)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">
                                    @svg('heroicon-o-building-office-2','w-3 h-3') Knoten #{{ $plan->organization_entity_id }}
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">
                                @svg('heroicon-o-clock','w-3 h-3') Version {{ $plan->current_version }}
                            </span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">
                                @svg('heroicon-o-eye','w-3 h-3') Nur Ansicht
                            </span>
                        </div>
                    </div>

                    {{-- Ebenen-Anzeige --}}
                    <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/50">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-[var(--ui-muted)]">Ebene</span>
                        @foreach(['Jahr','Monat','Tag','Stunde'] as $lvl)
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-md
                                {{ $levelLabel === $lvl ? 'bg-[var(--ui-primary)] text-[var(--ui-on-primary)]' : 'text-[var(--ui-muted)]' }}">
                                {{ $lvl }}
                            </span>
                        @endforeach
                    </div>
                </div>

                {{-- Zoom-Pfad (wo bin ich) + Kontext-Satz (was sehe ich) --}}
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <nav class="flex items-center gap-1 flex-wrap">
                        @foreach($breadcrumb as $i => $crumb)
                            @if($i > 0)
                                @svg('heroicon-o-chevron-right','w-3.5 h-3.5 text-[var(--ui-muted)]/60')
                            @endif
                            <button type="button" wire:click="zoom('{{ $crumb['bucket'] }}')"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-sm transition-colors
                                    {{ $loop->last
                                        ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-semibold ring-1 ring-[var(--ui-primary)]/20'
                                        : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-10)] hover:text-[var(--ui-secondary)]' }}">
                                @if($i === 0)@svg('heroicon-o-calendar-days','w-3.5 h-3.5')@endif
                                {{ $crumb['label'] }}
                            </button>
                        @endforeach
                    </nav>

                    <div class="flex items-center gap-1.5 text-sm text-[var(--ui-muted)]">
                        <span class="text-[var(--ui-secondary)] font-medium">{{ $scopeCaption }}</span>
                        @if($canZoom)
                            <span class="inline-flex items-center gap-1 text-xs">
                                · @svg('heroicon-o-cursor-arrow-rays','w-3.5 h-3.5') Spalte anklicken zum Reinzoomen
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ═══════════ KPI-Karten: was ist Sache ═══════════ --}}
            @if($kpiRows->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    @foreach($kpiRows as $rowKey => $row)
                        @php
                            $t = $scopeTotals[$rowKey] ?? ['value' => 0, 'rest' => 0];
                            $concrete = max(0, $t['value'] - $t['rest']);
                            $pct = $t['value'] > 0 ? round($concrete / $t['value'] * 100) : 100;
                        @endphp
                        <div class="relative overflow-hidden rounded-2xl border border-[var(--ui-border)]/60 bg-[var(--ui-surface)] p-4">
                            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[var(--ui-primary)]/40 to-transparent"></div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium text-[var(--ui-muted)] truncate">{{ $row['label'] }}</span>
                                <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]/70">{{ $row['kind'] }}</span>
                            </div>
                            <div class="mt-1.5 text-2xl font-semibold tracking-tight text-[var(--ui-secondary)] tabular-nums">{{ $fmt($t['value']) }}</div>
                            <div class="mt-3 h-1.5 rounded-full bg-[var(--ui-muted-10)] overflow-hidden flex">
                                <div class="h-full bg-[var(--ui-primary)]" style="width: {{ $pct }}%"></div>
                                <div class="h-full bg-amber-400/70" style="width: {{ 100 - $pct }}%"></div>
                            </div>
                            <div class="mt-1.5 flex items-center justify-between text-[11px]">
                                <span class="text-[var(--ui-muted)]">{{ $pct }}% konkret</span>
                                @if($t['rest'] > 0)
                                    <span class="text-amber-600 font-medium">Rest {{ $fmt($t['rest']) }} offen</span>
                                @else
                                    <span class="text-emerald-600 font-medium">vollständig</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- ═══════════ Grid ═══════════ --}}
            <div class="rounded-2xl border border-[var(--ui-border)]/60 bg-[var(--ui-surface)] overflow-hidden">
                {{-- Grid-Kopf: Ebene + Legende --}}
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-[var(--ui-border)]/50 bg-[var(--ui-muted-5)]">
                    <div class="text-sm font-medium text-[var(--ui-secondary)]">{{ $scopeCaption }}</div>
                    <div class="flex items-center gap-3 text-[11px] text-[var(--ui-muted)]">
                        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-[var(--ui-primary)]"></span> konkret</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-400"></span> Rest (offen)</span>
                        <span class="inline-flex items-center gap-1"><span class="text-[9px] font-bold px-1 rounded bg-emerald-500/15 text-emerald-600">+</span> Plus</span>
                        <span class="inline-flex items-center gap-1"><span class="text-[9px] font-bold px-1 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">D</span> Detail</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-separate border-spacing-0 text-sm">
                        <thead>
                            <tr>
                                <th class="sticky left-0 z-20 bg-[var(--ui-muted-5)] text-left px-4 py-2.5 font-medium text-[11px] uppercase tracking-wider text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 min-w-[190px]">
                                    Zeile
                                </th>
                                @if(!empty($summary))
                                    <th class="bg-[var(--ui-primary)]/[0.04] text-right px-4 py-2.5 border-b border-r border-[var(--ui-border)]/60 whitespace-nowrap min-w-[120px]">
                                        <div class="text-xs font-semibold text-[var(--ui-secondary)]">{{ $breadcrumb[count($breadcrumb)-1]['label'] }}</div>
                                        <div class="text-[10px] font-normal text-[var(--ui-muted)]">Ebene gesamt</div>
                                    </th>
                                @endif
                                @foreach($columns as $col)
                                    <th class="group/col bg-[var(--ui-muted-5)] text-right px-3 py-2.5 border-b border-[var(--ui-border)]/60 whitespace-nowrap min-w-[92px]
                                        {{ $canZoom ? 'cursor-pointer hover:bg-[var(--ui-primary)]/[0.06] transition-colors' : '' }}"
                                        @if($canZoom) wire:click="zoom('{{ $col['bucket'] }}')" @endif>
                                        <span class="inline-flex items-center gap-1 text-xs font-medium text-[var(--ui-secondary)]">
                                            {{ $col['label'] }}
                                            @if($canZoom)
                                                @svg('heroicon-o-magnifying-glass-plus','w-3 h-3 text-[var(--ui-muted)]/50 group-hover/col:text-[var(--ui-primary)] transition-colors')
                                            @endif
                                        </span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $rowKey => $row)
                                <tr class="group/row">
                                    <td class="sticky left-0 z-10 bg-[var(--ui-surface)] group-hover/row:bg-[var(--ui-muted-5)] px-4 py-3 border-b border-[var(--ui-border)]/40 transition-colors">
                                        <div class="font-medium text-[var(--ui-secondary)]">{{ $row['label'] }}</div>
                                        <div class="text-[10px] uppercase tracking-wide text-[var(--ui-muted)]/70">{{ $row['kind'] }}</div>
                                    </td>

                                    @if(!empty($summary))
                                        @php
                                            $s = $summary[$rowKey] ?? ['value' => 0, 'rest' => 0];
                                            $sConcrete = max(0, $s['value'] - $s['rest']);
                                            $sPct = $s['value'] > 0 ? round($sConcrete / $s['value'] * 100) : 100;
                                        @endphp
                                        <td class="text-right px-4 py-3 border-b border-r border-[var(--ui-border)]/40 bg-[var(--ui-primary)]/[0.04] align-top">
                                            <div class="font-semibold text-[var(--ui-secondary)] tabular-nums">{{ $fmt($s['value']) }}</div>
                                            @if($s['rest'] > 0)
                                                <div class="mt-1.5 h-1 rounded-full bg-[var(--ui-muted-10)] overflow-hidden flex">
                                                    <div class="h-full bg-[var(--ui-primary)]" style="width: {{ $sPct }}%"></div>
                                                    <div class="h-full bg-amber-400/70" style="width: {{ 100 - $sPct }}%"></div>
                                                </div>
                                                <div class="mt-0.5 text-[10px] text-amber-600">Rest {{ $fmt($s['rest']) }}</div>
                                            @endif
                                        </td>
                                    @endif

                                    @foreach($columns as $col)
                                        @php $cell = $row['cells'][$col['bucket']] ?? null; @endphp
                                        <td class="text-right px-3 py-3 border-b border-[var(--ui-border)]/40 whitespace-nowrap align-top group-hover/row:bg-[var(--ui-muted-5)]/60 transition-colors">
                                            @if($cell && ($cell['entered'] || $cell['value'] != 0))
                                                @php
                                                    $val = $cell['value']; $rest = $cell['rest'];
                                                    $concrete = max(0, $val - $rest);
                                                    $pct = $val > 0 ? round($concrete / $val * 100) : 100;
                                                @endphp
                                                <div class="flex items-center justify-end gap-1.5">
                                                    @if($cell['entered'])
                                                        @if($cell['mode'] === 'plus')
                                                            <span class="text-[9px] font-bold leading-none px-1 py-0.5 rounded bg-emerald-500/15 text-emerald-600">+</span>
                                                        @else
                                                            <span class="text-[9px] font-bold leading-none px-1 py-0.5 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">D</span>
                                                        @endif
                                                    @endif
                                                    <span class="tabular-nums {{ $cell['entered'] ? 'font-semibold text-[var(--ui-secondary)]' : 'text-[var(--ui-secondary)]/80' }}">{{ $fmt($val) }}</span>
                                                </div>
                                                @if($rest > 0)
                                                    <div class="mt-1.5 h-1 rounded-full bg-[var(--ui-muted-10)] overflow-hidden flex">
                                                        <div class="h-full bg-[var(--ui-primary)]" style="width: {{ $pct }}%"></div>
                                                        <div class="h-full bg-amber-400/70" style="width: {{ 100 - $pct }}%"></div>
                                                    </div>
                                                    <div class="mt-0.5 text-[10px] text-amber-600">Rest {{ $fmt($rest) }}</div>
                                                @endif
                                            @else
                                                <span class="text-[var(--ui-muted)]/40">·</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach

                            @if(empty($rows))
                                <tr>
                                    <td colspan="99" class="px-4 py-12 text-center text-sm text-[var(--ui-muted)]">
                                        Dieser Typ hat noch keine Zeilen.
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
