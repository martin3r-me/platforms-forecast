@php
    $fmt = fn ($v) => number_format((float) $v, 0, ',', '.');
    $kpiRows = collect($rows)->take(4);

    // Vorzeichen / Farbton / Betrag je nach Zeilen-Richtung (bzw. Netto-Wert)
    $signOf = function ($rk, $v) use ($rowInfo) {
        $info = $rowInfo[$rk] ?? [];
        if (($info['signMode'] ?? 'direction') === 'net') {
            return $v < 0 ? '−' : ($v > 0 ? '+' : '');
        }
        $d = $info['direction'] ?? 'neutral';
        return $d === 'income' ? '+' : ($d === 'expense' ? '−' : '');
    };
    $toneOf = function ($rk, $v) use ($rowInfo) {
        $info = $rowInfo[$rk] ?? [];
        $d = (($info['signMode'] ?? 'direction') === 'net')
            ? ($v < 0 ? 'expense' : ($v > 0 ? 'income' : 'neutral'))
            : ($info['direction'] ?? 'neutral');
        return $d === 'income' ? 'text-emerald-600' : ($d === 'expense' ? 'text-rose-600' : 'text-[var(--ui-secondary)]');
    };
    $magOf = fn ($rk, $v) => (($rowInfo[$rk]['signMode'] ?? 'direction') === 'net') ? abs($v) : $v;
    $unitOf = fn ($rk) => $rowInfo[$rk]['unit'] ?? '';
    // %-Zeilen mit 1 Nachkommastelle, sonst ganzzahlig
    $fmtRow = function ($rk, $v) use ($rowInfo, $fmt) {
        return ($rowInfo[$rk]['unit'] ?? '') === '%' ? number_format((float) $v, 1, ',', '.') : $fmt($v);
    };
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

            {{-- ═══════════ Kontext-Kopf ═══════════ --}}
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
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--ui-muted-10)] text-[var(--ui-muted)]" title="Vorlauf: öffnet X Tage vor Periodenstart · Nachlauf: bleibt Y Tage nach Periodenende offen">
                                @svg('heroicon-o-lock-closed','w-3 h-3') Vorlauf {{ $lock['lead_days'] }} T · Nachlauf {{ $lock['grace_days'] }} T
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 px-3 py-2 rounded-xl bg-[var(--ui-muted-5)] border border-[var(--ui-border)]/50">
                        <span class="text-[10px] font-medium uppercase tracking-wider text-[var(--ui-muted)]">Ebene</span>
                        @foreach(['Jahr','Quartal','Monat','Tag','Stunde'] as $lvl)
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-md
                                {{ $levelLabel === $lvl ? 'bg-[var(--ui-primary)] text-[var(--ui-on-primary)]' : 'text-[var(--ui-muted)]' }}">
                                {{ $lvl }}
                            </span>
                        @endforeach
                    </div>
                </div>

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
                            <span class="inline-flex items-center gap-1 text-xs">· @svg('heroicon-o-cursor-arrow-rays','w-3.5 h-3.5') Spalte anklicken zum Reinzoomen</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ═══════════ KPI-Karten ═══════════ --}}
            @if($kpiRows->isNotEmpty())
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    @foreach($kpiRows as $rowKey => $row)
                        @php
                            $t = $meta[$rowKey] ?? ['value' => 0, 'rest' => 0, 'committed' => 0, 'implied' => false];
                            $isF = $rowInfo[$rowKey]['isFormula'] ?? false;
                            $pct = $t['value'] != 0 ? round($t['committed'] / $t['value'] * 100) : 100;
                        @endphp
                        <div class="relative overflow-hidden rounded-2xl border border-[var(--ui-border)]/60 bg-[var(--ui-surface)] p-4">
                            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[var(--ui-primary)]/40 to-transparent"></div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium text-[var(--ui-muted)] truncate">{{ $row['label'] }}</span>
                                @if($isF)
                                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">{{ $rowInfo[$rowKey]['aggLabel'] }}</span>
                                @else
                                    <span class="text-[10px] uppercase tracking-wider text-[var(--ui-muted)]/70">{{ $unitOf($rowKey) ?: $row['kind'] }}</span>
                                @endif
                            </div>
                            <div class="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums {{ $toneOf($rowKey, $t['value']) }}">
                                {{ $t['implied'] ? '≈ ' : '' }}{{ $signOf($rowKey, $t['value']) }}{{ $fmtRow($rowKey, $magOf($rowKey, $t['value'])) }}<span class="text-sm font-normal text-[var(--ui-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span>
                            </div>
                            @if($isF)
                                <div class="mt-3 text-[11px] text-[var(--ui-muted)]">berechnet aus {{ count($rowInfo[$rowKey]['sources']) }} Zeilen</div>
                            @else
                                <div class="mt-3 h-1.5 rounded-full bg-[var(--ui-muted-10)] overflow-hidden flex">
                                    <div class="h-full bg-[var(--ui-primary)]" style="width: {{ $pct }}%"></div>
                                    <div class="h-full bg-amber-400/70" style="width: {{ 100 - $pct }}%"></div>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between text-[11px]">
                                    <span class="text-[var(--ui-muted)]">{{ $pct }}% verbindlich</span>
                                    @if($t['rest'] > 0)
                                        <span class="text-amber-600 font-medium">Rest {{ $fmt($t['rest']) }} verteilt</span>
                                    @else
                                        <span class="text-emerald-600 font-medium">voll verplant</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- ═══════════ Grid ═══════════ --}}
            <div class="rounded-2xl border border-[var(--ui-border)]/60 bg-[var(--ui-surface)] overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-[var(--ui-border)]/50 bg-[var(--ui-muted-5)]">
                    <div class="text-sm font-medium text-[var(--ui-secondary)]">{{ $scopeCaption }}</div>
                    <div class="flex items-center gap-3 text-[11px] text-[var(--ui-muted)]">
                        <span class="inline-flex items-center gap-1"><span class="text-[9px] font-bold px-1 rounded bg-emerald-500/15 text-emerald-600">+</span> Plus</span>
                        <span class="inline-flex items-center gap-1"><span class="text-[9px] font-bold px-1 rounded bg-[var(--ui-primary)]/15 text-[var(--ui-primary)]">V</span> Verteilen</span>
                        <span class="inline-flex items-center gap-1 italic">≈ verteilter Rest</span>
                        <span class="inline-flex items-center gap-1"><span class="text-[9px] font-bold px-1 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">ƒ</span> berechnet</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full border-separate border-spacing-0 text-sm">
                        <thead>
                            <tr>
                                <th class="sticky left-0 z-20 bg-[var(--ui-muted-5)] text-left px-4 py-2.5 font-medium text-[11px] uppercase tracking-wider text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 min-w-[200px]">Zeile</th>
                                @if($zoomed)
                                    <th class="bg-[var(--ui-primary)]/[0.04] text-right px-4 py-2.5 border-b border-r border-[var(--ui-border)]/60 whitespace-nowrap min-w-[120px]">
                                        <div class="text-xs font-semibold text-[var(--ui-secondary)]">{{ $breadcrumb[count($breadcrumb)-1]['label'] }}</div>
                                        <div class="text-[10px] font-normal text-[var(--ui-muted)]">Ebene gesamt</div>
                                    </th>
                                @endif
                                @foreach($columns as $col)
                                    @php $st = $colStatus[$col['bucket']] ?? ['state' => 'mixed', 'days' => null]; @endphp
                                    <th class="group/col bg-[var(--ui-muted-5)] text-right px-3 py-2.5 border-b border-[var(--ui-border)]/60 whitespace-nowrap min-w-[96px]
                                        {{ $canZoom ? 'cursor-pointer hover:bg-[var(--ui-primary)]/[0.06] transition-colors' : '' }}
                                        {{ $st['state'] === 'closed' ? 'opacity-60' : '' }}"
                                        @if($canZoom) wire:click="zoom('{{ $col['bucket'] }}')" @endif>
                                        <div class="flex flex-col items-end gap-0.5">
                                            <span class="inline-flex items-center gap-1 text-xs font-medium text-[var(--ui-secondary)]">
                                                {{ $col['label'] }}
                                                @if($canZoom)@svg('heroicon-o-magnifying-glass-plus','w-3 h-3 text-[var(--ui-muted)]/50 group-hover/col:text-[var(--ui-primary)] transition-colors')@endif
                                            </span>
                                            @if($st['state'] === 'open')
                                                <span class="inline-flex items-center gap-0.5 text-[9px] font-medium text-emerald-600">@svg('heroicon-o-lock-open','w-2.5 h-2.5') offen · noch {{ $st['days'] }} T</span>
                                            @elseif($st['state'] === 'pending')
                                                <span class="inline-flex items-center gap-0.5 text-[9px] text-[var(--ui-muted)]">@svg('heroicon-o-clock','w-2.5 h-2.5') öffnet in {{ $st['days'] }} T</span>
                                            @elseif($st['state'] === 'closed')
                                                <span class="inline-flex items-center gap-0.5 text-[9px] text-[var(--ui-muted)]/70">@svg('heroicon-o-lock-closed','w-2.5 h-2.5') zu</span>
                                            @endif
                                        </div>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $rowKey => $row)
                                @php $isF = $rowInfo[$rowKey]['isFormula'] ?? false; @endphp
                                <tr class="group/row {{ $isF ? 'bg-[var(--ui-muted-5)]/40' : '' }}">
                                    {{-- Zeilen-Kopf --}}
                                    <td class="sticky left-0 z-10 {{ $isF ? 'bg-[var(--ui-muted-5)]/80' : 'bg-[var(--ui-surface)]' }} group-hover/row:bg-[var(--ui-muted-5)] px-4 py-3 border-b border-[var(--ui-border)]/40 transition-colors">
                                        <div class="flex items-center gap-1.5">
                                            @if($isF)<span class="text-[9px] font-bold px-1 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">ƒ</span>@endif
                                            <span class="font-medium text-[var(--ui-secondary)]">{{ $row['label'] }}</span>
                                            @php $refPlans = $rowInfo[$rowKey]['refPlans'] ?? []; @endphp
                                            @if(!empty($refPlans) && ($refPlans[0]['uuid'] ?? null))
                                                <a href="{{ route('forecast.plans.show', ['uuid' => $refPlans[0]['uuid']]) }}" wire:navigate
                                                   class="inline-flex items-center gap-0.5 text-[10px] font-medium text-[var(--ui-primary)] hover:underline px-1 py-0.5 rounded hover:bg-[var(--ui-primary)]/10"
                                                   title="Detailplan öffnen: {{ $refPlans[0]['name'] }}{{ count($refPlans) > 1 ? ' (+'.(count($refPlans)-1).' weitere)' : '' }}">
                                                    @svg('heroicon-o-arrow-top-right-on-square','w-3 h-3') Detail
                                                </a>
                                            @endif
                                        </div>
                                        <div class="text-[10px] uppercase tracking-wide text-[var(--ui-muted)]/70">
                                            @if($isF){{ $rowInfo[$rowKey]['aggLabel'] }}@else{{ $rowInfo[$rowKey]['direction'] === 'income' ? 'Ertrag +' : ($rowInfo[$rowKey]['direction'] === 'expense' ? 'Aufwand −' : 'Messgröße') }}@endif
                                            @if($unitOf($rowKey)) · {{ $unitOf($rowKey) }}@endif
                                        </div>
                                    </td>

                                    {{-- Summenspalte (gezoomt) --}}
                                    @if($zoomed)
                                        @php
                                            $s = $meta[$rowKey] ?? ['value' => 0, 'rest' => 0, 'committed' => 0, 'implied' => false];
                                            $sPct = $s['value'] != 0 ? round($s['committed'] / $s['value'] * 100) : 100;
                                        @endphp
                                        <td class="text-right px-4 py-3 border-b border-r border-[var(--ui-border)]/40 bg-[var(--ui-primary)]/[0.04] align-top">
                                            <div class="font-semibold tabular-nums {{ $toneOf($rowKey, $s['value']) }}">{{ $s['implied'] ? '≈ ' : '' }}{{ $signOf($rowKey, $s['value']) }}{{ $fmtRow($rowKey, $magOf($rowKey, $s['value'])) }}<span class="text-[10px] font-normal text-[var(--ui-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span></div>
                                            @if(! $isF && $s['rest'] > 0)
                                                <div class="mt-1.5 h-1 rounded-full bg-[var(--ui-muted-10)] overflow-hidden flex">
                                                    <div class="h-full bg-[var(--ui-primary)]" style="width: {{ $sPct }}%"></div>
                                                    <div class="h-full bg-amber-400/70" style="width: {{ 100 - $sPct }}%"></div>
                                                </div>
                                                <div class="mt-0.5 text-[10px] text-amber-600">Rest {{ $fmt($s['rest']) }} verteilt</div>
                                            @endif
                                        </td>
                                    @endif

                                    {{-- Spalten --}}
                                    @foreach($columns as $col)
                                        <td class="text-right px-3 py-3 border-b border-[var(--ui-border)]/40 whitespace-nowrap align-top group-hover/row:bg-[var(--ui-muted-5)]/60 transition-colors {{ (($colStatus[$col['bucket']]['state'] ?? '') === 'closed') ? 'opacity-45' : '' }}">
                                            @if($isF)
                                                {{-- Formula: berechnet, read-only --}}
                                                @php $fv = $formulaCells[$rowKey][$col['bucket']] ?? 0; @endphp
                                                @if($fv != 0)
                                                    <span class="tabular-nums {{ $toneOf($rowKey, $fv) }}">{{ $signOf($rowKey, $fv) }}{{ $fmtRow($rowKey, $magOf($rowKey, $fv)) }}<span class="text-[10px] text-[var(--ui-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span></span>
                                                @else
                                                    <span class="text-[var(--ui-muted)]/40">·</span>
                                                @endif
                                            @else
                                                @php $cell = $row['cells'][$col['bucket']] ?? null; @endphp
                                                @if($cell && ($cell['entered'] || $cell['value'] != 0))
                                                    @php
                                                        $val = $cell['value'];
                                                        $committed = $meta[$rowKey]['cellCommitted'][$col['bucket']] ?? $val;
                                                        $rest = max(0, $val - $committed);
                                                        $pct = $val > 0 ? round($committed / $val * 100) : 100;
                                                    @endphp
                                                    <div class="flex items-center justify-end gap-1.5">
                                                        @if($cell['entered'])
                                                            @if($cell['mode'] === 'plus')
                                                                <span class="text-[9px] font-bold leading-none px-1 py-0.5 rounded bg-emerald-500/15 text-emerald-600">+</span>
                                                            @else
                                                                <span class="text-[9px] font-bold leading-none px-1 py-0.5 rounded bg-[var(--ui-primary)]/15 text-[var(--ui-primary)]">V</span>
                                                            @endif
                                                        @endif
                                                        <span class="tabular-nums {{ $cell['entered'] ? 'font-semibold' : '' }} {{ $toneOf($rowKey, $val) }}">{{ $signOf($rowKey, $val) }}{{ $fmtRow($rowKey, $val) }}<span class="text-[10px] font-normal text-[var(--ui-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span></span>
                                                    </div>
                                                    @if($rest > 0)
                                                        <div class="mt-1.5 h-1 rounded-full bg-[var(--ui-muted-10)] overflow-hidden flex">
                                                            <div class="h-full bg-[var(--ui-primary)]" style="width: {{ $pct }}%"></div>
                                                            <div class="h-full bg-amber-400/70" style="width: {{ 100 - $pct }}%"></div>
                                                        </div>
                                                        <div class="mt-0.5 text-[10px] text-amber-600">Rest {{ $fmt($rest) }}</div>
                                                    @endif
                                                @else
                                                    @php $sp = $meta[$rowKey]['spread'] ?? 0; @endphp
                                                    @if($sp > 0)
                                                        <span class="tabular-nums italic text-[11px] text-[var(--ui-muted)]/55" title="verteilter Rest (nicht verbindlich)">≈&hairsp;{{ $signOf($rowKey, $sp) }}{{ $fmtRow($rowKey, $sp) }}</span>
                                                    @else
                                                        <span class="text-[var(--ui-muted)]/40">·</span>
                                                    @endif
                                                @endif
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach

                            @if(empty($rows))
                                <tr><td colspan="99" class="px-4 py-12 text-center text-sm text-[var(--ui-muted)]">Dieser Typ hat noch keine Zeilen.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </x-ui-page-container>
</x-ui-page>
