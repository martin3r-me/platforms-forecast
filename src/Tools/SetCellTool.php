<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Reconciliation\Mode;
use Platform\Forecast\Services\PlanReconciler;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class SetCellTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.cell.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /cells – Setzt eine Zelle (Wert + Modus) und erzeugt eine neue Version. '
            .'Parameter: plan (uuid), row_key, bucket_key (z.B. "2026-07" oder "2026-07-12"), '
            .'value (Zahl), mode (detail|plus). WICHTIG: "detail" verfeinert einen bestehenden '
            .'Wert (ändert die Summe NICHT), "plus" kommt zusätzlich obendrauf.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid.'],
                'row_key' => ['type' => 'string'],
                'bucket_key' => ['type' => 'string', 'description' => 'Jahr "2026", Monat "2026-07", Tag "2026-07-12", Stunde "2026-07-12T14".'],
                'value' => ['type' => 'number'],
                'mode' => ['type' => 'string', 'enum' => ['detail', 'plus'], 'description' => 'detail = Teil des bestehenden Werts; plus = zusätzlich.'],
            ],
            'required' => ['plan', 'row_key', 'bucket_key', 'value', 'mode'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            foreach (['plan', 'row_key', 'bucket_key', 'mode'] as $req) {
                if (empty($arguments[$req])) {
                    return ToolResult::error("Parameter '{$req}' ist erforderlich.", 'VALIDATION_ERROR');
                }
            }
            if (! array_key_exists('value', $arguments) || ! is_numeric($arguments['value'])) {
                return ToolResult::error('value muss eine Zahl sein.', 'VALIDATION_ERROR');
            }
            $mode = Mode::tryFrom((string) $arguments['mode']);
            if (! $mode) {
                return ToolResult::error('mode muss detail oder plus sein.', 'VALIDATION_ERROR');
            }

            $plan = $this->findPlan((string) $arguments['plan'], $teamId);
            if (! $plan) {
                return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
            }

            $service = new PlanService();
            $service->setCell($plan, (string) $arguments['row_key'], (string) $arguments['bucket_key'], (float) $arguments['value'], $mode, $this->userId($context));

            $plan->refresh();
            $cell = (new PlanReconciler())->cell($plan, (string) $arguments['row_key'], (string) $arguments['bucket_key']);

            return ToolResult::success([
                'plan' => $plan->uuid,
                'row_key' => $arguments['row_key'],
                'bucket_key' => $arguments['bucket_key'],
                'mode' => $mode->value,
                'reconciled' => $cell,   // value + rest an diesem Bucket
                'version' => $plan->current_version,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Setzen der Zelle: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'cell', 'set'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
