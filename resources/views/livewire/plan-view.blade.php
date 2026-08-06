@php
    $fmt = fn ($v) => number_format((float) $v, 0, ',', '.');
    $kpiRows = collect($rows)->take(4);

    // Sektionen (generisch aus den Zeilen-Metadaten) — Basis für Ein-/Ausklappen & Kompakt-Ansicht.
    // Keine Plan-Typ-Spezifika: was in `section` steht, wird zur klappbaren Gruppe.
    $sectionCounts = [];
    foreach ($rows as $rk => $r) {
        $s = $rowInfo[$rk]['section'] ?? null;
        if ($s !== null && $s !== '') {
            $sectionCounts[$s] = ($sectionCounts[$s] ?? 0) + 1;
        }
    }
    $sectionList = array_keys($sectionCounts);

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
        return $d === 'income' ? 'text-emerald-600' : ($d === 'expense' ? 'text-rose-600' : 'text-[var(--nx-text)]');
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
            else return 'text-[var(--nx-muted)]/70';
        }
        return $eff > 0 ? 'text-emerald-600' : ($eff < 0 ? 'text-rose-600' : 'text-[var(--nx-muted)]/60');
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
        <div class="space-y-4">

            {{-- ═══════════ Kontext-Kopf ═══════════ --}}
            <div class="space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold tracking-tight text-[var(--nx-text)]">{{ $plan->name }}</h1>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-semibold {{ $roleText }} {{ $roleBg }}" title="{{ $roleTip }}">
                                @svg($roleIcon,'w-3 h-3') {{ $roleLabel }}
                            </span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--nx-accent-soft)] text-[var(--nx-muted)]">
                                @svg('heroicon-o-squares-2x2','w-3 h-3') {{ $plan->planType?->name }}
                            </span>
                            @if($plan->organization_entity_id)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--nx-accent-soft)] text-[var(--nx-muted)]">
                                    @svg('heroicon-o-building-office-2','w-3 h-3') Knoten #{{ $plan->organization_entity_id }}
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--nx-accent-soft)] text-[var(--nx-muted)]">
                                @svg('heroicon-o-clock','w-3 h-3') Version {{ $plan->current_version }}
                            </span>
                            @if($editMode)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--nx-accent)]/10 text-[var(--nx-accent)] font-medium">
                                    @svg('heroicon-o-pencil-square','w-3 h-3') Bearbeiten
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--nx-accent-soft)] text-[var(--nx-muted)]">
                                    @svg('heroicon-o-eye','w-3 h-3') Nur Ansicht
                                </span>
                            @endif
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--nx-accent-soft)] text-[var(--nx-muted)]" title="Vorlauf: öffnet X Tage vor Periodenstart · Nachlauf: bleibt Y Tage nach Periodenende offen">
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
                        <p class="mt-2 flex items-start gap-1.5 text-xs text-[var(--nx-muted)] max-w-2xl">
                            @svg('heroicon-o-information-circle','w-3.5 h-3.5 mt-px shrink-0 opacity-60')
                            <span>{{ $explain }}</span>
                        </p>
                    </div>
                </div>
            </div>

            {{-- ═══════════ KPI-Streifen (kompakt: Label + Wert je Kennzahl in einer Zeile) ═══════════ --}}
            @if($kpiRows->isNotEmpty())
                <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1.5 -mt-1 px-0.5">
                    @foreach($kpiRows as $rowKey => $row)
                        @php
                            $t = $meta[$rowKey] ?? ['value' => 0, 'rest' => 0, 'committed' => 0, 'implied' => false];
                            $isF = $rowInfo[$rowKey]['isFormula'] ?? false;
                            $naMaster = $isMaster && ($rowInfo[$rowKey]['nonAdditive'] ?? false) && ! ($rowInfo[$rowKey]['hasEffective'] ?? false);
                        @endphp
                        <div class="inline-flex items-baseline gap-1.5 min-w-0">
                            <span class="text-[11px] font-medium text-[var(--nx-muted)] truncate max-w-[11rem]">{{ $row['label'] }}</span>
                            <span class="text-lg font-semibold tracking-tight tabular-nums {{ $naMaster ? 'text-[var(--nx-muted)]/40' : $toneOf($rowKey, $t['value']) }}">
                                @if($naMaster)–@else{{ $t['implied'] ? '≈' : '' }}{{ $signOf($rowKey, $t['value']) }}{{ $fmtRow($rowKey, $magOf($rowKey, $t['value'])) }}<span class="text-[11px] font-normal text-[var(--nx-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span>@endif
                            </span>
                            @if(! $isF && ! $isMaster && $t['rest'] > 0)
                                <span class="text-[10px] text-amber-600 whitespace-nowrap" title="Rest wird nach unten verteilt">· Rest {{ $fmt($t['rest']) }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- ═══════════ Grid ═══════════ --}}
            <div class="rounded-2xl border border-[var(--nx-line)]/60 bg-[var(--nx-surface)] overflow-hidden">
                {{-- ═══ Kopf-Leiste der Tabelle = Steuerzentrale (liegt über der Scroll-Box, also immer sichtbar) ═══ --}}
                <div class="border-b border-[var(--nx-line)]/50 bg-[var(--nx-hover)]">
                    {{-- Tier 1: Zeit-Navigation der Tabelle — Zoom-Pfad (raus) links, Ebenen-Sprung rechts --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 pt-2.5 pb-1.5">
                        <nav class="flex items-center gap-0.5 flex-wrap min-w-0" aria-label="Zeit-Navigation">
                            @foreach($breadcrumb as $i => $crumb)
                                @if($i > 0)@svg('heroicon-o-chevron-right','w-3.5 h-3.5 text-[var(--nx-muted)]/50 shrink-0')@endif
                                <button type="button" wire:click="zoom('{{ $crumb['bucket'] }}')"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-sm transition-colors
                                        {{ $loop->last
                                            ? 'bg-[var(--nx-accent)]/10 text-[var(--nx-accent)] font-semibold ring-1 ring-[var(--nx-accent)]/20'
                                            : 'text-[var(--nx-muted)] hover:bg-[var(--nx-accent-soft)] hover:text-[var(--nx-text)]' }}"
                                    @unless($loop->last) title="Zurück zu „{{ $crumb['label'] }}“" @endunless>
                                    @if($i === 0)@svg('heroicon-o-calendar-days','w-3.5 h-3.5')@endif
                                    {{ $crumb['label'] }}
                                </button>
                            @endforeach
                        </nav>
                        <div class="flex items-center gap-1 shrink-0">
                            <span class="text-[10px] font-medium uppercase tracking-wider text-[var(--nx-muted)] mr-0.5">Ebene</span>
                            @foreach($levelNav as $ln)
                                @if($ln['state'] === 'ahead')
                                    <span class="text-xs font-medium px-2 py-1 rounded-md text-[var(--nx-muted)]/35 cursor-default"
                                        title="Tiefer als die aktuelle Ansicht — Spalte anklicken zum Reinzoomen">{{ $ln['label'] }}</span>
                                @else
                                    <button type="button"
                                        wire:click="{{ ($ln['jump'] ?? false) ? "viewLevelJump('".$ln['bucket']."', '".$ln['level']."')" : "zoom('".$ln['bucket']."')" }}"
                                        class="text-xs font-semibold px-2 py-1 rounded-md transition-colors
                                            {{ $ln['state'] === 'current'
                                                ? 'bg-[var(--nx-accent)] text-[var(--nx-on-accent)]'
                                                : 'text-[var(--nx-muted)] hover:bg-[var(--nx-accent-soft)] hover:text-[var(--nx-text)]' }}"
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
                                    {{ $editMode ? 'bg-[var(--nx-accent)] text-[var(--nx-on-accent)]' : 'text-[var(--nx-accent)] ring-1 ring-[var(--nx-accent)]/30 hover:bg-[var(--nx-accent)]/10' }}"
                                title="Nur offene Zellen werden zum Tippfeld. Geschlossene/berechnete/abgeleitete bleiben gesperrt.">
                                @svg('heroicon-o-pencil-square','w-3.5 h-3.5') {{ $editMode ? 'Fertig' : 'Bearbeiten' }}
                            </button>
                            <span class="text-[var(--nx-muted)]/30">·</span>
                            @if($canZoom)
                                <span class="inline-flex items-center gap-1 text-[11px] text-[var(--nx-muted)]" title="Auf einen Spaltenkopf klicken, um in diese Periode zu zoomen">@svg('heroicon-o-cursor-arrow-rays','w-3.5 h-3.5') Spalte anklicken = rein</span>
                                <span class="text-[var(--nx-muted)]/30">·</span>
                            @endif
                            <button type="button" wire:click="toggleShare"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs transition-colors
                                    {{ $showShare ? 'bg-[var(--nx-accent)]/10 text-[var(--nx-accent)] font-medium' : 'text-[var(--nx-muted)] hover:bg-[var(--nx-accent-soft)]' }}">
                                @svg('heroicon-o-chart-pie','w-3.5 h-3.5') Anteil %
                            </button>
                            <button type="button" wire:click="toggleDelta"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs transition-colors
                                    {{ $showDelta ? 'bg-[var(--nx-accent)]/10 text-[var(--nx-accent)] font-medium' : 'text-[var(--nx-muted)] hover:bg-[var(--nx-accent-soft)]' }}">
                                @svg('heroicon-o-arrow-trending-up','w-3.5 h-3.5') Δ Vorperiode
                            </button>
                            <button type="button" wire:click="toggleActual"
                                class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs transition-colors
                                    {{ $showActual ? 'bg-teal-500/15 text-teal-700 font-medium' : 'text-[var(--nx-muted)] hover:bg-[var(--nx-accent-soft)]' }}"
                                title="Ist-Werte + Abweichung anzeigen. Im Bearbeiten-Modus werden dann Ist-Werte eingegeben (jede Periode).">
                                @svg('heroicon-o-scale','w-3.5 h-3.5') Ist / Δ
                            </button>
                        </div>
                        {{-- Feld-Zustände: sieht es aus wie ein Feld, kannst du tippen — sonst nicht. --}}
                        <div class="flex items-center gap-3 text-[11px] text-[var(--nx-muted)]">
                            <span class="inline-flex items-center gap-1" title="Offen: hier kannst du (bald) tippen — Periode ist offen"><span class="w-3 h-3 rounded-sm bg-[var(--nx-accent)]/[0.12] ring-1 ring-inset ring-[var(--nx-accent)]/40"></span> offen · tippbar</span>
                            <span class="inline-flex items-center gap-1" title="Berechnet: ergibt sich aus anderen Zeilen"><span class="text-[9px] font-bold px-1 rounded bg-[var(--nx-accent-soft)] text-[var(--nx-muted)]">ƒ</span> berechnet</span>
                            <span class="inline-flex items-center gap-1" title="Abgeleitet: kommt aus den untergeordneten Planungen hoch"><span class="text-[9px] font-bold px-1 rounded bg-indigo-500/10 text-indigo-600">↑</span> abgeleitet</span>
                            <span class="inline-flex items-center gap-1" title="Zu: Periode geschlossen — keine Eingabe">@svg('heroicon-o-lock-closed','w-3 h-3 text-[var(--nx-muted)]/60') zu</span>
                            <span class="inline-flex items-center gap-1" title="Grob-Eingabe: gröber als die Erfassungs-Ebene — der Wert verteilt sich nach unten. Fluss wird per Schlüssel aufgeteilt, Rate/Bestand konstant repliziert.">@svg('heroicon-o-bars-arrow-down','w-3 h-3 text-amber-500/70') grob · verteilt</span>
                        </div>
                    </div>

                    @if($editMode)
                        <div class="px-4 pb-2.5 flex items-center gap-2 text-[11px]">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[var(--nx-accent)]/10 text-[var(--nx-accent)] font-medium">@svg('heroicon-o-pencil-square','w-3 h-3') Bearbeiten aktiv</span>
                            <span class="text-[var(--nx-muted)]"><span class="font-medium">Klick</span> wählt · <span class="font-medium">Ziehen/⇧</span> Bereich · <span class="font-medium">Tippen/Enter/Doppelklick</span> ändert · <span class="font-medium">Entf</span> leert · <span class="font-medium">Pfeile</span> bewegen · <span class="font-medium">⌘/Strg+C/V</span> kopiert/fügt ein · <span class="font-medium">100</span> setzt, <span class="font-medium">+50 · +5% · *1,1 · /2</span> rechnet.</span>

                            {{-- Auto-Forecast: die (zukünftige) Auswahl aus der Ist-Historie fortschreiben --}}
                            <span class="inline-flex items-center gap-1 pl-1 border-l border-[var(--nx-line)]/50">
                                <span class="text-[var(--nx-muted)]">⤳ Trend:</span>
                                <button type="button" @click="fcTrend('run_rate')" class="px-1.5 py-0.5 rounded bg-teal-500/10 text-teal-700 hover:bg-teal-500/20 transition-colors" title="Ausgewählte Zukunfts-Zellen aus der Ist-Historie fortschreiben: Ø der letzten Perioden (Run-Rate).">Run-Rate</button>
                                <button type="button" @click="fcTrend('growth')" class="px-1.5 py-0.5 rounded bg-teal-500/10 text-teal-700 hover:bg-teal-500/20 transition-colors" title="Geometrisches Ø-Wachstum der Historie fortschreiben.">Wachstum</button>
                                <button type="button" @click="fcTrend('linear')" class="px-1.5 py-0.5 rounded bg-teal-500/10 text-teal-700 hover:bg-teal-500/20 transition-colors" title="Lineare Regression über die Historie extrapolieren.">Linear</button>
                            </span>

                            @if($lastEdit)
                                {{-- Settle-Fenster: 30 s rückgängig, dann festgeschrieben. --}}
                                <div wire:key="settle-{{ $editNonce }}" x-data="{ left: 30 }"
                                    x-init="let t = setInterval(() => { if (--left <= 0) { clearInterval(t); $wire.clearLastEditIf({{ $editNonce }}) } }, 1000)"
                                    class="inline-flex items-center gap-2 px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-700 font-medium ml-auto">
                                    @svg('heroicon-o-check-circle','w-3.5 h-3.5')
                                    @php $fcAct = $lastEdit['action'] ?? 'saved'; $fcCnt = $lastEdit['count'] ?? 1; @endphp
                                    <span>@if($fcAct === 'saved') „{{ \Illuminate\Support\Str::limit($lastEdit['label'], 22) }}" gespeichert @else {{ $fcCnt }} Zellen{{ $lastEdit['label'] ? ' · '.\Illuminate\Support\Str::limit($lastEdit['label'], 16) : '' }} {{ $fcAct === 'cleared' ? 'geleert' : ($fcAct === 'pasted' ? 'eingefügt' : ($fcAct === 'trend' ? 'fortgeschrieben' : 'gefüllt')) }} @endif · festgeschrieben in <span x-text="left" class="tabular-nums"></span> s</span>
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

                <div x-data="{
                        dense: localStorage.getItem('fcDense') === '1',
                        collapsed: (() => { try { return JSON.parse(localStorage.getItem('fcCollapsed:{{ $plan->uuid }}')) || {}; } catch (e) { return {}; } })(),
                        isC(s) { return !! this.collapsed[s]; },
                        toggle(s) { this.collapsed[s] = ! this.collapsed[s]; this.persist(); },
                        setAll(v) { const secs = @js($sectionList); const m = {}; if (v) secs.forEach(s => m[s] = true); this.collapsed = m; this.persist(); },
                        persist() { localStorage.setItem('fcCollapsed:{{ $plan->uuid }}', JSON.stringify(this.collapsed)); },
                        toggleDense() { this.dense = ! this.dense; localStorage.setItem('fcDense', this.dense ? '1' : '0'); }
                     }">
                    @if(count($sectionList) > 1)
                        <div class="flex items-center gap-1.5 px-4 py-1.5 border-b border-[var(--nx-line)]/40 text-[11px] text-[var(--nx-muted)]">
                            <button type="button" @click="setAll(true)" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded hover:bg-[var(--nx-accent-soft)] transition-colors" title="Alle Gruppen einklappen">@svg('heroicon-o-chevron-double-up','w-3 h-3') Alle einklappen</button>
                            <button type="button" @click="setAll(false)" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded hover:bg-[var(--nx-accent-soft)] transition-colors" title="Alle Gruppen ausklappen">@svg('heroicon-o-chevron-double-down','w-3 h-3') Alle ausklappen</button>
                            <span class="text-[var(--nx-muted)]/30">·</span>
                            <button type="button" @click="toggleDense()" :class="dense ? 'bg-[var(--nx-accent)]/10 text-[var(--nx-accent)] font-medium' : 'hover:bg-[var(--nx-accent-soft)]'" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded transition-colors" title="Kompakte Darstellung: geringere Zeilenhöhe, Nebeninfos ausgeblendet">@svg('heroicon-o-bars-3-bottom-left','w-3 h-3') Kompakt</button>
                        </div>
                    @endif
                    <div class="overflow-auto max-h-[72vh]">
                    <table :class="{ 'fc-dense': dense }" class="min-w-full border-separate border-spacing-0 text-sm">
                        <thead>
                            <tr>
                                <th class="sticky left-0 top-0 z-30 bg-[var(--nx-surface)] text-left px-4 py-2.5 font-medium text-[11px] uppercase tracking-wider text-[var(--nx-muted)] border-b border-[var(--nx-line)]/60 min-w-[200px]">Zeile</th>
                                @if($zoomed)
                                    <th class="sticky top-0 z-20 bg-[var(--nx-surface)] text-right px-4 py-2.5 border-b border-r border-[var(--nx-line)]/60 whitespace-nowrap min-w-[120px]">
                                        <div class="text-xs font-semibold text-[var(--nx-text)]">{{ $breadcrumb[count($breadcrumb)-1]['label'] }}</div>
                                        <div class="text-[10px] font-normal text-[var(--nx-muted)]">Ebene gesamt</div>
                                    </th>
                                @endif
                                @foreach($columns as $col)
                                    @php $st = $colStatus[$col['bucket']] ?? ['state' => 'mixed', 'days' => null]; @endphp
                                    <th class="group/col sticky top-0 z-20 bg-[var(--nx-surface)] text-right px-3 py-2.5 border-b border-[var(--nx-line)]/60 whitespace-nowrap min-w-[96px]
                                        {{ $canZoom ? 'cursor-pointer hover:bg-[var(--nx-accent)]/[0.06] transition-colors' : '' }}
                                        {{ $st['state'] === 'closed' ? 'opacity-60' : '' }}"
                                        @if($canZoom) wire:click="zoom('{{ $col['bucket'] }}')" @endif>
                                        <div class="flex flex-col items-end gap-0.5">
                                            <span class="inline-flex items-center gap-1 text-xs font-medium text-[var(--nx-text)]">
                                                {{ $col['label'] }}
                                                @if($canZoom)@svg('heroicon-o-magnifying-glass-plus','w-3 h-3 text-[var(--nx-muted)]/50 group-hover/col:text-[var(--nx-accent)] transition-colors')@endif
                                            </span>
                                            @if($st['state'] === 'open')
                                                <span class="inline-flex items-center gap-0.5 text-[9px] font-medium text-emerald-600">@svg('heroicon-o-lock-open','w-2.5 h-2.5') offen · noch {{ $st['days'] }} T</span>
                                            @elseif($st['state'] === 'pending')
                                                <span class="inline-flex items-center gap-0.5 text-[9px] text-[var(--nx-muted)]">@svg('heroicon-o-clock','w-2.5 h-2.5') öffnet in {{ $st['days'] }} T</span>
                                            @elseif($st['state'] === 'closed')
                                                <span class="inline-flex items-center gap-0.5 text-[9px] text-[var(--nx-muted)]/70">@svg('heroicon-o-lock-closed','w-2.5 h-2.5') zu</span>
                                            @endif
                                        </div>
                                    </th>
                                @endforeach
                                {{-- Füll-Spalte: schluckt Restbreite, damit echte Spalten kompakt-links bleiben (kein Stretch) --}}
                                <th class="sticky top-0 z-20 bg-[var(--nx-surface)] border-b border-[var(--nx-line)]/60 w-full p-0"></th>
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
                                    <tr class="fc-sec">
                                        <td colspan="99" @click="toggle({{ \Illuminate\Support\Js::from($sec) }})" class="sticky left-0 bg-[var(--nx-accent-soft)]/60 hover:bg-[var(--nx-accent-soft)] px-4 py-1 border-y border-[var(--nx-line)]/50 cursor-pointer select-none transition-colors">
                                            <span class="inline-flex items-center gap-1.5">
                                                <span x-show="isC({{ \Illuminate\Support\Js::from($sec) }})">@svg('heroicon-o-chevron-right','w-3 h-3 text-[var(--nx-muted)]')</span>
                                                <span x-show="! isC({{ \Illuminate\Support\Js::from($sec) }})">@svg('heroicon-o-chevron-down','w-3 h-3 text-[var(--nx-muted)]')</span>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-[var(--nx-muted)]">{{ $sec }}</span>
                                                <span x-show="isC({{ \Illuminate\Support\Js::from($sec) }})" x-cloak class="text-[10px] font-medium text-[var(--nx-muted)]/60 tabular-nums">{{ $sectionCounts[$sec] ?? 0 }} Zeilen</span>
                                            </span>
                                        </td>
                                    </tr>
                                @endif
                                @php $lastSection = $sec; @endphp
                                <tr class="group/row {{ $isF ? 'bg-[var(--nx-hover)]/40' : '' }}"@if($sec) x-show="! isC({{ \Illuminate\Support\Js::from($sec) }})"@endif>
                                    {{-- Zeilen-Kopf --}}
                                    <td class="sticky left-0 z-10 bg-[var(--nx-surface)] {{ $isF ? 'shadow-[inset_0_0_0_100vw_var(--nx-hover)]' : '' }} px-4 py-1.5 border-b border-[var(--nx-line)]/40 transition-colors">
                                        <div class="flex items-center gap-1.5">
                                            @if($isF)<span class="text-[9px] font-bold px-1 rounded bg-[var(--nx-accent-soft)] text-[var(--nx-muted)]" title="{{ ! empty($rowInfo[$rowKey]['expr']) ? 'Ausdruck: '.$rowInfo[$rowKey]['expr'] : 'Berechnet: ergibt sich aus anderen Zeilen — nicht eingebbar' }}">ƒ</span>@endif
                                            @if($isMaster && ! $isF)<span class="text-[9px] font-bold px-1 rounded bg-indigo-500/10 text-indigo-600 inline-flex items-center gap-0.5" title="Abgeleitet: kommt aus den untergeordneten Planungen hoch — hier nicht direkt eingebbar">↑</span>@endif
                                            @php $ta = $rowInfo[$rowKey]['timeAgg'] ?? 'flow'; @endphp
                                            @if($ta !== 'flow')<span class="text-[9px] font-semibold px-1 rounded bg-sky-500/10 text-sky-600" title="Rollt NICHT als Summe über die Zeit — {{ ['stock'=>'Schlusswert (Quartal = letzter Monat)','stock_open'=>'Eröffnungswert (erster Teilzeitraum)','avg'=>'Durchschnitt über die Teilzeiträume','wavg'=>'gewichteter Ø über die Zeit (Σ Wert×Gewicht ÷ Σ Gewicht)','recompute'=>'Ausdruck je Ebene neu gerechnet'][$ta] ?? $ta }}">{{ ['stock'=>'Bestand','stock_open'=>'Eröffnung','avg'=>'Ø','wavg'=>'Ø gew.','recompute'=>'neu gerechnet'][$ta] ?? $ta }}</span>@endif
                                            <span class="font-medium text-[var(--nx-text)]">{{ $row['label'] }}</span>
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
                                            <span class="fc-meta text-[10px] text-[var(--nx-muted)]/55 whitespace-nowrap ml-0.5">@if($isF){{ $rowInfo[$rowKey]['aggLabel'] }}@else{{ $rowInfo[$rowKey]['direction'] === 'income' ? 'Ertrag +' : ($rowInfo[$rowKey]['direction'] === 'expense' ? 'Aufwand −' : 'Messgröße') }}@endif @if($unitOf($rowKey))· {{ $unitOf($rowKey) }}@endif</span>
                                        </div>
                                    </td>

                                    {{-- Summenspalte (gezoomt) --}}
                                    @if($zoomed)
                                        @php
                                            $s = $meta[$rowKey] ?? ['value' => 0, 'rest' => 0, 'committed' => 0, 'implied' => false];
                                            $sPct = $s['value'] != 0 ? round($s['committed'] / $s['value'] * 100) : 100;
                                        @endphp
                                        <td class="text-right px-4 py-1.5 border-b border-r border-[var(--nx-line)]/40 bg-[var(--nx-accent)]/[0.04] align-top">
                                            <div class="font-semibold tabular-nums {{ $toneOf($rowKey, $s['value']) }}">{{ $s['implied'] ? '≈ ' : '' }}{{ $signOf($rowKey, $s['value']) }}{{ $fmtRow($rowKey, $magOf($rowKey, $s['value'])) }}<span class="text-[10px] font-normal text-[var(--nx-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span></div>
                                            @if(! $isF && $s['rest'] > 0)
                                                <div class="mt-1.5 h-1 rounded-full bg-[var(--nx-accent-soft)] overflow-hidden flex">
                                                    <div class="h-full bg-[var(--nx-accent)]" style="width: {{ $sPct }}%"></div>
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
                                            // (Plan) Grobe Rate/Bestand-Zelle MIT feinerem Detail: die grobe Schätzung würde vom Detail
                                            // dominiert — nicht editierbar; der Detail-Marker weist aufs Reinzoomen.
                                            if (! $showActual && $cellState === 'spread' && $rowReplicates && $hasDetailMark) {
                                                $cellState = 'derived';
                                            }
                                            $isInputRow = ! $isF && ! (($isMaster && ! $isF) || ! empty($rowInfo[$rowKey]['refPlans']));
                                            // Ist-Ansicht im Bearbeiten-Modus: JEDE Eingabe-Zeile ist im Ist-Kanal erfassbar (jede
                                            // Periode — Ist gibt's oft in geschlossenen Perioden); Formel/Ordner nicht.
                                            if ($showActual && $editMode) {
                                                $cellState = $isF ? 'computed' : ($isInputRow ? 'actual' : 'derived');
                                            }
                                            // Grid-Editor: editierbar = offen/spread/ist im Bearbeiten-Modus.
                                            $cellEditable = $editMode && in_array($cellState, ['open', 'spread', 'actual'], true);
                                            $isFu = $rowInfo[$rowKey]['isFactor'] ?? false;
                                            $cellObj = $isF ? null : ($row['cells'][$col['bucket']] ?? null);
                                            // Prefill: im Ist-Modus der Ist-Wert, sonst der erfasste Plan-Wert.
                                            $pfSrc = ($showActual && $editMode)
                                                ? (($cellObj['hasActual'] ?? false) ? ($cellObj['actual'] ?? 0) : null)
                                                : (($cellObj && ($cellObj['entered'] ?? false)) ? ($cellObj['value'] ?? 0) : null);
                                            $pfRaw = $pfSrc === null ? ''
                                                : rtrim(rtrim(number_format($isFu ? $pfSrc * 100 : (float) $pfSrc, 4, '.', ''), '0'), '.');
                                        @endphp
                                        <td class="relative text-right px-3 py-1.5 border-b border-[var(--nx-line)]/40 whitespace-nowrap align-top transition-colors
                                            {{ $cellState === 'open'
                                                ? 'bg-[var(--nx-accent)]/[0.04] cursor-text group-hover/row:bg-[var(--nx-accent)]/[0.07] hover:!bg-[var(--nx-accent)]/[0.11] hover:shadow-[inset_0_0_0_1px_var(--nx-accent)]'
                                                : ($cellState === 'spread'
                                                    ? 'bg-amber-400/[0.05] cursor-text group-hover/row:bg-amber-400/[0.09] hover:!bg-amber-400/[0.14] hover:shadow-[inset_0_0_0_1px_rgb(251_191_36_/_0.6)]'
                                                : ($cellState === 'actual'
                                                    ? 'bg-teal-500/[0.06] cursor-text group-hover/row:bg-teal-500/[0.1] hover:!bg-teal-500/[0.16] hover:shadow-[inset_0_0_0_1px_rgb(20_184_166_/_0.6)]'
                                                : ($cellState === 'locked'
                                                    ? 'opacity-50 group-hover/row:bg-[var(--nx-hover)]/50'
                                                    : 'group-hover/row:bg-[var(--nx-hover)]/60'))) }}"
                                            data-fc-r="{{ $gridRowIdx }}" data-fc-c="{{ $loop->index }}"
                                            data-fc-row="{{ $rowKey }}" data-fc-col="{{ $col['bucket'] }}"
                                            @if($cellEditable) data-fc-edit="1" data-fc-raw="{{ $pfRaw }}"@if($cellState === 'spread') data-fc-spread="1"@endif @if($isFu) data-fc-factor="1"@endif @endif>
                                            @if($hasDetailMark)
                                                <span class="absolute top-1 left-1.5 text-[var(--nx-accent)]/45 group-hover/row:text-[var(--nx-accent)]/70 transition-colors" title="Enthält feineres Detail — Spalte anklicken zum Reinzoomen">
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
                                                <span class="absolute top-1 left-1.5 text-[var(--nx-muted)]/40" title="{{ $lockTitle }}">
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
                                                    <span class="tabular-nums {{ $toneOf($rowKey, $fv) }} {{ ($partial[$rowKey][$col['bucket']] ?? false) ? 'italic opacity-50' : '' }}">{{ $signOf($rowKey, $fv) }}{{ $fmtRow($rowKey, $magOf($rowKey, $fv)) }}<span class="text-[10px] text-[var(--nx-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span></span>
                                                @else
                                                    <span class="text-[var(--nx-muted)]/40">·</span>
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
                                                                <span class="text-[9px] font-bold leading-none px-1 py-0.5 rounded bg-[var(--nx-accent)]/15 text-[var(--nx-accent)]">V</span>
                                                            @endif
                                                        @endif
                                                        <span class="tabular-nums {{ $cell['entered'] ? 'font-semibold' : '' }} {{ $toneOf($rowKey, $val) }}">{{ $signOf($rowKey, $val) }}{{ $fmtRow($rowKey, $val) }}<span class="text-[10px] font-normal text-[var(--nx-muted)] ml-0.5">{{ $unitOf($rowKey) }}</span></span>
                                                    </div>
                                                    @if($rest > 0)
                                                        <div class="mt-1.5 h-1 rounded-full bg-[var(--nx-accent-soft)] overflow-hidden flex">
                                                            <div class="h-full bg-[var(--nx-accent)]" style="width: {{ $pct }}%"></div>
                                                            <div class="h-full bg-amber-400/70" style="width: {{ 100 - $pct }}%"></div>
                                                        </div>
                                                        <div class="mt-0.5 text-[10px] text-amber-600">Rest {{ $fmt($rest) }}</div>
                                                    @endif
                                                @else
                                                    @php $sp = $meta[$rowKey]['spreadBy'][$col['bucket']] ?? 0; @endphp
                                                    @if($sp > 0)
                                                        <span class="tabular-nums italic text-[11px] text-[var(--nx-muted)]/55" title="verteilter Rest (nicht verbindlich)">≈&hairsp;{{ $signOf($rowKey, $sp) }}{{ $fmtRow($rowKey, $sp) }}</span>
                                                    @else
                                                        <span class="text-[var(--nx-muted)]/40">·</span>
                                                    @endif
                                                @endif
                                            @endif
                                            @php
                                                $hasShare = $showShare && isset($share[$rowKey][$col['bucket']]);
                                                $hasDelta = $showDelta && isset($delta[$rowKey][$col['bucket']]);
                                                $qBasis = $rowInfo[$rowKey]['quoteBasis'] ?? null;
                                                $hasQuote = $showShare && $qBasis && isset($quote[$rowKey][$col['bucket']]);
                                                // Ist + Δ: nur in der Ist-Ansicht, wo Ist-Daten vorliegen und nicht gerade editiert wird.
                                                $acCell = $row['cells'][$col['bucket']] ?? null;
                                                $hasAct = $showActual && ! $cellEditable && $acCell && ($acCell['hasActual'] ?? false);
                                            @endphp
                                            @if($hasShare || $hasDelta || $hasQuote || $hasAct)
                                                <div class="mt-2 pt-1.5 border-t border-dashed border-[var(--nx-line)]/40 flex flex-col items-end gap-1">
                                                    @if($hasAct)
                                                        @php $var = $acCell['variance'] ?? 0; @endphp
                                                        <div class="inline-flex items-center gap-1.5 text-[10px] font-medium" title="Ist − Plan = Abweichung">
                                                            <span class="text-teal-700">Ist {{ $signOf($rowKey, $acCell['actual']) }}{{ $fmtRow($rowKey, $magOf($rowKey, $acCell['actual'])) }}</span>
                                                            <span class="{{ $deltaTone($rowKey, $var) }}">Δ {{ $var > 0 ? '+' : ($var < 0 ? '−' : '') }}{{ $fmtRow($rowKey, abs($var)) }}</span>
                                                        </div>
                                                    @endif
                                                    @if($hasShare)
                                                        <div class="inline-flex items-center gap-1 text-[10px] font-medium text-[var(--nx-accent)]">
                                                            <span class="w-8 h-1 rounded-full bg-[var(--nx-accent-soft)] overflow-hidden inline-flex">
                                                                <span class="h-full bg-[var(--nx-accent)]" style="width: {{ min(100, $share[$rowKey][$col['bucket']]) }}%"></span>
                                                            </span>
                                                            {{ number_format($share[$rowKey][$col['bucket']], 1, ',', '.') }}&thinsp;%
                                                        </div>
                                                    @endif
                                                    @if($hasQuote)
                                                        <div class="inline-flex items-center gap-1 text-[10px] font-medium text-[var(--nx-text)]" title="Anteil an „{{ $rows[$qBasis]['label'] ?? $qBasis }}“ (Bezugsgröße)">
                                                            {{ number_format($quote[$rowKey][$col['bucket']], 1, ',', '.') }}&thinsp;% <span class="text-[var(--nx-muted)]/70">v. {{ \Illuminate\Support\Str::limit($rows[$qBasis]['label'] ?? $qBasis, 16) }}</span>
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
                                    {{-- Füll-Zelle zur Füll-Spalte im Kopf --}}
                                    <td class="border-b border-[var(--nx-line)]/40 w-full group-hover/row:bg-[var(--nx-hover)]/60"></td>
                                </tr>
                            @endforeach

                            @if(empty($rows))
                                <tr><td colspan="99" class="px-4 py-12 text-center text-sm text-[var(--nx-muted)]">Dieser Typ hat noch keine Zeilen.</td></tr>
                            @endif
                        </tbody>
                    </table>
                    </div>
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
                            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--nx-muted)]">Struktur</h3>
                            <a href="{{ route('forecast.plans.index') }}" wire:navigate class="text-[10px] text-[var(--nx-muted)] hover:text-[var(--nx-accent)]">Alle</a>
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
                        <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--nx-muted)] mb-2.5">{{ $contextRoots->isNotEmpty() ? 'Detailpläne (Drill-down)' : 'Planung' }}</h3>
                        <div class="space-y-0.5">
                            @foreach($contextOther as $op)
                                @php $cur = $op->uuid === $plan->uuid; @endphp
                                <a href="{{ route('forecast.plans.show', ['uuid' => $op->uuid, 'from' => $plan->uuid]) }}" wire:navigate
                                   class="flex items-center gap-1.5 py-1 px-1.5 rounded-md transition-colors {{ $cur ? 'bg-[var(--nx-accent)]/10 text-[var(--nx-accent)] font-semibold ring-1 ring-[var(--nx-accent)]/20' : 'text-[var(--nx-text)] hover:text-[var(--nx-accent)] hover:bg-[var(--nx-accent-soft)]' }}">
                                    @svg($navIcon($planRole[$op->id] ?? 'single'),'w-3.5 h-3.5 shrink-0 '.($cur ? '' : 'opacity-70')) <span class="truncate">{{ $op->name }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Zeit-Ebene: aktueller Zoom-Pfad --}}
                <div>
                    <h3 class="text-[10px] font-semibold uppercase tracking-wider text-[var(--nx-muted)] mb-2.5">Zeit-Ebene</h3>
                    <div class="flex flex-wrap items-center gap-x-1 gap-y-1">
                        @foreach($breadcrumb as $i => $crumb)
                            @if($i > 0)<span class="text-[var(--nx-muted)]/40 text-xs">/</span>@endif
                            <button type="button" wire:click="zoom('{{ $crumb['bucket'] }}')"
                                class="px-1.5 py-0.5 rounded text-xs transition-colors {{ $loop->last ? 'bg-[var(--nx-accent)]/10 text-[var(--nx-accent)] font-medium' : 'text-[var(--nx-muted)] hover:text-[var(--nx-text)] hover:bg-[var(--nx-accent-soft)]' }}">
                                {{ $crumb['label'] }}
                            </button>
                        @endforeach
                    </div>
                    <div class="mt-1.5 text-[11px] text-[var(--nx-muted)]">Ebene: <span class="text-[var(--nx-text)] font-medium">{{ $levelLabel }}</span></div>
                </div>

                {{-- Zurück zur Herkunft --}}
                @if($fromPlan)
                    <a href="{{ route('forecast.plans.show', ['uuid' => $fromPlan->uuid]) }}" wire:navigate
                       class="inline-flex items-center gap-1.5 text-[var(--nx-text)] hover:text-[var(--nx-accent)] font-medium">
                        @svg('heroicon-o-arrow-uturn-left','w-3.5 h-3.5') Zurück: {{ $fromPlan->name }}
                    </a>
                @endif

                {{-- Legende: was die Icons bedeuten --}}
                <div class="pt-3 border-t border-[var(--nx-line)]/40 space-y-1.5 text-[10px] text-[var(--nx-muted)]">
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-folder','w-3 h-3 text-indigo-500') Ordner — bündelt Planungen</div>
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-document-chart-bar','w-3 h-3 text-emerald-500') Blatt — hier werden Zahlen erfasst</div>
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-magnifying-glass-plus','w-3 h-3 text-amber-500') Drill-down — Feld mit eigener Planung dahinter</div>
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-bars-arrow-down','w-3 h-3 text-[var(--nx-accent)]/60') Zelle hat feineres Detail — reinzoomen</div>
                    <div class="flex items-center gap-1.5">@svg('heroicon-o-exclamation-triangle','w-3 h-3 text-amber-500') nur teilweise Detail — Kennzahl unvollständig</div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Auswahl-Modell (Excel-Optik): Bereichs-Tönung, aktive-Zelle-Rahmen, Grid-Editor --}}
    <style>
        td[data-fc-r] { cursor: cell; user-select: none; }
        td.fc-sel { background: color-mix(in srgb, var(--nx-accent) 12%, transparent) !important; }
        td.fc-active { box-shadow: inset 0 0 0 2px var(--nx-accent); z-index: 2; }
        td.fc-active.fc-sel { background: color-mix(in srgb, var(--nx-accent) 6%, transparent) !important; }
        body.fc-selecting { user-select: none; }
        .fc-editor { position: absolute; inset: 3px; width: calc(100% - 6px); box-sizing: border-box;
            text-align: right; font-variant-numeric: tabular-nums; font-size: .875rem;
            background: var(--nx-surface); color: var(--nx-text);
            border: 2px solid var(--nx-accent); border-radius: 4px; padding: 2px 5px; z-index: 10; outline: none; }
        .fc-editor.fc-editor--spread { border-color: rgb(245 158 11); }
        .fc-fill-handle { position: absolute; bottom: -4px; right: -4px; width: 9px; height: 9px; border-radius: 1px;
            background: var(--nx-accent); border: 1.5px solid var(--nx-surface); cursor: crosshair; z-index: 3; }
        td.fc-fill-preview { box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--nx-accent) 55%, transparent);
            background: color-mix(in srgb, var(--nx-accent) 7%, transparent) !important; }

        /* Alpine: erst nach Init anzeigen (verhindert Aufblitzen von Anzahl-Badges/collapsed-State) */
        [x-cloak] { display: none !important; }

        /* Kompakt-Modus: geringere Zeilenhöhe + Nebeninfos aus → mehr Zeilen auf einen Blick.
           Generisch (greift für jeden Plan-Typ, keine Zeilen-Spezifika). */
        table.fc-dense td { padding-top: 3px !important; padding-bottom: 3px !important; }
        table.fc-dense tr.fc-sec td { padding-top: 1px !important; padding-bottom: 1px !important; }
        table.fc-dense .fc-meta { display: none; }
    </style>

    {{-- Tastatur-Navigation der Eingabe-Felder: Enter/Tab → nächste offene Zelle (Shift = zurück).
         Der Fokuswechsel blurrt das aktuelle Feld → @blur speichert durchs Editier-Tor.
         Reihenfolge = DOM-Reihenfolge (zeilenweise links→rechts, dann nächste Zeile). --}}
    @script
    <script>
        // $wire dieser Komponente global greifbar machen (für Fill aus dem Drag-Handler).
        window.__fcWire = $wire;

        // Auto-Forecast: die aktuelle Grid-Auswahl (zukünftige Zellen) per Trend fortschreiben.
        window.fcTrend = (method) => {
            const cells = window.fcGrid && window.fcGrid.rangeCells(false);
            if (cells && cells.length && window.__fcWire) window.__fcWire.projectTrendRange(cells, method);
        };

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
                },
                // ── Copy/Paste (TSV, Excel-kompatibel) ──────────────────────────────────────
                cellText(td) {
                    const n = td.querySelector('.tabular-nums');
                    return ((n ? n.textContent : td.textContent) || '').replace(/[€%≈·]/g, '').replace(/\s+/g, '').trim();
                },
                selectionTSV() {
                    if (! this.active) return null;
                    const q = this.rect(), rows = [];
                    for (let r = q.r0; r <= q.r1; r++) {
                        const cols = [];
                        for (let c = q.c0; c <= q.c1; c++) {
                            const td = this.cell(r, c);
                            cols.push(! td ? '' : (td.dataset.fcEdit === '1' ? (td.dataset.fcRaw || '') : this.cellText(td)));
                        }
                        rows.push(cols.join('\t'));
                    }
                    return rows.join('\n');
                },
                pasteText(text) {
                    if (! this.active || text == null) return;
                    const g2 = text.replace(/\r\n?/g, '\n').split('\n').map(l => l.split('\t'));
                    while (g2.length > 1 && g2[g2.length - 1].length === 1 && g2[g2.length - 1][0] === '') g2.pop();
                    const cells = [], q = this.rect();
                    const single = g2.length === 1 && g2[0].length === 1;
                    if (single && (q.r0 !== q.r1 || q.c0 !== q.c1)) {
                        // 1 Wert → ganze aktuelle Auswahl füllen
                        this.rangeCells(true).forEach(c => cells.push({ row: c.row, bucket: c.bucket, value: g2[0][0] }));
                    } else {
                        // Block ab aktiver Zelle einsetzen (Excel-Import)
                        const b = this.active;
                        for (let i = 0; i < g2.length; i++) for (let j = 0; j < g2[i].length; j++) {
                            const td = this.cell(b.r + i, b.c + j);
                            if (td && td.dataset.fcEdit === '1') cells.push({ row: td.dataset.fcRow, bucket: td.dataset.fcCol, value: g2[i][j] });
                        }
                        this.anchor = { r: b.r, c: b.c };
                        this.active = this.clamp(b.r + g2.length - 1, b.c + g2[0].length - 1);
                        this.paint();
                    }
                    if (cells.length && window.__fcWire) window.__fcWire.saveRange(cells, null, 'pasted');
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

            // Copy/Cut/Paste über die nativen Events (keine Permission-Prompts). Nur wenn eine
            // Grid-Zelle aktiv ist, nicht editiert wird und kein anderes Feld fokussiert ist.
            const otherFieldFocused = () => { const a = document.activeElement; return a && a !== document.body && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.isContentEditable); };
            document.addEventListener('copy', (e) => {
                if (! G.active || G.editing || otherFieldFocused()) return;
                if (window.getSelection && ! window.getSelection().isCollapsed) return; // echte Textauswahl hat Vorrang
                const tsv = G.selectionTSV();
                if (tsv == null) return;
                (e.clipboardData || window.clipboardData).setData('text/plain', tsv);
                e.preventDefault();
            }, true);
            document.addEventListener('cut', (e) => {
                if (! G.active || G.editing || otherFieldFocused()) return;
                if (window.getSelection && ! window.getSelection().isCollapsed) return;
                const tsv = G.selectionTSV();
                if (tsv == null) return;
                (e.clipboardData || window.clipboardData).setData('text/plain', tsv);
                e.preventDefault();
                G.clearSel();
            }, true);
            document.addEventListener('paste', (e) => {
                if (! G.active || G.editing || otherFieldFocused()) return;
                const text = (e.clipboardData || window.clipboardData).getData('text/plain');
                e.preventDefault();
                G.pasteText(text);
            }, true);

            // Auswahl nach Livewire-Re-Render (Speichern) neu zeichnen.
            if (window.Livewire) {
                Livewire.hook('commit', ({ succeed }) => { succeed(() => { G.paint(); }); });
            }
        }
    </script>
    @endscript
</x-ui-page>
