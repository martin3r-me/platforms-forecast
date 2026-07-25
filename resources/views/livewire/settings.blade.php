@php
    $tabs = [
        'overview' => ['Übersicht', 'squares-2x2'],
        'units' => ['Einheiten', 'scale'],
        'lock-policies' => ['Sperr-Regeln', 'lock-closed'],
        'distribution' => ['Verteilung', 'arrows-pointing-out'],
        'plan-types' => ['Plan-Typen', 'rectangle-stack'],
        'vocabulary' => ['Vokabular', 'language'],
    ];
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Forecast · Einstellungen" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Forecast', 'href' => route('forecast.dashboard'), 'icon' => 'presentation-chart-line'],
            ['label' => 'Einstellungen'],
            ['label' => $tabs[$section][0] ?? 'Übersicht'],
        ]" />
    </x-slot>

    <x-ui-page-container>
        <div class="space-y-5">

            {{-- Sub-Navigation --}}
            <div class="flex flex-wrap items-center gap-1.5">
                @foreach($tabs as $key => $tab)
                    <a href="{{ route('forecast.settings', ['section' => $key === 'overview' ? null : $key]) }}" wire:navigate
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm transition-colors
                           {{ $section === $key
                               ? 'bg-[color:var(--nx-accent-soft)] text-[color:var(--nx-text)] font-semibold'
                               : 'text-[color:var(--nx-muted)] hover:bg-[color:var(--nx-hover)] hover:text-[color:var(--nx-text)]' }}">
                        @svg('heroicon-o-'.$tab[1], 'w-4 h-4')
                        {{ $tab[0] }}
                    </a>
                @endforeach
            </div>

            <div class="text-xs text-[color:var(--nx-faint)] inline-flex items-center gap-1">
                @svg('heroicon-o-eye','w-3.5 h-3.5') Nur Ansicht — Bearbeiten folgt später
            </div>

            {{-- ═══ Übersicht ═══ --}}
            @if($section === 'overview')
                <x-nx-stat-grid :cols="4">
                    @foreach([
                        ['units','Einheiten','scale', $counts['units'] ?? 0, 'mit Umrechnung je Dimension'],
                        ['lock-policies','Sperr-Regeln','lock-closed', $counts['policies'] ?? 0, 'Vorlauf/Nachlauf, Kaskade'],
                        ['distribution','Verteilung','arrows-pointing-out', $counts['distributions'] ?? 0, 'Schlüssel nach unten (gleichmäßig/saisonal)'],
                        ['plan-types','Plan-Typen','rectangle-stack', $counts['types'] ?? 0, 'Zeilen-Vorlagen'],
                        ['vocabulary','Vokabular','language', null, 'System-Listen (Arten, Aggregationen …)'],
                    ] as $card)
                        <x-nx-stat
                            :href="route('forecast.settings', ['section' => $card[0]])"
                            :label="$card[1]"
                            :value="$card[3] !== null ? $card[3] : '—'"
                            :hint="$card[4]"
                            :icon="'heroicon-o-'.$card[2]"
                            wire:navigate />
                    @endforeach
                </x-nx-stat-grid>
            @endif

            {{-- ═══ Einheiten ═══ --}}
            @if($section === 'units')
                <x-nx-section title="Einheiten" description="Umrechnung innerhalb einer Dimension über den Faktor zur Basis">
                    <x-nx-card flush>
                        <x-nx-table>
                            <x-nx-table-header>
                                <x-nx-table-header-cell>Code</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Name</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Symbol</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Dimension</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Faktor → Basis</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Basis</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Geltung</x-nx-table-header-cell>
                            </x-nx-table-header>
                            <x-nx-table-body>
                                @foreach($units as $u)
                                    <x-nx-table-row>
                                        <x-nx-table-cell><span class="font-mono text-xs">{{ $u->code }}</span></x-nx-table-cell>
                                        <x-nx-table-cell>{{ $u->name }}</x-nx-table-cell>
                                        <x-nx-table-cell>{{ $u->symbol }}</x-nx-table-cell>
                                        <x-nx-table-cell><x-nx-badge>{{ $u->dimension }}</x-nx-badge></x-nx-table-cell>
                                        <x-nx-table-cell align="right"><span class="tabular-nums">{{ rtrim(rtrim(number_format($u->factor_to_base, 6, ',', '.'), '0'), ',') }}</span></x-nx-table-cell>
                                        <x-nx-table-cell>@if($u->is_base)<span class="text-[color:var(--nx-success)]">✓</span>@endif</x-nx-table-cell>
                                        <x-nx-table-cell><span class="text-xs text-[color:var(--nx-faint)]">{{ $u->team_id ? 'Team' : 'global' }}</span></x-nx-table-cell>
                                    </x-nx-table-row>
                                @endforeach
                            </x-nx-table-body>
                        </x-nx-table>
                    </x-nx-card>
                </x-nx-section>
            @endif

            {{-- ═══ Sperr-Regeln ═══ --}}
            @if($section === 'lock-policies')
                <x-nx-section title="Sperr-Regeln" description="Vergangenheit zu · Vorlauf öffnet vor Start · Nachlauf hält nach Ende offen · Entscheidung auf Perioden-Ebene, feinere erben">
                    <x-nx-card flush>
                        <x-nx-table>
                            <x-nx-table-header>
                                <x-nx-table-header-cell>Name</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Perioden-Ebene</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Vorlauf (T)</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Nachlauf (T)</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Vergangenheit</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Default</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Geltung</x-nx-table-header-cell>
                            </x-nx-table-header>
                            <x-nx-table-body>
                                @foreach($policies as $p)
                                    <x-nx-table-row>
                                        <x-nx-table-cell>{{ $p->name }}</x-nx-table-cell>
                                        <x-nx-table-cell>{{ $p->period_level }}</x-nx-table-cell>
                                        <x-nx-table-cell align="right"><span class="tabular-nums">{{ $p->lead_days }}</span></x-nx-table-cell>
                                        <x-nx-table-cell align="right"><span class="tabular-nums">{{ $p->grace_days }}</span></x-nx-table-cell>
                                        <x-nx-table-cell><span class="text-xs">{{ $p->freeze_past ? 'gesperrt' : 'offen' }}</span></x-nx-table-cell>
                                        <x-nx-table-cell>@if($p->is_default)<span class="text-[color:var(--nx-success)]">✓</span>@endif</x-nx-table-cell>
                                        <x-nx-table-cell><span class="text-xs text-[color:var(--nx-faint)]">{{ $p->team_id ? 'Team' : 'global' }}</span></x-nx-table-cell>
                                    </x-nx-table-row>
                                @endforeach
                            </x-nx-table-body>
                        </x-nx-table>
                    </x-nx-card>
                </x-nx-section>
            @endif

            {{-- ═══ Verteilung ═══ --}}
            @if($section === 'distribution')
                <x-nx-section title="Verteilungsschlüssel" description="Wie ein gröberer Wert / der Rest nach unten auf feinere, leere Zellen fällt — gleichmäßig oder saisonal (Monatsgewichte)">
                    @php $monate = ['J','F','M','A','M','J','J','A','S','O','N','D']; @endphp
                    <div class="space-y-3">
                        @foreach($distributions as $d)
                            <x-nx-card>
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        @svg($d->key === 'seasonal' ? 'heroicon-o-chart-bar' : 'heroicon-o-minus', 'w-4 h-4 text-[color:var(--nx-muted)]')
                                        <span class="font-medium text-[color:var(--nx-text)]">{{ $d->name }}</span>
                                        @if($d->is_default)<x-nx-badge variant="success">Default</x-nx-badge>@endif
                                    </div>
                                    <span class="text-xs text-[color:var(--nx-faint)]">{{ $d->key === 'seasonal' ? 'saisonal' : 'gleichmäßig' }} · {{ $d->team_id ? 'Team' : 'global' }}</span>
                                </div>
                                @if($d->key === 'seasonal' && is_array($d->weights) && count($d->weights) === 12)
                                    @php $maxW = max($d->weights) ?: 1; @endphp
                                    <div class="mt-3 flex items-end gap-1">
                                        @foreach($d->weights as $i => $w)
                                            <div class="flex-1 flex flex-col items-center gap-1">
                                                <span class="text-[9px] text-[color:var(--nx-faint)] tabular-nums">{{ number_format($w, 1, ',', '.') }}</span>
                                                <div class="w-full rounded-t bg-[color:var(--nx-accent)]" style="height: {{ max(3, (int) round($w / $maxW * 56)) }}px" title="{{ $monate[$i] }}: Gewicht {{ $w }}"></div>
                                                <span class="text-[9px] text-[color:var(--nx-faint)]">{{ $monate[$i] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="mt-2 text-xs text-[color:var(--nx-faint)]">Gleiche Gewichte auf alle Perioden.</div>
                                @endif
                            </x-nx-card>
                        @endforeach
                    </div>
                </x-nx-section>
            @endif

            {{-- ═══ Plan-Typen ═══ --}}
            @if($section === 'plan-types')
                <x-nx-section title="Plan-Typen" description="Vorlagen: definieren die Zeilen-Struktur">
                    <x-nx-card flush>
                        <x-nx-table>
                            <x-nx-table-header>
                                <x-nx-table-header-cell>Name</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Key</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Zeilen</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Pläne</x-nx-table-header-cell>
                            </x-nx-table-header>
                            <x-nx-table-body>
                                @foreach($types as $t)
                                    <x-nx-table-row>
                                        <x-nx-table-cell>{{ $t->name }}</x-nx-table-cell>
                                        <x-nx-table-cell><span class="font-mono text-xs text-[color:var(--nx-muted)]">{{ $t->key }}</span></x-nx-table-cell>
                                        <x-nx-table-cell align="right"><span class="tabular-nums">{{ $t->rows_count }}</span></x-nx-table-cell>
                                        <x-nx-table-cell align="right"><span class="tabular-nums">{{ $t->plans_count }}</span></x-nx-table-cell>
                                    </x-nx-table-row>
                                @endforeach
                            </x-nx-table-body>
                        </x-nx-table>
                    </x-nx-card>
                </x-nx-section>
            @endif

            {{-- ═══ Vokabular ═══ --}}
            @if($section === 'vocabulary')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($vocab as $group => $items)
                        <x-nx-card>
                            <div class="text-sm font-semibold text-[color:var(--nx-text)] mb-2">{{ $group }}</div>
                            <div class="space-y-1.5">
                                @foreach($items as $item)
                                    <div class="flex items-center gap-2 text-sm">
                                        <span class="font-mono text-xs px-1.5 py-0.5 rounded bg-[color:var(--nx-accent-soft)] text-[color:var(--nx-muted)]">{{ $item['code'] }}</span>
                                        <span class="text-[color:var(--nx-text)]">{{ $item['label'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </x-nx-card>
                    @endforeach
                </div>
            @endif

        </div>
    </x-ui-page-container>
</x-ui-page>
