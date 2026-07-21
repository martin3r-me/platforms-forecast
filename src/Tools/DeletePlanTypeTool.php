<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastPlanType;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class DeletePlanTypeTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan_type.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /plan-types/{plan_type} – Löscht einen Planungs-Typ (Soft-Delete, reversibel). '
            .'Parameter: plan_type (uuid oder key). Verweigert, solange noch Pläne diesen Typ nutzen '
            .'(erst die Pläne löschen). Nur für aufgeräumte, ungenutzte Typen gedacht.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_type' => ['type' => 'string', 'description' => 'uuid oder key des Typs.'],
            ],
            'required' => ['plan_type'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }

            $ref = (string) ($arguments['plan_type'] ?? '');
            $type = ForecastPlanType::where('team_id', $teamId)
                ->where(fn ($q) => $q->where('uuid', $ref)->orWhere('key', $ref))
                ->first();
            if (! $type) {
                return ToolResult::error('Planungs-Typ nicht gefunden.', 'TYPE_NOT_FOUND');
            }

            $count = $type->plans()->count();
            if ($count > 0) {
                return ToolResult::error(
                    "Typ „{$type->name}\" wird noch von {$count} Plan(en) genutzt — erst die Pläne löschen.",
                    'TYPE_IN_USE'
                );
            }

            (new PlanService())->deletePlanType($type);

            return ToolResult::success([
                'deleted' => ['uuid' => $type->uuid, 'key' => $type->key, 'name' => $type->name],
                'soft_delete' => true,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Löschen des Typs: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'plan_type', 'delete'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
