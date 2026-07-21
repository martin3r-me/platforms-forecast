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
                       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm transition-colors
                           {{ $section === $key
                               ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-semibold ring-1 ring-[var(--ui-primary)]/20'
                               : 'text-[var(--ui-muted)] hover:bg-[var(--ui-muted-10)] hover:text-[var(--ui-secondary)]' }}">
                        @svg('heroicon-o-'.$tab[1], 'w-4 h-4')
                        {{ $tab[0] }}
                    </a>
                @endforeach
            </div>

            <div class="text-xs text-[var(--ui-muted)] inline-flex items-center gap-1">
                @svg('heroicon-o-eye','w-3.5 h-3.5') Nur Ansicht — Bearbeiten folgt später
            </div>

            {{-- ═══ Übersicht ═══ --}}
            @if($section === 'overview')
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                    @foreach([
                        ['units','Einheiten','scale', $counts['units'] ?? 0, 'mit Umrechnung je Dimension'],
                        ['lock-policies','Sperr-Regeln','lock-closed', $counts['policies'] ?? 0, 'Vorlauf/Nachlauf, Kaskade'],
                        ['distribution','Verteilung','arrows-pointing-out', $counts['distributions'] ?? 0, 'Schlüssel nach unten (gleichmäßig/saisonal)'],
                        ['plan-types','Plan-Typen','rectangle-stack', $counts['types'] ?? 0, 'Zeilen-Vorlagen'],
                        ['vocabulary','Vokabular','language', null, 'System-Listen (Arten, Aggregationen …)'],
                    ] as $card)
                        <a href="{{ route('forecast.settings', ['section' => $card[0]]) }}" wire:navigate
                           class="group relative overflow-hidden rounded-2xl border border-[var(--ui-border)]/60 bg-[var(--ui-surface)] p-4 hover:-translate-y-0.5 hover:shadow-md transition-all">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-[var(--ui-primary)]/10">
                                    @svg('heroicon-o-'.$card[2], 'w-4.5 h-4.5 text-[var(--ui-primary)]')
                                </div>
                                @if($card[3] !== null)
                                    <span class="text-2xl font-semibold tabular-nums text-[var(--ui-secondary)]">{{ $card[3] }}</span>
                                @endif
                            </div>
                            <div class="mt-3 font-medium text-[var(--ui-secondary)]">{{ $card[1] }}</div>
                            <div class="text-xs text-[var(--ui-muted)]">{{ $card[4] }}</div>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- ═══ Einheiten ═══ --}}
            @if($section === 'units')
                <x-ui-panel title="Einheiten" subtitle="Umrechnung innerhalb einer Dimension über den Faktor zur Basis">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] uppercase tracking-wider text-[var(--ui-muted)] border-b border-[var(--ui-border)]/50">
                                    <th class="py-2 pr-4">Code</th><th class="py-2 pr-4">Name</th><th class="py-2 pr-4">Symbol</th>
                                    <th class="py-2 pr-4">Dimension</th><th class="py-2 pr-4 text-right">Faktor → Basis</th>
                                    <th class="py-2 pr-4">Basis</th><th class="py-2 pr-4">Geltung</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($units as $u)
                                    <tr class="border-b border-[var(--ui-border)]/30">
                                        <td class="py-2 pr-4 font-mono text-xs">{{ $u->code }}</td>
                                        <td class="py-2 pr-4 text-[var(--ui-secondary)]">{{ $u->name }}</td>
                                        <td class="py-2 pr-4">{{ $u->symbol }}</td>
                                        <td class="py-2 pr-4"><span class="px-1.5 py-0.5 rounded bg-[var(--ui-muted-10)] text-xs">{{ $u->dimension }}</span></td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ rtrim(rtrim(number_format($u->factor_to_base, 6, ',', '.'), '0'), ',') }}</td>
                                        <td class="py-2 pr-4">@if($u->is_base)<span class="text-emerald-600">✓</span>@endif</td>
                                        <td class="py-2 pr-4 text-xs text-[var(--ui-muted)]">{{ $u->team_id ? 'Team' : 'global' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui-panel>
            @endif

            {{-- ═══ Sperr-Regeln ═══ --}}
            @if($section === 'lock-policies')
                <x-ui-panel title="Sperr-Regeln" subtitle="Vergangenheit zu · Vorlauf öffnet vor Start · Nachlauf hält nach Ende offen · Entscheidung auf Perioden-Ebene, feinere erben">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] uppercase tracking-wider text-[var(--ui-muted)] border-b border-[var(--ui-border)]/50">
                                    <th class="py-2 pr-4">Name</th><th class="py-2 pr-4">Perioden-Ebene</th>
                                    <th class="py-2 pr-4 text-right">Vorlauf (T)</th><th class="py-2 pr-4 text-right">Nachlauf (T)</th>
                                    <th class="py-2 pr-4">Vergangenheit</th><th class="py-2 pr-4">Default</th><th class="py-2 pr-4">Geltung</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($policies as $p)
                                    <tr class="border-b border-[var(--ui-border)]/30">
                                        <td class="py-2 pr-4 text-[var(--ui-secondary)]">{{ $p->name }}</td>
                                        <td class="py-2 pr-4">{{ $p->period_level }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $p->lead_days }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $p->grace_days }}</td>
                                        <td class="py-2 pr-4 text-xs">{{ $p->freeze_past ? 'gesperrt' : 'offen' }}</td>
                                        <td class="py-2 pr-4">@if($p->is_default)<span class="text-emerald-600">✓</span>@endif</td>
                                        <td class="py-2 pr-4 text-xs text-[var(--ui-muted)]">{{ $p->team_id ? 'Team' : 'global' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui-panel>
            @endif

            {{-- ═══ Verteilung ═══ --}}
            @if($section === 'distribution')
                <x-ui-panel title="Verteilungsschlüssel" subtitle="Wie ein gröberer Wert / der Rest nach unten auf feinere, leere Zellen fällt — gleichmäßig oder saisonal (Monatsgewichte)">
                    @php $monate = ['J','F','M','A','M','J','J','A','S','O','N','D']; @endphp
                    <div class="space-y-3">
                        @foreach($distributions as $d)
                            <div class="rounded-xl border border-[var(--ui-border)]/50 p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        @svg($d->key === 'seasonal' ? 'heroicon-o-chart-bar' : 'heroicon-o-minus', 'w-4 h-4 text-[var(--ui-primary)]')
                                        <span class="font-medium text-[var(--ui-secondary)]">{{ $d->name }}</span>
                                        @if($d->is_default)<span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/10 text-emerald-600 font-medium">Default</span>@endif
                                    </div>
                                    <span class="text-xs text-[var(--ui-muted)]">{{ $d->key === 'seasonal' ? 'saisonal' : 'gleichmäßig' }} · {{ $d->team_id ? 'Team' : 'global' }}</span>
                                </div>
                                @if($d->key === 'seasonal' && is_array($d->weights) && count($d->weights) === 12)
                                    @php $maxW = max($d->weights) ?: 1; @endphp
                                    <div class="mt-3 flex items-end gap-1 h-16">
                                        @foreach($d->weights as $i => $w)
                                            <div class="flex-1 flex flex-col items-center justify-end gap-1">
                                                <div class="w-full rounded-t bg-[var(--ui-primary)]/60" style="height: {{ max(4, round($w / $maxW * 100)) }}%" title="{{ $monate[$i] }}: Gewicht {{ $w }}"></div>
                                                <span class="text-[9px] text-[var(--ui-muted)]">{{ $monate[$i] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="mt-2 text-xs text-[var(--ui-muted)]">Gleiche Gewichte auf alle Perioden.</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-ui-panel>
            @endif

            {{-- ═══ Plan-Typen ═══ --}}
            @if($section === 'plan-types')
                <x-ui-panel title="Plan-Typen" subtitle="Vorlagen: definieren die Zeilen-Struktur">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-[11px] uppercase tracking-wider text-[var(--ui-muted)] border-b border-[var(--ui-border)]/50">
                                    <th class="py-2 pr-4">Name</th><th class="py-2 pr-4">Key</th>
                                    <th class="py-2 pr-4 text-right">Zeilen</th><th class="py-2 pr-4 text-right">Pläne</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($types as $t)
                                    <tr class="border-b border-[var(--ui-border)]/30">
                                        <td class="py-2 pr-4 text-[var(--ui-secondary)]">{{ $t->name }}</td>
                                        <td class="py-2 pr-4 font-mono text-xs text-[var(--ui-muted)]">{{ $t->key }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $t->rows_count }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $t->plans_count }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui-panel>
            @endif

            {{-- ═══ Vokabular ═══ --}}
            @if($section === 'vocabulary')
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    @foreach($vocab as $group => $items)
                        <x-ui-panel :title="$group">
                            <div class="space-y-1.5">
                                @foreach($items as $item)
                                    <div class="flex items-center gap-2 text-sm">
                                        <span class="font-mono text-xs px-1.5 py-0.5 rounded bg-[var(--ui-muted-10)] text-[var(--ui-muted)]">{{ $item['code'] }}</span>
                                        <span class="text-[var(--ui-secondary)]">{{ $item['label'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </x-ui-panel>
                    @endforeach
                </div>
            @endif

        </div>
    </x-ui-page-container>
</x-ui-page>
