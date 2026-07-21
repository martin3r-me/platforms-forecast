{{--
    Rekursiver Navigations-Knoten für den kompletten Plan-Baum in der inneren Sidebar.
    Erwartet: $node, $depth, $currentUuid, $ancestorIds, $childrenByParent
    Zeigt IMMER den ganzen Baum; aktueller Plan = Pin + Highlight, Pfad dorthin = fett.
--}}
@php
    $isCurrent = $node->uuid === $currentUuid;
    $onPath = ! $isCurrent && in_array($node->id, $ancestorIds ?? [], true);
    $cls = $isCurrent
        ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-semibold ring-1 ring-[var(--ui-primary)]/20'
        : ($onPath
            ? 'text-[var(--ui-secondary)] font-semibold hover:bg-[var(--ui-muted-10)]'
            : 'text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] hover:bg-[var(--ui-muted-10)]');
    $kids = $childrenByParent[$node->id] ?? [];
@endphp

<a href="{{ route('forecast.plans.show', ['uuid' => $node->uuid]) }}" wire:navigate
   class="flex items-center gap-1.5 py-1 pr-2 rounded-md transition-colors {{ $cls }}"
   style="padding-left: {{ 6 + $depth * 16 }}px"
   @if($isCurrent) aria-current="page" @endif>
    @if($isCurrent)
        @svg('heroicon-o-map-pin', 'w-3.5 h-3.5 shrink-0')
    @elseif($depth > 0)
        @svg('heroicon-o-arrow-turn-down-right', 'w-3 h-3 shrink-0 opacity-50')
    @else
        @svg('heroicon-o-chart-bar-square', 'w-3.5 h-3.5 shrink-0 opacity-70')
    @endif
    <span class="truncate text-sm">{{ $node->name }}</span>
    @if(count($kids))
        <span class="ml-auto text-[9px] text-[var(--ui-muted)]/60 shrink-0">{{ count($kids) }}</span>
    @endif
</a>

@foreach($kids as $child)
    @include('forecast::livewire.partials.nav-plan-node', [
        'node' => $child,
        'depth' => $depth + 1,
        'currentUuid' => $currentUuid,
        'ancestorIds' => $ancestorIds ?? [],
        'childrenByParent' => $childrenByParent,
    ])
@endforeach
