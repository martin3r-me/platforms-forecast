<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ListSnapshotsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.snapshot.GET';
    }

    public function getDescription(): string
    {
        return 'GET /snapshots – Listet die benannten Snapshots einer Planung. Parameter: plan (uuid).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
            ],
            'required' => ['plan'],
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

            $snapshots = $plan->snapshots()->orderByDesc('created_at')->get()
                ->map(fn ($s) => [
                    'uuid' => $s->uuid,
                    'name' => $s->name,
                    'version' => $s->version,
                    'cells' => count((array) $s->payload),
                    'note' => $s->note,
                    'created_at' => optional($s->created_at)->toIso8601String(),
                ])->all();

            return ToolResult::success(['plan' => $plan->uuid, 'snapshots' => $snapshots]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Laden der Snapshots: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['forecast', 'snapshot', 'list'],
            'read_only' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
