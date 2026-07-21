<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastDistributionPolicy;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ListDistributionPoliciesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.distribution_policy.GET';
    }

    public function getDescription(): string
    {
        return 'GET /distribution-policies – Listet die Verteilungsschlüssel des Teams (+ globale).';
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

            $policies = ForecastDistributionPolicy::where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
                ->orderByDesc('is_default')->orderBy('name')->get();

            return ToolResult::success([
                'policies' => $policies->map(fn ($p) => [
                    'uuid' => $p->uuid,
                    'name' => $p->name,
                    'key' => $p->key,
                    'weights' => $p->weights,
                    'is_default' => $p->is_default,
                    'scope' => $p->team_id ? 'team' : 'global',
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
            'tags' => ['forecast', 'distribution_policy', 'list'],
            'read_only' => true,
            'requires_team' => true,
            'risk_level' => 'read',
        ];
    }
}
