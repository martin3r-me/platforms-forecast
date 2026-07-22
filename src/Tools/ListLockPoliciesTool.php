<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastLockPolicy;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ListLockPoliciesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.lock_policy.GET';
    }

    public function getDescription(): string
    {
        return 'GET /lock-policies – Listet die Sperr-Regeln des Teams (+ globale) und markiert, welche '
            .'für dieses Team wirksam ist. Eine Sperr-Regel entscheidet, wann eine Periode zu ist: '
            .'period_level (auf welcher Ebene gesperrt wird), Vorlauf (öffnet vor Start), Nachlauf (hält nach Ende offen).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }

            $effective = ForecastLockPolicy::resolveDefault($teamId);
            $policies = ForecastLockPolicy::where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
                ->orderByDesc('is_default')->orderBy('name')->get();

            return ToolResult::success([
                'effective_uuid' => $effective?->uuid,
                'policies' => $policies->map(fn ($p) => [
                    'uuid' => $p->uuid,
                    'name' => $p->name,
                    'period_level' => $p->period_level,
                    'lead_days' => $p->lead_days,
                    'grace_days' => $p->grace_days,
                    'freeze_past' => $p->freeze_past,
                    'is_default' => $p->is_default,
                    'scope' => $p->team_id ? 'team' : 'global',
                    'effective' => $effective && $p->id === $effective->id,
                ])->all(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['forecast', 'lock_policy', 'list'],
            'read_only' => true,
            'requires_team' => true,
            'risk_level' => 'read',
        ];
    }
}
