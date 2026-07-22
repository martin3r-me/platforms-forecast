<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastLockPolicy;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class SetLockPolicyTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    private const LEVELS = ['year', 'quarter', 'month', 'day', 'hour'];

    public function getName(): string
    {
        return 'forecast.lock_policy.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /lock-policies/default – Setzt die wirksame Sperr-Regel des Teams (legt eine Team-Default-Regel '
            .'an oder aktualisiert sie; überschreibt die globale, ohne sie zu ändern). '
            .'period_level = auf welcher Ebene gesperrt wird: month = ganze Monate schließen gemeinsam ab; '
            .'day = jeder Tag einzeln (gestern zu, heute offen). lead_days = Vorlauf (öffnet X Tage vor Start), '
            .'grace_days = Nachlauf (bleibt Y Tage nach Ende offen). Nicht gesetzte Werte werden aus der aktuell '
            .'wirksamen Regel übernommen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period_level' => ['type' => 'string', 'enum' => self::LEVELS, 'description' => 'Sperr-Ebene (year|quarter|month|day|hour).'],
                'lead_days' => ['type' => 'integer', 'description' => 'Vorlauf in Tagen (öffnet vor Periodenstart).'],
                'grace_days' => ['type' => 'integer', 'description' => 'Nachlauf in Tagen (bleibt nach Periodenende offen).'],
                'freeze_past' => ['type' => 'boolean', 'description' => 'Vergangenheit sperren (Standard true).'],
                'name' => ['type' => 'string', 'description' => 'Optionaler Name; sonst automatisch aus Ebene/Vorlauf/Nachlauf.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }

            // Basis = aktuell wirksame Regel (Team-Default → global), damit ungesetzte Felder erhalten bleiben.
            $base = ForecastLockPolicy::resolveDefault($teamId);
            $level = $arguments['period_level'] ?? $base?->period_level ?? 'month';
            if (! in_array($level, self::LEVELS, true)) {
                return ToolResult::error('period_level muss eines von: '.implode(', ', self::LEVELS), 'VALIDATION_ERROR');
            }
            $lead = array_key_exists('lead_days', $arguments) ? max(0, (int) $arguments['lead_days']) : (int) ($base?->lead_days ?? 40);
            $grace = array_key_exists('grace_days', $arguments) ? max(0, (int) $arguments['grace_days']) : (int) ($base?->grace_days ?? 10);
            $freeze = array_key_exists('freeze_past', $arguments) ? (bool) $arguments['freeze_past'] : (bool) ($base?->freeze_past ?? true);
            $name = ! empty($arguments['name'])
                ? (string) $arguments['name']
                : sprintf('Team-Standard (%s · Vorlauf %d / Nachlauf %d)', $this->levelLabel($level), $lead, $grace);

            // Team-eigene Default-Regel: vorhandene aktualisieren, sonst neu anlegen.
            $policy = ForecastLockPolicy::where('team_id', $teamId)->where('is_default', true)->first();
            ForecastLockPolicy::where('team_id', $teamId)->where('is_default', true)
                ->when($policy, fn ($q) => $q->where('id', '!=', $policy->id))->update(['is_default' => false]);

            $attrs = [
                'name' => $name,
                'period_level' => $level,
                'lead_days' => $lead,
                'grace_days' => $grace,
                'freeze_past' => $freeze,
                'is_default' => true,
            ];

            if ($policy) {
                $policy->fill($attrs)->save();
            } else {
                $policy = ForecastLockPolicy::create(array_merge(['team_id' => $teamId], $attrs));
            }

            return ToolResult::success([
                'uuid' => $policy->uuid,
                'name' => $policy->name,
                'period_level' => $policy->period_level,
                'lead_days' => $policy->lead_days,
                'grace_days' => $policy->grace_days,
                'freeze_past' => $policy->freeze_past,
                'is_default' => $policy->is_default,
                'scope' => 'team',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Setzen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    private function levelLabel(string $level): string
    {
        return ['year' => 'Jahr', 'quarter' => 'Quartal', 'month' => 'Monat', 'day' => 'Tag', 'hour' => 'Stunde'][$level] ?? $level;
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['forecast', 'lock_policy', 'update'], 'read_only' => false, 'requires_team' => true, 'risk_level' => 'write'];
    }
}
