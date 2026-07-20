<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastPlanType;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ListPlanTypesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan_type.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plan-types – Listet alle Planungs-Typen des aktuellen Teams inkl. Zeilen.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'required' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }

            $types = ForecastPlanType::where('team_id', $teamId)->orderBy('name')->get()
                ->map(fn ($t) => [
                    'uuid' => $t->uuid,
                    'key' => $t->key,
                    'name' => $t->name,
                    'description' => $t->description,
                    'rows' => $t->rows()->orderBy('order')->get(['key', 'label', 'kind'])->toArray(),
                    'plans_count' => $t->plans()->count(),
                ])->all();

            return ToolResult::success(['plan_types' => $types]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Laden der Typen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['forecast', 'plan_type', 'list'],
            'read_only' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
