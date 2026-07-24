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
                            @if($editMode)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-medium">
                                    @svg('heroicon-o-pencil-square','w-3 h-3') Bearbeiten
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">
                                    @svg('heroicon-o-eye','w-3 h-3') Nur Ansicht
                                </span>
                            @endif
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
                {{-- ═══ Kopf-Leiste der Tabelle = Steuerzentrale (liegt über der Scroll-Box, also immer sichtbar) ═══ --}}
                <div class="border-b border-[var(--ui-border)]/50 bg-[var(--ui-muted-5)]">
                    {{-- Tier 1: Zeit-Navigation der Tabelle — Zoom-Pfad (raus) links, Ebenen-Sprung rechts --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-2.5 pb-1.5">
                        <nav class="flex items-center gap-0.5 flex-wrap min-w-0" aria-label="Zeit-Navigation">
                            @foreach($breadcrumb as $i => $crumb)
                                @if($i > 0)@svg('heroicon-o-chevron-right','w-3.5 h-3.5 text-[var(--ui-muted)]/50 shrink-0')@endif
                                <button type="button" wire:click="zoom('{{ $crumb['bucket'] }}')"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-sm transition-colors
                                        {{ $loop->last
                                            ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-semibold ring-1 ring-[var(--ui-primary)]/20'
                                            : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-10)] hover:text-[var(--ui-secondary)]' }}"
                                    @unless($loop->last) title="Zurück zu „{{ $crumb['label'] }}“" @endunless>
                                    @if($i === 0)@svg('heroicon-o-calendar-days','w-3.5 h-3.5')@endif
                                    {{ $crumb['label'] }}
                                </button>
                            @endforeach
                        </nav>
                        <div class="flex items-center gap-1 shrink-0">
                            <span class="text-[10px] font-medium uppercase tracking-wider text-[var(--ui-muted)] mr-0.5">Ebene</span>
                            @foreach($levelNav as $ln)
                                @if($ln['state'] === 'ahead')
                                    <span class="text-xs font-medium px-2 py-1 rounded-md text-[var(--ui-muted)]/35 cursor-default"
                                        title="Tiefer als die aktuelle Ansicht — Spalte anklicken zum Reinzoomen">{{ $ln['label'] }}</span>
                                @else
                                    <button type="button"
                                        wire:click="{{ ($ln['jump'] ?? false) ? "viewLevelJump('".$ln['bucket']."', '".$ln['level']."')" : "zoom('".$ln['bucket']."')" }}"
                                        class="text-xs font-semibold px-2 py-1 rounded-md transition-colors
                                            {{ $ln['state'] === 'current'
                                                ? 'bg-[var(--ui-primary)] text-[var(--ui-on-primary)]'
                                                : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-10)] hover:text-[var(--ui-secondary)]' }}"
                                        @if(($ln['jump'] ?? false)) title="Halbjahre (H1/H2) anzeigen — ohne Pflicht-Zwischenschritt"
                                        @elseif($ln['state'] === 'done') title="Zur Ebene {{ $ln['label'] }} rauszoomen" @endif>
                                        {{ $ln['label'] }}
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>
                    {{-- Tier 2: Anzeige-Optionen + Legende --}}
                    <div class="flex flex-wrap items-center justify-between gap-2 px-4 pb-2.5">
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="toggleEdit"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium transition-colors
                                    {{ $editMode ? 'bg-[var(--ui-primary)] text-[var(--ui-on-primary)]' : 'text-[var(--ui-primary)] ring-1 ring-[var(--ui-primary)]/30 hover:bg-[var(--ui-primary)]/10' }}"
                                title="Nur offene Zellen werden zum Tippfeld. Geschlossene/berechnete/abgeleitete bleiben gesperrt.">
                                @svg('heroicon-o-pencil-square','w-3.5 h-3.5') {{ $editMode ? 'Fertig' : 'Bearbeiten' }}
                            </button>
                            <span class="text-[var(--ui-muted)]/30">·</span>
                            @if($canZoom)
                                <span class="inline-flex items-center gap-1 text-[11px] text-[var(--ui-muted)]" title="Auf einen Spaltenkopf klicken, um in diese Periode zu zoomen">@svg('heroicon-o-cursor-arrow-rays','w-3.5 h-3.5') Spalte anklicken = rein</span>
                                <span class="text-[var(--ui-muted)]/30">·</span>
                            @endif
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
                        {{-- Feld-Zustände: sieht es aus wie ein Feld, kannst du tippen — sonst nicht. --}}
                        <div class="flex items-center gap-3 text-[11px] text-[var(--ui-muted)]">
                            <span class="inline-flex items-center gap-1" title="Offen: hier kannst du (bald) tippen — Periode ist offen"><span class="w-3 h-3 rounded-sm bg-[var(--ui-primary)]/[0.12] ring-1 ring-inset ring-[var(--ui-primary)]/40"></span> offen · tippbar</span>
                            <span class="inline-flex items-center gap-1" title="Berechnet: ergibt sich aus anderen Zeilen"><span class="text-[9px] font-bold px-1 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">ƒ</span> berechnet</span>
                            <span class="inline-flex items-center gap-1" title="Abgeleitet: kommt aus den untergeordneten Planungen hoch"><span class="text-[9px] font-bold px-1 rounded bg-indigo-500/10 text-indigo-600">↑</span> abgeleitet</span>
                            <span class="inline-flex items-center gap-1" title="Zu: Periode geschlossen — keine Eingabe">@svg('heroicon-o-lock-closed','w-3 h-3 text-[var(--ui-muted)]/60') zu</span>
                            <span class="inline-flex items-center gap-1" title="Grob-Eingabe: gröber als die Erfassungs-Ebene — der Wert verteilt sich nach unten. Fluss wird per Schlüssel aufgeteilt, Rate/Bestand konstant repliziert.">@svg('heroicon-o-bars-arrow-down','w-3 h-3 text-amber-500/70') grob · verteilt</span>
                        </div>
                    </div>

                    @if($editMode)
                        <div class="px-4 pb-2.5 flex items-center gap-2 text-[11px]">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-medium">@svg('heroicon-o-pencil-square','w-3 h-3') Bearbeiten aktiv</span>
                            <span class="text-[var(--ui-muted)]"><span class="font-medium">Klick</span> wählt · <span class="font-medium">Ziehen/⇧</span> Bereich · <span class="font-medium">Tippen/Enter/Doppelklick</span> ändert · <span class="font-medium">Entf</span> leert · <span class="font-medium">Pfeile</span> bewegen · <span class="font-medium">100</span> setzt, <span class="font-medium">+50 · +5% · *1,1 · /2</span> rechnet.</span>

                            @if($lastEdit)
                                {{-- Settle-Fenster: 30 s rückgängig, dann festgeschrieben. --}}
                                <div wire:key="settle-{{ $editNonce }}" x-data="{ left: 30 }"
                                    x-init="let t = setInterval(() => { if (--left <= 0) { clearInterval(t); $wire.clearLastEditIf({{ $editNonce }}) } }, 1000)"
                                    class="inline-flex items-center gap-2 px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-700 font-medium ml-auto">
                                    @svg('heroicon-o-check-circle','w-3.5 h-3.5')
                                    @php $fcAct = $lastEdit['action'] ?? 'saved'; $fcCnt = $lastEdit['count'] ?? 1; @endphp
                                    <span>@if($fcAct === 'saved') „{{ \Illuminate\Support\Str::limit($lastEdit['label'], 22) }}" gespeichert @else {{ $fcCnt }} Zellen{{ $lastEdit['label'] ? ' · '.\Illuminate\Support\Str::limit($lastEdit['label'], 16) : '' }} {{ $fcAct === 'cleared' ? 'geleert' : 'gefüllt' }} @endif · festgeschrieben in <span x-text="left" class="tabular-nums"></span> s</span>
                                    <button type="button" wire:click="undoLastEdit"
                                        class="inline-flex items-center gap-0.5 underline decoration-dotted hover:text-emerald-900">
                                        @svg('heroicon-o-arrow-uturn-left','w-3 h-3') rückgängig
                                    </button>
                                </div>
                            @elseif($cellError)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-700 font-medium ml-auto">@svg('heroicon-o-exclamation-triangle','w-3 h-3') {{ $cellError }}</span>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="overflow-auto max-h-[72vh]">
                    <table class="min-w-full border-separate border-spacing-0 text-sm">
                        <thead>
                            <tr>
                                <th class="sticky left-0 top-0 z-30 bg-[var(--ui-surface-solid)] text-left px-4 py-2.5 font-medium text-[11px] uppercase tracking-wider text-[var(--ui-muted)] border-b border-[var(--ui-border)]/60 min-w-[200px]">Zeile</th>
                                @if($zoomed)
                                    <th class="sticky top-0 z-20 bg-[var(--ui-surface-solid)] text-right px-4 py-2.5 border-b border-r border-[var(--ui-border)]/60 whitespace-nowrap min-w-[120px]">
                                        <div class="text-xs font-semibold text-[var(--ui-secondary)]">{{ $breadcrumb[count($breadcrumb)-1]['label'] }}</div>
                                        <div class="text-[10px] font-normal text-[var(--ui-muted)]">Ebene gesamt</div>
                                    </th>
                                @endif
                                @foreach($columns as $col)
                                    @php $st = $colStatus[$col['bucket']] ?? ['state' => 'mixed', 'days' => null]; @endphp
                                    <th class="group/col sticky top-0 z-20 bg-[var(--ui-surface-solid)] text-right px-3 py-2.5 border-b border-[var(--ui-border)]/60 whitespace-nowrap min-w-[96px]
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
                            @php $lastSection = '__init__'; $gridRowIdx = -1; @endphp
                            @foreach($rows as $rowKey => $row)
                                @php
                                    $isF = $rowInfo[$rowKey]['isFormula'] ?? false;
                                    $sec = $rowInfo[$rowKey]['section'] ?? null;
                                    $gridRowIdx++; // Grid-Zeilenindex (nur Datenzeilen; Sektions-Header zählen nicht)
                                    // Verteilt sich eine Grob-Eingabe durch REPLIZIEREN (Rate/Bestand) statt AUFTEILEN (Fluss)?
                                    // Spiegelt exakt das $constantDown der Anzeige, damit Affordanz und Verhalten übereinstimmen.
                                    $rowReplicates = ($rowInfo[$rowKey]['nonAdditive'] ?? false) || (($rowInfo[$rowKey]['timeAgg'] ?? 'flow') !== 'flow');
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
                                    <td class="sticky left-0 z-10 bg-[var(--ui-surface-solid)] {{ $isF ? 'shadow-[inset_0_0_0_100vw_var(--ui-muted-5)]' : '' }} px-4 py-3 border-b border-[var(--ui-border)]/40 transition-colors">
                                        <div class="flex items-center gap-1.5">
                                            @if($isF)<span class="text-[9px] font-bold px-1 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]" title="{{ ! empty($rowInfo[$rowKey]['expr']) ? 'Ausdruck: '.$rowInfo[$rowKey]['expr'] : 'Berechnet: ergibt sich aus anderen Zeilen — nicht eingebbar' }}">ƒ</span>@endif
                                            @if($isMaster && ! $isF)<span class="text-[9px] font-bold px-1 rounded bg-indigo-500/10 text-indigo-600 inline-flex items-center gap-0.5" title="Abgeleitet: kommt aus den untergeordneten Planungen hoch — hier nicht direkt eingebbar">↑</span>@endif
                                            @php $ta = $rowInfo[$rowKey]['timeAgg'] ?? 'flow'; @endphp
                                            @if($ta !== 'flow')<span class="text-[9px] font-semibold px-1 rounded bg-sky-500/10 text-sky-600" title="Rollt NICHT als Summe über die Zeit — {{ ['stock'=>'Schlusswert (Quartal = letzter Monat)','stock_open'=>'Eröffnungswert (erster Teilzeitraum)','avg'=>'Durchschnitt über die Teilzeiträume','wavg'=>'gewichteter Ø über die Zeit (Σ Wert×Gewicht ÷ Σ Gewicht)','recompute'=>'Ausdruck je Ebene neu gerechnet'][$ta] ?? $ta }}">{{ ['stock'=>'Bestand','stock_open'=>'Eröffnung','avg'=>'Ø','wavg'=>'Ø gew.','recompute'=>'neu gerechnet'][$ta] ?? $ta }}</span>@endif
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
                                        @php
                                            $bkt = $col['bucket'];
                                            // Voller Sperr-Status der Spalte: NUR 'open' ist tippbar. 'closed'/'pending'/'mixed'
                                            // sind gesperrt — sonst verspricht die Anzeige Editierbarkeit, die das Tor (CellEditability)
                                            // beim Speichern ablehnt (z. B. Halbjahr gröber als die Sperr-Ebene → 'mixed').
                                            $colState = $colStatus[$bkt]['state'] ?? 'mixed';
                                            $colOpen = ($colState === 'open');
                                            // Grob-Eingabe: Spalte gröber als die Sperr-Ebene ('mixed') ist bei JEDER Eingabe-Zeile
                                            // tippbar — der Wert wird als Schätzung gespeichert und verteilt sich nach unten:
                                            // Fluss teilt (Schlüssel), Rate/Bestand repliziert. closed/pending bleiben gesperrt.
                                            $colSpread = ($colState === 'mixed');
                                            // Feld-Zustände — Orientierung: sieht es aus wie ein Feld, kannst du tippen.
                                            //   computed = ƒ · derived = Ordner/Detail · locked = zu · open = tippbar · spread = grob→verteilt
                                            $cellState = $isF ? 'computed'
                                                : ((($isMaster && ! $isF) || ! empty($rowInfo[$rowKey]['refPlans'])) ? 'derived'
                                                : ($colOpen ? 'open' : ($colSpread ? 'spread' : 'locked')));
                                            $hasDetailMark = ($timeDetail[$rowKey][$bkt] ?? false) && $canZoom;
                                            // Grid-Editor (Auswahl-Modell): editierbar = offen/spread im Bearbeiten-Modus.
                                            $cellEditable = $editMode && ($cellState === 'open' || $cellState === 'spread');
                                            $isFu = $rowInfo[$rowKey]['isFactor'] ?? false;
                                            $cellObj = $isF ? null : ($row['cells'][$col['bucket']] ?? null);
                                            $pfRaw = ($cellObj && ($cellObj['entered'] ?? false))
                                                ? rtrim(rtrim(number_format($isFu ? ($cellObj['value'] ?? 0) * 100 : (float) ($cellObj['value'] ?? 0), 4, '.', ''), '0'), '.')
                                                : '';
                                        @endphp
                                        <td class="relative text-right px-3 py-3 border-b border-[var(--ui-border)]/40 whitespace-nowrap align-top transition-colors
                                            {{ $cellState === 'open'
                                                ? 'bg-[var(--ui-primary)]/[0.04] cursor-text group-hover/row:bg-[var(--ui-primary)]/[0.07] hover:!bg-[var(--ui-primary)]/[0.11] hover:shadow-[inset_0_0_0_1px_var(--ui-primary)]'
                                                : ($cellState === 'spread'
                                                    ? 'bg-amber-400/[0.05] cursor-text group-hover/row:bg-amber-400/[0.09] hover:!bg-amber-400/[0.14] hover:shadow-[inset_0_0_0_1px_rgb(251_191_36_/_0.6)]'
                                                : ($cellState === 'locked'
                                                    ? 'opacity-50 group-hover/row:bg-[var(--ui-muted-5)]/50'
                                                    : 'group-hover/row:bg-[var(--ui-muted-5)]/60')) }}"
                                            data-fc-r="{{ $gridRowIdx }}" data-fc-c="{{ $loop->index }}"
                                            data-fc-row="{{ $rowKey }}" data-fc-col="{{ $col['bucket'] }}"
                                            @if($cellEditable) data-fc-edit="1" data-fc-raw="{{ $pfRaw }}"@if($cellState === 'spread') data-fc-spread="1"@endif @if($isFu) data-fc-factor="1"@endif @endif>
                                            @if($hasDetailMark)
                                                <span class="absolute top-1 left-1.5 text-[var(--ui-primary)]/45 group-hover/row:text-[var(--ui-primary)]/70 transition-colors" title="Enthält feineres Detail — Spalte anklicken zum Reinzoomen">
                                                    @svg('heroicon-o-bars-arrow-down','w-3 h-3')
                                                </span>
                                            @elseif($cellState === 'spread')
                                                <span class="absolute top-1 left-1.5 text-amber-500/60" title="Grob-Eingabe (gröber als die Erfassungs-Ebene): {{ $rowReplicates ? 'gilt als Rate/Bestand konstant für jede Teilperiode (repliziert).' : 'wird per Verteilungsschlüssel auf die Teilperioden aufgeteilt.' }} Gespeichert als Schätzung, ≈-Werte darunter.">
                                                    @svg('heroicon-o-bars-arrow-down','w-3 h-3')
                                                </span>
                                            @elseif($cellState === 'locked')
                                                @php
                                                    $lockTitle = match ($colState) {
                                                        'mixed' => 'Gröber als die Sperr-Ebene — bitte auf der Erfassungs-Ebene eingeben.',
                                                        'pending' => 'Periode noch nicht offen'.(($colStatus[$bkt]['days'] ?? null) !== null ? ' — öffnet in '.$colStatus[$bkt]['days'].' T' : '').'.',
                                                        default => 'Periode geschlossen — hier ist keine Eingabe möglich.',
                                                    };
                                                @endphp
                                                <span class="absolute top-1 left-1.5 text-[var(--ui-muted)]/40" title="{{ $lockTitle }}">
                                                    @svg('heroicon-o-lock-closed','w-3 h-3')
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
                                                @php $cell = $cellObj; @endphp
                                                {{-- Anzeige-Zelle (Auswahl-Modell): editiert wird über den Grid-Editor auf der aktiven Zelle, kein Input pro Zelle. --}}
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
                                                    @php $sp = $meta[$rowKey]['spreadBy'][$col['bucket']] ?? 0; @endphp
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

    {{-- Auswahl-Modell (Excel-Optik): Bereichs-Tönung, aktive-Zelle-Rahmen, Grid-Editor --}}
    <style>
        td[data-fc-r] { cursor: cell; user-select: none; }
        td.fc-sel { background: color-mix(in srgb, var(--ui-primary) 12%, transparent) !important; }
        td.fc-active { box-shadow: inset 0 0 0 2px var(--ui-primary); z-index: 2; }
        td.fc-active.fc-sel { background: color-mix(in srgb, var(--ui-primary) 6%, transparent) !important; }
        body.fc-selecting { user-select: none; }
        .fc-editor { position: absolute; inset: 3px; width: calc(100% - 6px); box-sizing: border-box;
            text-align: right; font-variant-numeric: tabular-nums; font-size: .875rem;
            background: var(--ui-surface-solid); color: var(--ui-secondary);
            border: 2px solid var(--ui-primary); border-radius: 4px; padding: 2px 5px; z-index: 10; outline: none; }
        .fc-editor.fc-editor--spread { border-color: rgb(245 158 11); }
        .fc-fill-handle { position: absolute; bottom: -4px; right: -4px; width: 9px; height: 9px; border-radius: 1px;
            background: var(--ui-primary); border: 1.5px solid var(--ui-surface-solid); cursor: crosshair; z-index: 3; }
        td.fc-fill-preview { box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--ui-primary) 55%, transparent);
            background: color-mix(in srgb, var(--ui-primary) 7%, transparent) !important; }
    </style>

    {{-- Tastatur-Navigation der Eingabe-Felder: Enter/Tab → nächste offene Zelle (Shift = zurück).
         Der Fokuswechsel blurrt das aktuelle Feld → @blur speichert durchs Editier-Tor.
         Reihenfolge = DOM-Reihenfolge (zeilenweise links→rechts, dann nächste Zeile). --}}
    @script
    <script>
        // $wire dieser Komponente global greifbar machen (für Fill aus dem Drag-Handler).
        window.__fcWire = $wire;

        // ── Auswahl-Modell (Excel-artiges Grid) ────────────────────────────────────────────
        // Zellen sind Anzeige (data-fc-r/c/row/col + data-fc-edit). EIN geteilter Editor-Input
        // wird bei Bedarf in die aktive Zelle gesetzt. Speichern läuft durch saveCell/saveRange.
        // Listener/Objekt nur EINMAL anlegen (ueberlebt Script-Re-Runs via wire:navigate).
        if (! window.fcGrid) {
            const G = window.fcGrid = {
                active: null, anchor: null, editing: false, dragging: false, editor: null,
                cell(r, c) { return document.querySelector('td[data-fc-r="' + r + '"][data-fc-c="' + c + '"]'); },
                dims() {
                    let mr = -1, mc = -1;
                    document.querySelectorAll('td[data-fc-r]').forEach(td => { mr = Math.max(mr, +td.dataset.fcR); mc = Math.max(mc, +td.dataset.fcC); });
                    return { mr, mc };
                },
                clamp(r, c) { const d = this.dims(); return { r: Math.max(0, Math.min(d.mr, r)), c: Math.max(0, Math.min(d.mc, c)) }; },
                paint() {
                    document.querySelectorAll('td.fc-active, td.fc-sel').forEach(td => td.classList.remove('fc-active', 'fc-sel'));
                    if (! this.active) return;
                    const a = this.anchor || this.active, b = this.active;
                    const r0 = Math.min(a.r, b.r), r1 = Math.max(a.r, b.r), c0 = Math.min(a.c, b.c), c1 = Math.max(a.c, b.c);
                    for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) { const td = this.cell(r, c); if (td) td.classList.add('fc-sel'); }
                    const at = this.cell(b.r, b.c); if (at) at.classList.add('fc-active');
                    this.placeHandle();
                },
                select(r, c, extend) {
                    this.commitEdit();
                    const p = this.clamp(r, c);
                    this.active = p;
                    if (! extend || ! this.anchor) this.anchor = { r: p.r, c: p.c };
                    this.paint();
                    const td = this.cell(p.r, p.c); if (td) td.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                },
                move(dr, dc, extend) { if (this.active) this.select(this.active.r + dr, this.active.c + dc, extend); },
                ensureEditor() {
                    if (this.editor) return this.editor;
                    const ed = document.createElement('input');
                    ed.type = 'text'; ed.inputMode = 'decimal'; ed.className = 'fc-editor';
                    ed.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') { e.preventDefault(); this.commitEdit(); this.move(e.shiftKey ? -1 : 1, 0, false); }
                        else if (e.key === 'Tab') { e.preventDefault(); this.commitEdit(); this.move(0, e.shiftKey ? -1 : 1, false); }
                        else if (e.key === 'Escape') { e.preventDefault(); this.cancelEdit(); }
                        e.stopPropagation();
                    });
                    ed.addEventListener('blur', () => { if (this.editing) this.commitEdit(); });
                    document.body.appendChild(ed); ed.style.display = 'none';
                    this.editor = ed; return ed;
                },
                startEdit(initial) {
                    if (! this.active) return;
                    const td = this.cell(this.active.r, this.active.c);
                    if (! td || td.dataset.fcEdit !== '1') return; // nur offene/spread editieren
                    this.editing = true;
                    const ed = this.ensureEditor();
                    ed.dataset.row = td.dataset.fcRow; ed.dataset.col = td.dataset.fcCol;
                    ed.classList.toggle('fc-editor--spread', td.dataset.fcSpread === '1');
                    ed.style.display = ''; td.appendChild(ed);
                    ed.value = (initial != null) ? initial : (td.dataset.fcRaw || '');
                    ed.focus(); if (initial == null) ed.select();
                },
                stashEditor() { if (this.editor) { this.editor.style.display = 'none'; document.body.appendChild(this.editor); } },
                commitEdit() {
                    if (! this.editing) return;
                    this.editing = false;
                    const ed = this.editor, row = ed.dataset.row, col = ed.dataset.col, val = ed.value;
                    this.stashEditor();
                    if (window.__fcWire) window.__fcWire.saveCell(row, col, val);
                },
                cancelEdit() { if (this.editing) { this.editing = false; this.stashEditor(); } },
                rect() {
                    const a = this.anchor || this.active, b = this.active;
                    return { r0: Math.min(a.r, b.r), r1: Math.max(a.r, b.r), c0: Math.min(a.c, b.c), c1: Math.max(a.c, b.c) };
                },
                // {row, bucket}-Liste der Auswahl (optional nur editierbare Zellen).
                rangeCells(onlyEditable) {
                    if (! this.active) return [];
                    const q = this.rect(), out = [];
                    for (let r = q.r0; r <= q.r1; r++) for (let c = q.c0; c <= q.c1; c++) {
                        const td = this.cell(r, c);
                        if (td && (! onlyEditable || td.dataset.fcEdit === '1')) out.push({ row: td.dataset.fcRow, bucket: td.dataset.fcCol });
                    }
                    return out;
                },
                clearSel() {
                    const cells = this.rangeCells(true);
                    if (cells.length && window.__fcWire) window.__fcWire.saveRange(cells, '', 'cleared');
                },
                // ── Fill-Handle: an der unteren-rechten Ecke der Auswahl; ziehen füllt/kopiert ──
                ensureHandle() {
                    if (this.handle) return this.handle;
                    const h = document.createElement('div');
                    h.className = 'fc-fill-handle';
                    this.handle = h; return h;
                },
                placeHandle() {
                    if (! this.active) { if (this.handle) this.handle.remove(); return; }
                    const q = this.rect();
                    const td = this.cell(q.r1, q.c1); // untere-rechte Zelle der Auswahl
                    if (! td) return;
                    const h = this.ensureHandle();
                    td.appendChild(h);
                },
                startFillDrag() {
                    if (! this.active) return;
                    this.fillDragging = true;
                    this.fillSrc = this.rect();
                    this.fillTarget = null;
                    document.body.classList.add('fc-selecting');
                },
                fillMove(e) {
                    const el = document.elementFromPoint(e.clientX, e.clientY);
                    const td = el && el.closest && el.closest('td[data-fc-r]');
                    if (! td) return;
                    const r = +td.dataset.fcR, c = +td.dataset.fcC, s = this.fillSrc;
                    const down = r - s.r1, right = c - s.c1;
                    let t = null;
                    if (down > 0 && down >= right) t = { r0: s.r0, r1: r, c0: s.c0, c1: s.c1, axis: 'v' };
                    else if (right > 0) t = { r0: s.r0, r1: s.r1, c0: s.c0, c1: c, axis: 'h' };
                    this.fillTarget = t;
                    document.querySelectorAll('td.fc-fill-preview').forEach(x => x.classList.remove('fc-fill-preview'));
                    if (t) for (let rr = t.r0; rr <= t.r1; rr++) for (let cc = t.c0; cc <= t.c1; cc++) {
                        const inSrc = rr >= s.r0 && rr <= s.r1 && cc >= s.c0 && cc <= s.c1;
                        if (! inSrc) { const x = this.cell(rr, cc); if (x) x.classList.add('fc-fill-preview'); }
                    }
                },
                fillEnd() {
                    this.fillDragging = false;
                    document.body.classList.remove('fc-selecting');
                    document.querySelectorAll('td.fc-fill-preview').forEach(x => x.classList.remove('fc-fill-preview'));
                    const t = this.fillTarget, s = this.fillSrc;
                    if (! t) return;
                    const srcRows = s.r1 - s.r0 + 1, srcCols = s.c1 - s.c0 + 1, cells = [];
                    for (let r = t.r0; r <= t.r1; r++) for (let c = t.c0; c <= t.c1; c++) {
                        const inSrc = r >= s.r0 && r <= s.r1 && c >= s.c0 && c <= s.c1;
                        if (inSrc) continue; // Quelle nicht überschreiben
                        const tgt = this.cell(r, c);
                        if (! tgt || tgt.dataset.fcEdit !== '1') continue; // nur editierbare Ziele
                        const sr = (t.axis === 'v') ? s.r0 + ((r - s.r0) % srcRows) : r;
                        const sc = (t.axis === 'h') ? s.c0 + ((c - s.c0) % srcCols) : c;
                        const src = this.cell(sr, sc);
                        cells.push({ row: tgt.dataset.fcRow, bucket: tgt.dataset.fcCol, value: (src && src.dataset.fcRaw) || '' });
                    }
                    if (cells.length && window.__fcWire) window.__fcWire.saveRange(cells, null, 'filled');
                    // Auswahl auf Quelle+Ziel erweitern.
                    this.anchor = { r: t.r0, c: t.c0 };
                    this.active = { r: t.r1, c: t.c1 };
                    this.paint();
                }
            };

            // Maus: klicken wählt · Shift erweitert · ziehen spannt Bereich auf · Doppelklick editiert.
            document.addEventListener('mousedown', (e) => {
                if (e.target === G.editor) return;
                if (e.target === G.handle) { e.preventDefault(); e.stopPropagation(); G.startFillDrag(); return; } // Fill-Handle
                if (e.target.closest && e.target.closest('a, button')) return; // Drill-Links/Buttons durchlassen
                const td = e.target.closest && e.target.closest('td[data-fc-r]');
                if (! td) return;
                e.preventDefault();
                if (document.activeElement && document.activeElement !== document.body && document.activeElement.blur) document.activeElement.blur();
                G.select(+td.dataset.fcR, +td.dataset.fcC, e.shiftKey);
                G.dragging = true; document.body.classList.add('fc-selecting');
            }, true);
            document.addEventListener('mousemove', (e) => {
                if (G.fillDragging) { G.fillMove(e); return; }
                if (! G.dragging) return;
                const el = document.elementFromPoint(e.clientX, e.clientY);
                const td = el && el.closest && el.closest('td[data-fc-r]');
                if (td) { const r = +td.dataset.fcR, c = +td.dataset.fcC; if (! G.active || G.active.r !== r || G.active.c !== c) G.select(r, c, true); }
            }, true);
            document.addEventListener('mouseup', () => {
                if (G.fillDragging) { G.fillEnd(); return; }
                G.dragging = false; document.body.classList.remove('fc-selecting');
            }, true);
            document.addEventListener('dblclick', (e) => {
                const td = e.target.closest && e.target.closest('td[data-fc-r]');
                if (td) { G.select(+td.dataset.fcR, +td.dataset.fcC, false); G.startEdit(null); }
            }, true);

            // Tastatur (nur wenn eine Grid-Zelle aktiv ist, nicht editiert wird und kein Feld fokussiert ist).
            document.addEventListener('keydown', (e) => {
                if (G.editing || ! G.active) return;
                const ae = document.activeElement;
                if (ae && ae !== document.body && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.isContentEditable)) return;
                const k = e.key;
                if (k === 'ArrowUp') { e.preventDefault(); G.move(-1, 0, e.shiftKey); }
                else if (k === 'ArrowDown') { e.preventDefault(); G.move(1, 0, e.shiftKey); }
                else if (k === 'ArrowLeft') { e.preventDefault(); G.move(0, -1, e.shiftKey); }
                else if (k === 'ArrowRight') { e.preventDefault(); G.move(0, 1, e.shiftKey); }
                else if (k === 'Enter' || k === 'F2') { e.preventDefault(); G.startEdit(null); }
                else if (k === 'Delete' || k === 'Backspace') { e.preventDefault(); G.clearSel(); }
                else if (k.length === 1 && /[-0-9.,+*/%]/.test(k)) { e.preventDefault(); G.startEdit(k); }
            }, true);

            // Auswahl nach Livewire-Re-Render (Speichern) neu zeichnen.
            if (window.Livewire) {
                Livewire.hook('commit', ({ succeed }) => { succeed(() => { G.paint(); }); });
            }
        }
    </script>
    @endscript
</x-ui-page>
