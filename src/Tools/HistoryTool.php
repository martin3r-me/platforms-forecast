<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class HistoryTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.history.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/history – Die append-only Vorwärts-Historie einer Planung: jede Änderung '
            .'als unveränderliches Event (Version, op set/clear, alt→neu Wert, Modus, Wer, Wann). settled=false = '
            .'noch im 30-s-Settle-Fenster (rückgängig möglich), sonst festgeschrieben. Optional gefiltert auf eine '
            .'Zeile und/oder einen Bucket. Parameter: plan (uuid), row (row_key), bucket (Präfix), limit (Default 100).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'row' => ['type' => 'string', 'description' => 'Nur diese Zeile (row_key).'],
                'bucket' => ['type' => 'string', 'description' => 'Bucket-Präfix-Filter (z. B. "2026" oder "2026-07").'],
                'limit' => ['type' => 'integer', 'description' => 'Max. Einträge (Default 100).'],
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

            $labels = [];
            foreach ($plan->resolvedRows() as $r) {
                $labels[$r->key] = $r->label;
            }

            // Append-only Ledger: jede Änderung ein unveränderliches Event (alt → neu, Version, Wer, Wann).
            $q = \Platform\Forecast\Models\ForecastChange::where('plan_id', $plan->id)
                ->whereIn('op', ['set', 'clear']);
            if (! empty($arguments['row'])) {
                $q->where('row_key', (string) $arguments['row']);
            }
            if (! empty($arguments['bucket'])) {
                $q->where('bucket_key', 'like', ((string) $arguments['bucket']).'%');
            }
            $limit = max(1, min(1000, (int) ($arguments['limit'] ?? 100)));
            $events = $q->orderByDesc('version')->orderByDesc('id')->limit($limit)->get();

            $settleWindow = now()->subSeconds(30);

            return ToolResult::success([
                'plan' => ['uuid' => $plan->uuid, 'name' => $plan->name, 'version' => $plan->current_version],
                'note' => 'Append-only Vorwärts-Historie (jedes Event unveränderlich). "settled=false" = noch im 30-s-Fenster (rückgängig möglich).',
                'count' => $events->count(),
                'events' => $events->map(fn ($e) => [
                    'version' => $e->version,
                    'op' => $e->op,
                    'row' => $e->row_key,
                    'label' => $labels[$e->row_key] ?? $e->row_key,
                    'bucket' => $e->bucket_key,
                    'old_value' => $e->old_value,
                    'new_value' => $e->new_value,
                    'mode' => $e->new_mode,
                    'user_id' => $e->user_id,
                    'at' => optional($e->created_at)->toIso8601String(),
                    'settled' => $e->created_at ? $e->created_at->lt($settleWindow) : true,
                ])->all(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'history', 'ledger'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
