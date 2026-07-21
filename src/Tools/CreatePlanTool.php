<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class CreatePlanTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan.POST';
    }

    public function getDescription(): string
    {
        return 'POST /plans – Erstellt eine Plan-Instanz eines Typs und hängt sie optional an '
            .'einen Org-Knoten. Parameter: plan_type (required, uuid oder key), name (required), '
            .'organization_entity_id (integer, optional), org_mode (detail|plus, default detail), '
            .'base_level (year|month|day|hour), period_start, period_end, rows (Instanz-Zeilen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_type' => ['type' => 'string', 'description' => 'Typ per uuid oder key.'],
                'name' => ['type' => 'string'],
                'organization_entity_id' => ['type' => 'integer', 'description' => 'Org-Knoten (organization_entities.id).'],
                'org_mode' => ['type' => 'string', 'enum' => ['detail', 'plus'], 'description' => 'Wie die Instanz in den Elternknoten läuft.'],
                'base_level' => ['type' => 'string', 'enum' => ['year', 'month', 'day', 'hour']],
                'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'parent_plan' => ['type' => 'string', 'description' => 'uuid der Konsolidierungs-Elternplanung (dieser Plan wird Kind davon).'],
                'distribution_policy' => ['type' => 'string', 'description' => 'Verteilungsschlüssel (uuid oder key), z. B. "seasonal" für saisonale Abwärts-Verteilung. Ohne Angabe greift der Default (gleichmäßig).'],
                'rows' => [
                    'type' => 'array',
                    'description' => 'Zusätzliche Instanz-Zeilen (ergänzen den Typ).',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['plan_type', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            if (empty($arguments['plan_type']) || empty($arguments['name'])) {
                return ToolResult::error('plan_type und name sind erforderlich.', 'VALIDATION_ERROR');
            }

            $type = $this->findType((string) $arguments['plan_type'], $teamId);
            if (! $type) {
                return ToolResult::error('Planungs-Typ nicht gefunden.', 'TYPE_NOT_FOUND');
            }

            $orgMode = ($arguments['org_mode'] ?? 'detail') === 'plus' ? 'plus' : 'detail';

            $parentId = null;
            if (! empty($arguments['parent_plan'])) {
                $parent = $this->findPlan((string) $arguments['parent_plan'], $teamId);
                if (! $parent) {
                    return ToolResult::error('Elternplanung nicht gefunden.', 'PARENT_NOT_FOUND');
                }
                $parentId = $parent->id;
            }

            $distPolicyId = null;
            if (! empty($arguments['distribution_policy'])) {
                $ref = (string) $arguments['distribution_policy'];
                $dp = \Platform\Forecast\Models\ForecastDistributionPolicy::where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
                    ->where(fn ($q) => $q->where('uuid', $ref)->orWhere('key', $ref))
                    ->orderByRaw('team_id is null')
                    ->first();
                $distPolicyId = $dp?->id;
            }

            $plan = (new PlanService())->createPlan(
                $teamId,
                $this->userId($context),
                $type,
                (string) $arguments['name'],
                isset($arguments['organization_entity_id']) ? (int) $arguments['organization_entity_id'] : null,
                $orgMode,
                $arguments['rows'] ?? [],
                [
                    'base_level' => $arguments['base_level'] ?? 'month',
                    'period_start' => $arguments['period_start'] ?? null,
                    'period_end' => $arguments['period_end'] ?? null,
                    'parent_plan_id' => $parentId,
                    'distribution_policy_id' => $distPolicyId,
                ],
            );

            return ToolResult::success([
                'uuid' => $plan->uuid,
                'name' => $plan->name,
                'plan_type' => $type->key,
                'parent_plan_id' => $plan->parent_plan_id,
                'organization_entity_id' => $plan->organization_entity_id,
                'org_mode' => $plan->org_mode?->value,
                'version' => $plan->current_version,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Anlegen der Planung: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'plan', 'create'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
