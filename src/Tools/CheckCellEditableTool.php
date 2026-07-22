<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\CellEditability;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class CheckCellEditableTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.cell.editable.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/cell-editable – Das Editier-Tor: darf diese Zelle JETZT (in der UI) '
            .'geschrieben werden? Liefert editable + Zustand (open|computed|derived|locked) + Grund. '
            .'Dieselbe Regel, die die UI erzwingt (MCP/cell.PUT selbst bleibt Admin-Pfad ohne Sperre). '
            .'Parameter: plan (uuid), row_key, bucket_key.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid.'],
                'row_key' => ['type' => 'string'],
                'bucket_key' => ['type' => 'string', 'description' => 'z. B. "2026-07" oder "2026-07-15".'],
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

            $gate = (new CellEditability())->check(
                $plan,
                (string) ($arguments['row_key'] ?? ''),
                (string) ($arguments['bucket_key'] ?? ''),
            );

            return ToolResult::success([
                'plan' => ['uuid' => $plan->uuid, 'name' => $plan->name],
                'row_key' => (string) ($arguments['row_key'] ?? ''),
                'bucket_key' => (string) ($arguments['bucket_key'] ?? ''),
            ] + $gate);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'cell', 'editable', 'gate'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
