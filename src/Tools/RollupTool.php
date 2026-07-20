<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\OrgRollup;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class RollupTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.rollup.GET';
    }

    public function getDescription(): string
    {
        return 'GET /rollup – Org-Achse: rollt eine Zelle (row_key, bucket_key) eines Typs über '
            .'den Organisations-Teilbaum ab einem Knoten auf ("Draufsicht"). Liefert value + rest. '
            .'Parameter: plan_type (uuid oder key), organization_entity_id, row_key, bucket_key.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_type' => ['type' => 'string', 'description' => 'Typ per uuid oder key.'],
                'organization_entity_id' => ['type' => 'integer', 'description' => 'Wurzel-Knoten des Teilbaums.'],
                'row_key' => ['type' => 'string'],
                'bucket_key' => ['type' => 'string'],
            ],
            'required' => ['plan_type', 'organization_entity_id', 'row_key', 'bucket_key'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            $type = $this->findType((string) ($arguments['plan_type'] ?? ''), $teamId);
            if (! $type) {
                return ToolResult::error('Planungs-Typ nicht gefunden.', 'TYPE_NOT_FOUND');
            }

            $result = (new OrgRollup())->rollup(
                (int) $type->id,
                (int) $arguments['organization_entity_id'],
                (string) $arguments['row_key'],
                (string) $arguments['bucket_key'],
            );

            return ToolResult::success($result);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Aufrollen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['forecast', 'organization', 'rollup'],
            'read_only' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
