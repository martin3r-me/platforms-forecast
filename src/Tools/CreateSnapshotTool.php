<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class CreateSnapshotTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.snapshot.POST';
    }

    public function getDescription(): string
    {
        return 'POST /snapshots – Erstellt einen benannten Snapshot des aktuellen Stands einer '
            .'Planung (Baseline / "Stand vor X"). Parameter: plan (uuid), name (required), note.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'note' => ['type' => 'string'],
            ],
            'required' => ['plan', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            if (empty($arguments['name'])) {
                return ToolResult::error('name ist erforderlich.', 'VALIDATION_ERROR');
            }
            $plan = $this->findPlan((string) ($arguments['plan'] ?? ''), $teamId);
            if (! $plan) {
                return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
            }

            $snapshot = (new PlanService())->createSnapshot(
                $plan,
                (string) $arguments['name'],
                $this->userId($context),
                $arguments['note'] ?? null,
            );

            return ToolResult::success([
                'uuid' => $snapshot->uuid,
                'name' => $snapshot->name,
                'version' => $snapshot->version,
                'cells' => count((array) $snapshot->payload),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Snapshot: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'snapshot', 'create'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
