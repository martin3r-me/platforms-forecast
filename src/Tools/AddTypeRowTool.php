<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Enums\RowKind;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class AddTypeRowTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan_type_row.POST';
    }

    public function getDescription(): string
    {
        return 'POST /plan-types/{plan_type}/rows – Fügt EINE neue Zeile in einen Typ (Vorlage) ein. '
            .'Wirkt sofort auf ALLE Instanzen des Typs (sie lösen Typ-Zeilen mit auf). Gleiche Zeilen-Form '
            .'wie forecast.plan_type.POST: {key (required, eindeutig im Typ), label, kind[input|formula|sum|percent|reference], '
            .'unit (Code z.B. EUR/H/FTE/PCS/PCT), direction[income|expense|neutral], section, quote_basis, '
            .'time_agg, weight_by, config, order}. Formel-Zeilen: config ENTWEDER {agg, sources} ODER {expr}. '
            .'sources-Einträge: "rowKey" (selbe Planung) oder {row_key, plan_uuid, weight?} (Cross-Plan-Verweis). '
            .'Ohne order wird ans Ende gehängt. Bestehende Zeile ändern: forecast.plan_type_row.PUT · '
            .'Verweise später pflegen: forecast.row.sources.PUT · löschen: forecast.plan_type_row.DELETE.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_type' => ['type' => 'string', 'description' => 'uuid oder key des Typs.'],
                'key' => ['type' => 'string', 'description' => 'Stabiler Zeilen-Schlüssel, eindeutig im Typ.'],
                'label' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['input', 'formula', 'sum', 'percent', 'reference']],
                'unit' => ['type' => 'string', 'description' => 'Einheit-Code: EUR, KEUR, H, MIN, FTE, PCS, PCT, FAKTOR.'],
                'direction' => ['type' => 'string', 'enum' => ['income', 'expense', 'neutral']],
                'section' => ['type' => 'string', 'description' => 'Optionale Zeilen-Gruppe (z. B. "Umsatzerlöse").'],
                'quote_basis' => ['type' => 'string', 'description' => 'Key einer Referenz-Zeile für „Anteil %" (Betrag ÷ Referenz).'],
                'time_agg' => ['type' => 'string', 'enum' => ['flow', 'stock', 'stock_open', 'avg', 'recompute', 'wavg'], 'description' => 'Zeit-Aggregation (flow = Default). Bei config.expr: recompute = je Ebene neu rechnen (Quoten/Margen).'],
                'weight_by' => ['type' => 'string', 'description' => 'Nur bei time_agg=wavg: row_key der Gewichts-Zeile.'],
                'config' => ['type' => 'object', 'description' => 'Bei formula ENTWEDER {agg, sources} ODER {expr}. sources: Liste aus "rowKey" (selbe Planung) oder {row_key, plan_uuid, weight?} (Cross-Plan-Verweis).'],
                'order' => ['type' => 'integer', 'description' => 'Position. Ohne Angabe ans Ende.'],
            ],
            'required' => ['plan_type', 'key'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            $key = trim((string) ($arguments['key'] ?? ''));
            if (empty($arguments['plan_type']) || $key === '') {
                return ToolResult::error('plan_type und key sind erforderlich.', 'VALIDATION_ERROR');
            }

            $type = $this->findType((string) $arguments['plan_type'], $teamId);
            if (! $type) {
                return ToolResult::error('Planungs-Typ nicht gefunden.', 'TYPE_NOT_FOUND');
            }
            if ($type->rows()->where('key', $key)->exists()) {
                return ToolResult::error("Zeile '{$key}' existiert im Typ bereits.", 'ROW_KEY_EXISTS');
            }

            $rowDef = [
                'key' => $key,
                'label' => $arguments['label'] ?? $key,
                'kind' => $arguments['kind'] ?? 'input',
            ];
            foreach (['unit', 'direction', 'section', 'quote_basis', 'time_agg', 'weight_by', 'config', 'order'] as $opt) {
                if (array_key_exists($opt, $arguments) && $arguments[$opt] !== null) {
                    $rowDef[$opt] = $arguments[$opt];
                }
            }

            $row = (new PlanService())->addTypeRow($type, $rowDef);

            return ToolResult::success([
                'plan_type' => ['uuid' => $type->uuid, 'key' => $type->key],
                'row' => [
                    'uuid' => $row->uuid,
                    'key' => $row->key,
                    'label' => (string) $row->label,
                    'kind' => $row->kind instanceof RowKind ? $row->kind->value : (string) $row->kind,
                    'order' => (int) $row->order,
                ],
                'sources' => (new PlanService())->getRowSources($row),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Anlegen der Zeile: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'plan_type', 'row', 'add', 'create'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
