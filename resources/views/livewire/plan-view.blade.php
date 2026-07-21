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
    // Delta-Farbe nach WIRKUNG, nicht nach roher Zahl: Aufwand steigt = schlecht (rot),
    // Ertrag/Netto steigt = gut (grün), neutrale Zeilen (Summen etc.) neutral gefärbt.
    $deltaTone = function ($rk, $change) use ($rowInfo) {
        $info = $rowInfo[$rk] ?? [];
        if (($info['signMode'] ?? 'direction') === 'net') {
            $eff = $change;
        } else {
            $d = $info['direction'] ?? 'neutral';
            if ($d === 'income') $eff = $change;
            elseif ($d === 'expense') $eff = -$change;
            else return 'text-[var(--ui-muted)]/70';
        }
        return $eff > 0 ? 'text-emerald-600' : ($eff < 0 ? 'text-rose-600' : 'text-[var(--ui-muted)]/60');
    };
    // FAKTOR: gespeichert 0–1, angezeigt ×100 als %. %-Zeilen 1 Nachkommastelle, sonst ganzzahlig.
    $fmtRow = function ($rk, $v) use ($rowInfo, $fmt) {
        $info = $rowInfo[$rk] ?? [];
        if ($info['isFactor'] ?? false) {
            return number_format((float) $v * 100, 1, ',', '.');
        }
        return ($info['unit'] ?? '') === '%' ? number_format((float) $v, 1, ',', '.') : $fmt($v);
    };
    // Ordner-Modell: eine Planung BÜNDELT untergeordnete (= Ordner) ODER erfasst Zahlen (= Blatt).
    // Ordner/Blatt ist blickpunkt-unabhängig (ein Ordner bleibt Ordner, egal von wo man draufschaut).
    // Drill-down (ein Feld hat eine eigene Planung dahinter) ist EXTRA und hängt an der Zeile.
    $isFolder  = $isMaster;
    $roleIcon  = $isFolder ? 'heroicon-o-folder' : 'heroicon-o-document-chart-bar';
    $roleLabel = $isFolder ? 'Ordner' : 'Blatt';
    $roleText  = $isFolder ? 'text-indigo-600' : 'text-emerald-600';
    $roleBg    = $isFolder ? 'bg-indigo-500/10' : 'bg-emerald-500/10';
    $roleTip   = $isFolder ? 'Ordner — bündelt untergeordnete Planungen zu einem Gesamtbild.' : 'Blatt — hier werden Zahlen erfasst.';
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Forecast" />
    </x-slot>

    <x-slot name="actionbar">
        @php
            // Breadcrumb = Ordner-Pfad (Wurzel → … → hier): man sieht dauerhaft, wo man liegt.
            $crumbs = [
                ['label' => 'Forecast', 'href' => route('forecast.dashboard'), 'icon' => 'presentation-chart-line'],
                ['label' => 'Planungen', 'href' => route('forecast.plans.index')],
            ];
            foreach ($ancestors as $anc) {
                $crumbs[] = ['label' => $anc->name, 'href' => route('forecast.plans.show', ['uuid' => $anc->uuid])];
            }
            $crumbs[] = ['label' => $plan->name];
        @endphp
        <x-ui-page-actionbar :breadcrumbs="$crumbs">
            {{-- Dauerhaft sichtbar (Leiste ist sticky): Ordner oder Blatt --}}
            <x-slot name="left">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold {{ $roleText }} {{ $roleBg }}" title="{{ $roleTip }}">
                    @svg($roleIcon,'w-3 h-3') {{ $roleLabel }}
                </span>
            </x-slot>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-6">

            {{-- ═══════════ Kontext-Kopf ═══════════ --}}
            <div class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold tracking-tight text-[var(--ui-secondary)]">{{ $plan->name }}</h1>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-semibold {{ $roleText }} {{ $roleBg }}" title="{{ $roleTip }}">
                                @svg($roleIcon,'w-3 h-3') {{ $roleLabel }}
                            </span>
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
                        {{-- Nette Ein-Zeilen-Erklärung, was diese Planung ist --}}
                        @php
                            $folderPart = $isFolder
                                ? ($subMasterCount > 0
                                    ? "Ein Ordner: bündelt {$childCount} Planungen ({$leafCount} Blätter mit Zahlen insgesamt) — die Zahlen unten sind ihre Summe."
                                    : "Ein Ordner: bündelt {$childCount} Blätter — die Zahlen unten sind ihre Summe.")
                                : "Ein Blatt: hier werden die Zahlen erfasst.";
                            $locPart = $parentPlan ? " Liegt im Ordner „{$parentPlan->name}“." : "";
                            $drillPart = (! $isFolder && count($usedIn)) ? " Speist per Drill-down eine Zeile in „".implode('“, „', $usedIn)."“." : "";
                            $explain = $folderPart.$locPart.$drillPart;
                        @endphp
                        <p class="mt-2 flex items-start gap-1.5 text-xs text-[var(--ui-muted)] max-w-2xl">
                            @svg('heroicon-o-information-circle','w-3.5 h-3.5 mt-px shrink-0 opacity-60')
                            <span>{{ $explain }}</span>
                        </p>
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
                            $naMaster = $isMaster && ($rowInfo[$rowKey]['nonAdditive'] ?? false) && ! ($rowInfo[$rowKey]['hasEffective'] ?? false);
                            $effMaster = $isMaster && ($rowInfo[$rowKey]['hasEffective'] ?? false);
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
                            <div class="mt-1.5 text-2xl font-semibold tracking-tight tabular-nums {{ $naMaster ? 'text-[var(--ui-muted)]/40' : $toneOf($rowKey, $t['value']) }}">
                                @if($naMaster)–@else{{ $t['implied'] ? '≈ ' : '' }}{{ $signOf($rowKey, $t['value']) }}{{ $fmtRow($rowKey, $magOf($rowKey, $t['value'])) }}<span class="text-sm font-normal text-[var(--ui-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span>@endif
                            </div>
                            @if($naMaster)
                                <div class="mt-3 inline-flex items-center gap-1 text-[11px] text-[var(--ui-muted)]" title="Quoten/Faktoren sind nicht additiv — am Ordner nicht aufsummierbar, nur je Blatt erfasst.">
                                    @svg('heroicon-o-minus-circle','w-3.5 h-3.5') nicht aggregierbar · je Blatt erfasst
                                </div>
                            @elseif($effMaster)
                                <div class="mt-3 inline-flex items-center gap-1 text-[11px] text-indigo-600" title="Effektiver Wert am Ordner = Produkt ÷ Basis aus den konsolidierten Zahlen (nicht der aufsummierte Faktor).">
                                    @svg('heroicon-o-calculator','w-3.5 h-3.5') effektiv · aus den Blättern gerechnet
                                </div>
                            @elseif($isF)
                                @php $sc = $rowInfo[$rowKey]['sourceCount'] ?? count($rowInfo[$rowKey]['sources']); @endphp
                                <div class="mt-3 text-[11px] text-[var(--ui-muted)]">berechnet aus {{ $sc }} {{ $sc === 1 ? 'Zeile' : 'Zeilen' }}</div>
                            @elseif($isMaster)
                                <div class="mt-3 inline-flex items-center gap-1 text-[11px] font-medium text-indigo-600">
                                    @svg('heroicon-o-folder','w-3.5 h-3.5')
                                    @if($subMasterCount > 0)
                                        bündelt {{ $childCount }} Planungen · {{ $leafCount }} {{ $leafCount === 1 ? 'Blatt' : 'Blätter' }}
                                    @else
                                        bündelt {{ $childCount }} {{ $childCount === 1 ? 'Blatt' : 'Blätter' }}
                                    @endif
                                </div>
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
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium text-[var(--ui-secondary)]">{{ $scopeCaption }}</span>
                        <button type="button" wire:click="toggleShare"
                            class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs transition-colors
                                {{ $showShare ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-medium' : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-10)]' }}">
                            @svg('heroicon-o-chart-pie','w-3.5 h-3.5') Anteil %
                        </button>
                        <button type="button" wire:click="toggleDelta"
                            class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs transition-colors
                                {{ $showDelta ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-medium' : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-10)]' }}">
                            @svg('heroicon-o-arrow-trending-up','w-3.5 h-3.5') Δ Vorperiode
                        </button>
                    </div>
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
                            @php $lastSection = '__init__'; @endphp
                            @foreach($rows as $rowKey => $row)
                                @php
                                    $isF = $rowInfo[$rowKey]['isFormula'] ?? false;
                                    $sec = $rowInfo[$rowKey]['section'] ?? null;
                                @endphp
                                @if($sec && $sec !== $lastSection)
                                    <tr>
                                        <td colspan="99" class="sticky left-0 bg-[var(--ui-muted-10)]/60 px-4 py-1.5 border-y border-[var(--ui-border)]/50">
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)]">{{ $sec }}</span>
                                        </td>
                                    </tr>
                                @endif
                                @php $lastSection = $sec; @endphp
                                <tr class="group/row {{ $isF ? 'bg-[var(--ui-muted-5)]/40' : '' }}">
                                    {{-- Zeilen-Kopf --}}
                                    <td class="sticky left-0 z-10 {{ $isF ? 'bg-[var(--ui-muted-5)]/80' : 'bg-[var(--ui-surface)]' }} group-hover/row:bg-[var(--ui-muted-5)] px-4 py-3 border-b border-[var(--ui-border)]/40 transition-colors">
                                        <div class="flex items-center gap-1.5">
                                            @if($isF)<span class="text-[9px] font-bold px-1 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">ƒ</span>@endif
                                            <span class="font-medium text-[var(--ui-secondary)]">{{ $row['label'] }}</span>
                                            @if(!empty($rowInfo[$rowKey]['warnings']))
                                                <span class="text-amber-600" title="Konsolidierung ausgelassen — {{ implode(' · ', $rowInfo[$rowKey]['warnings']) }}">@svg('heroicon-o-exclamation-triangle','w-3.5 h-3.5')</span>
                                            @endif
                                            @php $refPlans = $rowInfo[$rowKey]['refPlans'] ?? []; @endphp
                                            @if(!empty($refPlans) && ($refPlans[0]['uuid'] ?? null))
                                                <a href="{{ route('forecast.plans.show', ['uuid' => $refPlans[0]['uuid'], 'from' => $plan->uuid]) }}" wire:navigate
                                                   class="inline-flex items-center gap-0.5 text-[10px] font-medium text-amber-600 bg-amber-500/10 hover:bg-amber-500/20 px-1.5 py-0.5 rounded"
                                                   title="Drill-down: Wert kommt aus Detailplan „{{ $refPlans[0]['name'] }}"{{ count($refPlans) > 1 ? ' (+'.(count($refPlans)-1).' weitere)' : '' }} — öffnen">
                                                    @svg('heroicon-o-magnifying-glass-plus','w-3 h-3') Detailplan
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
                                        <td class="relative text-right px-3 py-3 border-b border-[var(--ui-border)]/40 whitespace-nowrap align-top group-hover/row:bg-[var(--ui-muted-5)]/60 transition-colors {{ (($colStatus[$col['bucket']]['state'] ?? '') === 'closed') ? 'opacity-45' : '' }}">
                                            @if(($timeDetail[$rowKey][$col['bucket']] ?? false) && $canZoom)
                                                <span class="absolute top-1 left-1.5 text-[var(--ui-primary)]/45 group-hover/row:text-[var(--ui-primary)]/70 transition-colors" title="Enthält feineres Detail — Spalte anklicken zum Reinzoomen">
                                                    @svg('heroicon-o-bars-arrow-down','w-3 h-3')
                                                </span>
                                            @endif
                                            @if($partial[$rowKey][$col['bucket']] ?? false)
                                                <span class="absolute top-1 right-1.5 text-amber-500" title="Nur teilweise Detail auf dieser Ebene — nicht alle Bestandteile sind hier aufgeschlüsselt, die Kennzahl ist unvollständig">
                                                    @svg('heroicon-o-exclamation-triangle','w-3 h-3')
                                                </span>
                                            @endif
                                            @if($isF)
                                                {{-- Formula: berechnet, read-only --}}
                                                @php $fv = $formulaCells[$rowKey][$col['bucket']] ?? 0; @endphp
                                                @if($fv != 0)
                                                    <span class="tabular-nums {{ $toneOf($rowKey, $fv) }} {{ ($partial[$rowKey][$col['bucket']] ?? false) ? 'italic opacity-50' : '' }}">{{ $signOf($rowKey, $fv) }}{{ $fmtRow($rowKey, $magOf($rowKey, $fv)) }}<span class="text-[10px] text-[var(--ui-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span></span>
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
                                            @php
                                                $hasShare = $showShare && isset($share[$rowKey][$col['bucket']]);
                                                $hasDelta = $showDelta && isset($delta[$rowKey][$col['bucket']]);
                                                $qBasis = $rowInfo[$rowKey]['quoteBasis'] ?? null;
                                                $hasQuote = $showShare && $qBasis && isset($quote[$rowKey][$col['bucket']]);
                                            @endphp
                                            @if($hasShare || $hasDelta || $hasQuote)
                                                <div class="mt-2 pt-1.5 border-t border-dashed border-[var(--ui-border)]/40 flex flex-col items-end gap-1">
                                                    @if($hasShare)
                                                        <div class="inline-flex items-center gap-1 text-[10px] font-medium text-[var(--ui-primary)]">
                                                            <span class="w-8 h-1 rounded-full bg-[var(--ui-muted-10)] overflow-hidden inline-flex">
                                                                <span class="h-full bg-[var(--ui-primary)]" style="width: {{ min(100, $share[$rowKey][$col['bucket']]) }}%"></span>
                                                            </span>
                                                            {{ number_format($share[$rowKey][$col['bucket']], 1, ',', '.') }}&thinsp;%
                                                        </div>
                                                    @endif
                                                    @if($hasQuote)
                                                        <div class="inline-flex items-center gap-1 text-[10px] font-medium text-[var(--ui-secondary)]" title="Anteil an „{{ $rows[$qBasis]['label'] ?? $qBasis }}“ (Bezugsgröße)">
                                                            {{ number_format($quote[$rowKey][$col['bucket']], 1, ',', '.') }}&thinsp;% <span class="text-[var(--ui-muted)]/70">v. {{ \Illuminate\Support\Str::limit($rows[$qBasis]['label'] ?? $qBasis, 16) }}</span>
                                                        </div>
                                                    @endif
                                                    @if($hasDelta)
                                                        @php $d = $delta[$rowKey][$col['bucket']]; @endphp
                                                        <div class="inline-flex items-center gap-1 text-[10px] font-medium {{ $deltaTone($rowKey, $d['abs']) }}" title="Veränderung zur Vorperiode">
                                                            {{ $d['abs'] > 0 ? '▲' : ($d['abs'] < 0 ? '▼' : '=') }} {{ $d['abs'] >= 0 ? '+' : '−' }}{{ $fmt(abs($d['abs'])) }}@if($d['pct'] !== null) <span class="opacity-75">({{ $d['pct'] >= 0 ? '+' : '−' }}{{ number_format(abs($d['pct']), 1, ',', '.') }}&thinsp;%)</span>@endif
                                                        </div>
                                                    @endif
                                                </div>
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

    {{-- ═══════════ Innenliegende Sidebar: „Wo bin ich" — Position im Gesamtkontext ═══════════ --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Navigation" width="w-72" :defaultOpen="true" storeKey="forecastNavOpen" side="left">
            <div class="p-4 space-y-6 text-sm">

                {{-- Ordner-Struktur des aktuellen Kontexts (Ordner enthalten Ordner/Blätter) --}}
                @php
                    $navIcon = fn ($r) => match ($r) {
                        'master' => 'heroicon-o-folder',
                        'detail' => 'heroicon-o-magnifying-glass-plus',
                        default => 'heroicon-o-document-chart-bar',
                    };
                @endphp
                @if($contextRoots->isNotEmpty())
                    <div>
                        <div class="flex items-center justify-between mb-2.5">
                            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)]">Struktur</h3>
                            <a href="{{ route('forecast.plans.index') }}" wire:navigate class="text-[10px] text-[var(--ui-muted)] hover:text-[var(--ui-primary)]">Alle</a>
                        </div>
                        <div class="space-y-0.5">
                            @foreach($contextRoots as $root)
                                @include('forecast::livewire.partials.nav-plan-node', [
                                    'node' => $root,
                                    'depth' => 0,
                                    'currentUuid' => $plan->uuid,
                                    'ancestorIds' => $ancestorIds,
                                    'childrenByParent' => $childrenByParent,
                                    'planRole' => $planRole,
                                    'componentSet' => $componentSet,
                                    'drillConsumerIds' => $drillConsumerIds,
                                ])
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Verbundene Pläne im Kontext (Detailpläne / Einzelplan) --}}
                @if($contextOther->isNotEmpty())
                    <div>
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2.5">{{ $contextRoots->isNotEmpty() ? 'Detailpläne (Drill-down)' : 'Planung' }}</h3>
                        <div class="space-y-0.5">
                            @foreach($contextOther as $op)
                                @php $cur = $op->uuid === $plan->uuid; @endphp
                                <a href="{{ route('forecast.plans.show', ['uuid' => $op->uuid, 'from' => $plan->uuid]) }}" wire:navigate
                                   class="flex items-center gap-1.5 py-1 px-1.5 rounded-md transition-colors {{ $cur ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-semibold ring-1 ring-[var(--ui-primary)]/20' : 'text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] hover:bg-[var(--ui-muted-10)]' }}">
                                    @svg($navIcon($planRole[$op->id] ?? 'single'),'w-3.5 h-3.5 shrink-0 '.($cur ? '' : 'opacity-70')) <span class="truncate">{{ $op->name }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Zeit-Ebene: aktueller Zoom-Pfad --}}
                <div>
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--ui-muted)] mb-2.5">Zeit-Ebene</h3>
                    <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                        @foreach($breadcrumb as $i => $crumb)
                            @if($i > 0)<span class="text-[var(--ui-muted)]/40 text-xs">/</span>@endif
                            <button type="button" wire:click="zoom('{{ $crumb['bucket'] }}')"
                                class="px-1.5 py-0.5 rounded text-xs transition-colors {{ $loop->last ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-medium' : 'text-[var(--ui-muted)] hover:text-[var(--ui-secondary)] hover:bg-[var(--ui-muted-10)]' }}">
                                {{ $crumb['label'] }}
                            </button>
                        @endforeach
                    </div>
                    <div class="mt-1.5 text-[11px] text-[var(--ui-muted)]">Ebene: <span class="text-[var(--ui-secondary)] font-medium">{{ $levelLabel }}</span></div>
                </div>

                {{-- Zurück zur Herkunft --}}
                @if($fromPlan)
                    <a href="{{ route('forecast.plans.show', ['uuid' => $fromPlan->uuid]) }}" wire:navigate
                       class="inline-flex items-center gap-1.5 text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] font-medium">
                        @svg('heroicon-o-arrow-uturn-left','w-3.5 h-3.5') Zurück: {{ $fromPlan->name }}
                    </a>
                @endif

                {{-- Legende: was die Icons bedeuten --}}
                <div class="pt-3 border-t border-[var(--ui-border)]/40 space-y-1.5 text-[10px] text-[var(--ui-muted)]">
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-folder','w-3 h-3 text-indigo-500') Ordner — bündelt Planungen</div>
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-document-chart-bar','w-3 h-3 text-emerald-500') Blatt — hier werden Zahlen erfasst</div>
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-magnifying-glass-plus','w-3 h-3 text-amber-500') Drill-down — Feld mit eigener Planung dahinter</div>
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-bars-arrow-down','w-3 h-3 text-[var(--ui-primary)]/60') Zelle hat feineres Detail — reinzoomen</div>
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-exclamation-triangle','w-3 h-3 text-amber-500') nur teilweise Detail — Kennzahl unvollständig</div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>
</x-ui-page>
