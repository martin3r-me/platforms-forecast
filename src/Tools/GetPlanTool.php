<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Services\PlanReconciler;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class GetPlanTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans[/{plan}] – Ohne plan: listet Planungen des Teams. Mit plan (uuid): '
            .'liefert die reconciled Sicht (Wert + Rest je Zeile/Bucket, inkl. Plus/Detail).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid. Weglassen, um zu listen.'],
                'organization_entity_id' => ['type' => 'integer', 'description' => 'Filter beim Listen.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }

            if (! empty($arguments['plan'])) {
                $plan = $this->findPlan((string) $arguments['plan'], $teamId);
                if (! $plan) {
                    return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
                }

                return ToolResult::success((new PlanReconciler())->view($plan));
            }

            $query = ForecastPlan::where('team_id', $teamId);
            if (isset($arguments['organization_entity_id'])) {
                $query->where('organization_entity_id', (int) $arguments['organization_entity_id']);
            }

            $plans = $query->orderBy('name')->get()->map(fn ($p) => [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'plan_type_id' => $p->plan_type_id,
                'organization_entity_id' => $p->organization_entity_id,
                'org_mode' => $p->org_mode?->value,
                'version' => $p->current_version,
            ])->all();

            return ToolResult::success(['plans' => $plans]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Laden: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['forecast', 'plan', 'view'],
            'read_only' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
