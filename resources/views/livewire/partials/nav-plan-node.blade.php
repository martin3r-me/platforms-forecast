{{--
    Rekursiver Navigations-Knoten (Konsolidierungs-Baum im Kontext).
    Erwartet: $node, $depth, $currentUuid, $ancestorIds, $childrenByParent, $planRole, $componentSet
    Rolle bestimmt das Icon: Master · Instanz · Detail · Einzel. Aktueller Plan = Highlight.
--}}
@php
    $isCurrent = $node->uuid === $currentUuid;
    $onPath = ! $isCurrent && in_array($node->id, $ancestorIds ?? [], true);
    $role = $planRole[$node->id] ?? 'single';
    $icon = match ($role) {
        'master' => 'heroicon-o-square-3-stack-3d',
        'instance' => 'heroicon-o-cube',
        'detail' => 'heroicon-o-magnifying-glass-plus',
        default => 'heroicon-o-chart-bar-square',
    };
    $cls = $isCurrent
        ? 'bg-[var(--ui-primary)]/10 text-[var(--ui-primary)] font-semibold ring-1 ring-[var(--ui-primary)]/20'
        : ($onPath
            ? 'text-[var(--ui-secondary)] font-semibold hover:bg-[var(--ui-muted-10)]'
            : 'text-[var(--ui-secondary)] hover:text-[var(--ui-primary)] hover:bg-[var(--ui-muted-10)]');
    $kids = collect($childrenByParent[$node->id] ?? [])->filter(fn ($c) => empty($componentSet) || isset($componentSet[$c->id]));
@endphp

<a href="{{ route('forecast.plans.show', ['uuid' => $node->uuid]) }}" wire:navigate
   class="flex items-center gap-1.5 py-1 pr-2 rounded-md transition-colors {{ $cls }}"
   style="padding-left: {{ 6 + $depth * 16 }}px"
   @if($isCurrent) aria-current="page" @endif>
    @svg($icon, 'w-3.5 h-3.5 shrink-0 '.($isCurrent ? '' : 'opacity-70'))
    <span class="truncate text-sm">{{ $node->name }}</span>
    @if($kids->count())
        <span class="ml-auto text-[9px] font-medium text-[var(--ui-muted)]/60 shrink-0">{{ $kids->count() }}</span>
    @endif
</a>

@foreach($kids as $child)
    @include('forecast::livewire.partials.nav-plan-node', [
        'node' => $child,
        'depth' => $depth + 1,
        'currentUuid' => $currentUuid,
        'ancestorIds' => $ancestorIds ?? [],
        'childrenByParent' => $childrenByParent,
        'planRole' => $planRole,
        'componentSet' => $componentSet ?? [],
    ])
@endforeach
