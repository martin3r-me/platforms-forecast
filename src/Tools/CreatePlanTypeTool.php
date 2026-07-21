<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class CreatePlanTypeTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan_type.POST';
    }

    public function getDescription(): string
    {
        return 'POST /plan-types – Erstellt einen Planungs-Typ (Vorlage) inkl. Zeilen. '
            .'Parameter: name (required), key (required, stabiler Schlüssel), description, rows. '
            .'Jede Zeile: {key, label, kind[input|formula], unit (Code z.B. EUR/H/FTE/PCS/PCT), '
            .'direction[income|expense|neutral], config, order}. '
            .'Formula-Zeilen (read-only, aggregieren andere Zeilen) brauchen config: '
            .'{agg: sum|net|avg|median|min|max|count|product, sources: [rowKey, ...]}. '
            .'"net" = vorzeichenbehaftete Summe (income − expense).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Anzeigename des Typs.'],
                'key' => ['type' => 'string', 'description' => 'Stabiler Schlüssel (eindeutig je Team).'],
                'description' => ['type' => 'string'],
                'rows' => [
                    'type' => 'array',
                    'description' => 'Zeilen der Vorlage.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'kind' => ['type' => 'string', 'enum' => ['input', 'formula', 'sum', 'percent', 'reference']],
                            'unit' => ['type' => 'string', 'description' => 'Einheit-Code: EUR, KEUR, H, MIN, FTE, PCS, PCT.'],
                            'direction' => ['type' => 'string', 'enum' => ['income', 'expense', 'neutral']],
                            'section' => ['type' => 'string', 'description' => 'Optionale Sektion (Zeilen-Gruppe), z. B. "Umsatzerlöse". Aufeinanderfolgende Zeilen gleicher Sektion werden gruppiert.'],
                            'quote_basis' => ['type' => 'string', 'description' => 'Optional: Key einer Referenz-Zeile; zeigt bei „Anteil %" die Quote Betrag ÷ Referenz (z. B. Kostenzeile ÷ "gesamtleistung.betrag").'],
                            'config' => ['type' => 'object', 'description' => 'Bei formula: {agg, sources}.'],
                            'order' => ['type' => 'integer'],
                        ],
                        'required' => ['key'],
                    ],
                ],
            ],
            'required' => ['name', 'key'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            if (empty($arguments['name']) || empty($arguments['key'])) {
                return ToolResult::error('name und key sind erforderlich.', 'VALIDATION_ERROR');
            }

            $type = (new PlanService())->createType(
                $teamId,
                $this->userId($context),
                (string) $arguments['name'],
                (string) $arguments['key'],
                $arguments['rows'] ?? [],
                $arguments['description'] ?? null,
                $arguments['config'] ?? null,
            );

            return ToolResult::success([
                'uuid' => $type->uuid,
                'name' => $type->name,
                'key' => $type->key,
                'rows' => $type->rows()->orderBy('order')->get(['key', 'label', 'kind'])->toArray(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Anlegen des Typs: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'plan_type', 'create'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
