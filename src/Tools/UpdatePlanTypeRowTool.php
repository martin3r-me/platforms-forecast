<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Enums\Direction;
use Platform\Forecast\Models\ForecastUnit;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class UpdatePlanTypeRowTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan_type_row.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /plan-types/{plan_type}/rows/{row_key} – Aktualisiert Stammdaten EINER Vorlagen-Zeile '
            .'eines Planungs-Typs: label, direction (income|expense|neutral), unit (Code), order, time_agg. '
            .'time_agg = Zeit-Aggregation (Monate→Jahr): flow (Default, Stromgröße = Summe), '
            .'avg (Bestandsgröße = Ø über die Zeit, z. B. VZÄ/Köpfe/Auslastung), stock/stock_open '
            .'(Bestand = letzter/erster Teilzeitraum), recompute (Quote/Marge je Ebene neu rechnen), '
            .'wavg (gewichtetes Mittel, braucht weight_by). Die Ordner-Konsolidierung (Summe der Blätter) '
            .'bleibt unberührt — time_agg wirkt nur auf die Zeitachse. '
            .'KEIN Update von kind/agg hier (strukturelle Änderung — dafür Zeile löschen+neu anlegen). '
            .'Quell-Zeilen (sources/Verweise) ändern: forecast.row.sources.PUT. '
            .'Wirkt sofort auf alle Plan-Instanzen dieses Typs, da sie row_key referenzieren. '
            .'Parameter: plan_type (uuid oder key, required), row_key (required), mindestens ein weiteres Feld.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_type' => ['type' => 'string', 'description' => 'uuid oder key des Typs.'],
                'row_key' => ['type' => 'string', 'description' => 'key der Zeile innerhalb des Typs.'],
                'label' => ['type' => 'string'],
                'direction' => ['type' => 'string', 'enum' => ['income', 'expense', 'neutral']],
                'unit' => ['type' => 'string', 'description' => 'Einheit-Code, z. B. EUR, PCS, FTE.'],
                'order' => ['type' => 'integer'],
                'time_agg' => ['type' => 'string', 'enum' => ['flow', 'stock', 'stock_open', 'avg', 'recompute', 'wavg'], 'description' => 'Zeit-Aggregation Monate→Jahr. flow=Summe (Default), avg=Ø (Bestandsgrößen wie VZÄ), stock/stock_open=letzter/erster Teilzeitraum, recompute=Quote je Ebene neu, wavg=gewichtetes Mittel (weight_by nötig).'],
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

            $type = $this->findType((string) ($arguments['plan_type'] ?? ''), $teamId);
            if (! $type) {
                return ToolResult::error('Planungs-Typ nicht gefunden.', 'TYPE_NOT_FOUND');
            }

            $rowKey = (string) ($arguments['row_key'] ?? '');
            $row = $type->rows()->where('key', $rowKey)->first();
            if (! $row) {
                return ToolResult::error('Zeile nicht gefunden.', 'ROW_NOT_FOUND');
            }

            if (! array_key_exists('label', $arguments) && ! array_key_exists('direction', $arguments)
                && ! array_key_exists('unit', $arguments) && ! array_key_exists('order', $arguments)
                && ! array_key_exists('time_agg', $arguments)) {
                return ToolResult::error('Mindestens ein Feld (label/direction/unit/order/time_agg) angeben.', 'VALIDATION_ERROR');
            }

            if (array_key_exists('direction', $arguments) && ! in_array($arguments['direction'], ['income', 'expense', 'neutral'], true)) {
                return ToolResult::error('direction muss income, expense oder neutral sein.', 'VALIDATION_ERROR');
            }

            if (array_key_exists('time_agg', $arguments)
                && ! in_array($arguments['time_agg'], ['flow', 'stock', 'stock_open', 'avg', 'recompute', 'wavg'], true)) {
                return ToolResult::error('time_agg muss flow, stock, stock_open, avg, recompute oder wavg sein.', 'VALIDATION_ERROR');
            }

            $attrs = [
                'label' => $arguments['label'] ?? null,
                'direction' => isset($arguments['direction']) ? Direction::from($arguments['direction']) : null,
                'order' => $arguments['order'] ?? null,
                'time_agg' => $arguments['time_agg'] ?? null,
            ];

            if (array_key_exists('unit', $arguments)) {
                $unit = ForecastUnit::resolve((string) $arguments['unit'], $teamId);
                if (! $unit) {
                    return ToolResult::error('Einheit nicht gefunden.', 'UNIT_NOT_FOUND');
                }
                $attrs['unit_id'] = $unit->id;
            }

            $row = (new PlanService())->updateTypeRow($row, $attrs);

            return ToolResult::success([
                'plan_type' => $type->key,
                'row_key' => $row->key,
                'label' => $row->label,
                'direction' => $row->direction?->value,
                'unit_id' => $row->unit_id,
                'order' => $row->order,
                'time_agg' => $row->config['time_agg'] ?? 'flow',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Aktualisieren der Zeile: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'plan_type', 'row', 'update'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
