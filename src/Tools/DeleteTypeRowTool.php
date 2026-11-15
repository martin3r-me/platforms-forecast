<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class DeleteTypeRowTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan_type_row.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /plan-types/{plan_type}/rows/{row_key} – Löscht EINE Typ-Zeile (soft) samt ihrer '
            .'Quell-Verweise. Wirkt auf ALLE Instanzen des Typs. Schutz: verweisen andere Zeilen DESSELBEN '
            .'Typs auf diese Zeile (als Quelle, in einer expr-Formel, als quote_basis oder weight_by), wird '
            .'ohne force=true abgebrochen und die betroffenen Zeilen werden gemeldet (sonst brechen die '
            .'Formeln). Instanz-Zeilen bleiben unberührt (Instanz-Override mit gleichem key bleibt als '
            .'eigenständige Zeile stehen). Parameter: plan_type (uuid|key), row_key, force (optional).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_type' => ['type' => 'string', 'description' => 'uuid oder key des Typs.'],
                'row_key' => ['type' => 'string', 'description' => 'key der zu löschenden Zeile.'],
                'force' => ['type' => 'boolean', 'description' => 'true = auch löschen, wenn andere Zeilen darauf verweisen.'],
            ],
            'required' => ['plan_type', 'row_key'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            $rowKey = trim((string) ($arguments['row_key'] ?? ''));
            if (empty($arguments['plan_type']) || $rowKey === '') {
                return ToolResult::error('plan_type und row_key sind erforderlich.', 'VALIDATION_ERROR');
            }

            $type = $this->findType((string) $arguments['plan_type'], $teamId);
            if (! $type) {
                return ToolResult::error('Planungs-Typ nicht gefunden.', 'TYPE_NOT_FOUND');
            }
            $row = $type->rows()->where('key', $rowKey)->first();
            if (! $row) {
                return ToolResult::error("Zeile '{$rowKey}' im Typ nicht gefunden.", 'ROW_NOT_FOUND');
            }

            $service = new PlanService();
            $consumers = $service->typeRowConsumers($type, $rowKey);
            $force = (bool) ($arguments['force'] ?? false);

            if ($consumers !== [] && ! $force) {
                $list = implode(', ', array_map(fn ($c) => "{$c['key']} ({$c['via']})", $consumers));

                return ToolResult::error(
                    "Zeile wird von anderen Zeilen referenziert — Löschen würde deren Formeln brechen: {$list}. "
                        .'Mit force=true trotzdem löschen.',
                    'ROW_REFERENCED',
                );
            }

            $service->deleteTypeRow($row);

            return ToolResult::success([
                'plan_type' => ['uuid' => $type->uuid, 'key' => $type->key],
                'deleted' => ['key' => $rowKey, 'label' => (string) $row->label],
                'soft_delete' => true,
                'forced' => $force && $consumers !== [],
                'broke_references' => $force ? $consumers : [],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Löschen der Zeile: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'plan_type', 'row', 'delete'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
