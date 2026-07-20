<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class RestoreSnapshotTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.snapshot.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /snapshots/restore – Stellt den Stand eines Snapshots wieder her (ersetzt die '
            .'aktuellen Zellen) und erzeugt eine neue Version. Parameter: plan (uuid), snapshot (uuid).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'snapshot' => ['type' => 'string', 'description' => 'Snapshot-uuid.'],
            ],
            'required' => ['plan', 'snapshot'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            $plan = $this->findPlan((string) ($arguments['plan'] ?? ''), $teamId);
            if (! $plan) {
                return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
            }
            $snapshot = $this->findSnapshot((string) ($arguments['snapshot'] ?? ''), $teamId);
            if (! $snapshot || (int) $snapshot->plan_id !== (int) $plan->id) {
                return ToolResult::error('Snapshot nicht gefunden (oder gehört nicht zur Planung).', 'SNAPSHOT_NOT_FOUND');
            }

            (new PlanService())->restoreSnapshot($plan, $snapshot, $this->userId($context));
            $plan->refresh();

            return ToolResult::success([
                'plan' => $plan->uuid,
                'restored_from' => $snapshot->uuid,
                'restored_version' => $snapshot->version,
                'version' => $plan->current_version,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Wiederherstellen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'snapshot', 'restore'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
