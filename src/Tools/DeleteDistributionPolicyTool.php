<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastDistributionPolicy;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class DeleteDistributionPolicyTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.distribution_policy.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /distribution-policies/{policy} – Löscht einen team-eigenen Verteilungsschlüssel '
            .'(Soft-Delete). Pläne, die ihn nutzen, fallen auf den Default zurück. Globale nicht löschbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['policy' => ['type' => 'string', 'description' => 'uuid oder key.']],
            'required' => ['policy'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            $ref = (string) ($arguments['policy'] ?? '');
            $policy = ForecastDistributionPolicy::where('team_id', $teamId)
                ->where(fn ($q) => $q->where('uuid', $ref)->orWhere('key', $ref))->first();
            if (! $policy) {
                return ToolResult::error('Team-eigener Schlüssel nicht gefunden (globale sind nicht löschbar).', 'NOT_FOUND');
            }

            // Pläne, die ihn nutzen, auf Default (null) zurücksetzen
            ForecastPlan::where('team_id', $teamId)->where('distribution_policy_id', $policy->id)->update(['distribution_policy_id' => null]);
            $policy->delete();

            return ToolResult::success(['deleted' => ['uuid' => $policy->uuid, 'name' => $policy->name], 'soft_delete' => true]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Löschen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['forecast', 'distribution_policy', 'delete'], 'read_only' => false, 'requires_team' => true, 'risk_level' => 'write'];
    }
}
