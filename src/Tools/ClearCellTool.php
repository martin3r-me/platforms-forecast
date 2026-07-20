<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class ClearCellTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.cell.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /cells – Leert eine Zelle (entfernt die Eingabe) und erzeugt eine neue Version. '
            .'Parameter: plan (uuid), row_key, bucket_key.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'row_key' => ['type' => 'string'],
                'bucket_key' => ['type' => 'string'],
            ],
            'required' => ['plan', 'row_key', 'bucket_key'],
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

            $cleared = (new PlanService())->clearCell(
                $plan,
                (string) $arguments['row_key'],
                (string) $arguments['bucket_key'],
                $this->userId($context),
            );
            $plan->refresh();

            return ToolResult::success([
                'plan' => $plan->uuid,
                'cleared' => $cleared,
                'version' => $plan->current_version,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Leeren der Zelle: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'cell', 'clear'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
