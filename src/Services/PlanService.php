<?php

namespace Platform\Forecast\Services;

use Illuminate\Support\Facades\DB;
use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Models\ForecastChange;
use Platform\Forecast\Models\ForecastEntry;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Models\ForecastPlanType;
use Platform\Forecast\Models\ForecastRow;
use Platform\Forecast\Models\ForecastRowSource;
use Platform\Forecast\Models\ForecastSnapshot;
use Platform\Forecast\Models\ForecastUnit;
use Platform\Forecast\Reconciliation\Mode;

/**
 * Alle schreibenden Operationen. Jede Änderung erzeugt einen Change-Log-Eintrag
 * und hebt plan.current_version an ("jede Veränderung = neue Version").
 */
final class PlanService
{
    /**
     * @param  list<array{key:string, label?:string, kind?:string, config?:array, order?:int}>  $rows
     */
    public function createType(int $teamId, ?int $userId, string $name, string $key, array $rows = [], ?string $description = null, ?array $config = null): ForecastPlanType
    {
        return DB::transaction(function () use ($teamId, $userId, $name, $key, $rows, $description, $config) {
            $type = ForecastPlanType::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'config' => $config,
            ]);

            foreach (array_values($rows) as $i => $r) {
                $rowModel = ForecastRow::create($this->rowAttributes($r, $teamId, ['plan_type_id' => $type->id, 'plan_id' => null], $i));
                $this->syncRowSources($rowModel, $r);
            }

            return $type;
        });
    }

    /**
     * @param  list<array{key:string, label?:string, kind?:string, config?:array, order?:int}>  $extraRows
     * @param  array{base_level?:string, period_start?:string, period_end?:string}  $attrs
     */
    public function createPlan(int $teamId, ?int $userId, ForecastPlanType $type, string $name, ?int $orgEntityId = null, string $orgMode = 'detail', array $extraRows = [], array $attrs = []): ForecastPlan
    {
        return DB::transaction(function () use ($teamId, $userId, $type, $name, $orgEntityId, $orgMode, $extraRows, $attrs) {
            $plan = ForecastPlan::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'plan_type_id' => $type->id,
                'parent_plan_id' => $attrs['parent_plan_id'] ?? null,
                'organization_entity_id' => $orgEntityId,
                'name' => $name,
                'base_level' => $attrs['base_level'] ?? 'month',
                'period_start' => $attrs['period_start'] ?? null,
                'period_end' => $attrs['period_end'] ?? null,
                'org_mode' => $orgMode,
                'current_version' => 0,
            ]);

            foreach (array_values($extraRows) as $i => $r) {
                $rowModel = ForecastRow::create($this->rowAttributes($r, $teamId, ['plan_type_id' => null, 'plan_id' => $plan->id], $i));
                $this->syncRowSources($rowModel, $r);
            }

            $this->recordChange($plan, $userId, 'plan_create', []);

            return $plan;
        });
    }

    /** Setzt eine Zelle (value + mode) → neue Version. */
    public function setCell(ForecastPlan $plan, string $rowKey, string $bucketKey, float $value, Mode $mode, ?int $userId = null): ForecastEntry
    {
        return DB::transaction(function () use ($plan, $rowKey, $bucketKey, $value, $mode, $userId) {
            $level = TimeLevel::fromKey($bucketKey);

            $existing = ForecastEntry::where('plan_id', $plan->id)
                ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->first();

            $old = $existing
                ? ['value' => (float) $existing->value, 'mode' => $this->modeValue($existing->mode)]
                : ['value' => null, 'mode' => null];

            $entry = ForecastEntry::updateOrCreate(
                ['plan_id' => $plan->id, 'row_key' => $rowKey, 'bucket_key' => $bucketKey],
                ['team_id' => $plan->team_id, 'level' => $level->value, 'value' => $value, 'mode' => $mode->value],
            );

            $this->recordChange($plan, $userId, 'set', [
                'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'level' => $level->value,
                'old_value' => $old['value'], 'old_mode' => $old['mode'],
                'new_value' => $value, 'new_mode' => $mode->value,
            ]);

            return $entry;
        });
    }

    /** Leert eine Zelle → neue Version. */
    public function clearCell(ForecastPlan $plan, string $rowKey, string $bucketKey, ?int $userId = null): bool
    {
        return DB::transaction(function () use ($plan, $rowKey, $bucketKey, $userId) {
            $existing = ForecastEntry::where('plan_id', $plan->id)
                ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->first();

            if (! $existing) {
                return false;
            }

            $this->recordChange($plan, $userId, 'clear', [
                'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'level' => $this->levelValue($existing->level),
                'old_value' => (float) $existing->value, 'old_mode' => $this->modeValue($existing->mode),
                'new_value' => null, 'new_mode' => null,
            ]);

            $existing->delete();

            return true;
        });
    }

    /** Soft-Delete einer Planung (reversibel; Kind-Instanzen werden NICHT mitgelöscht). */
    public function deletePlan(ForecastPlan $plan, ?int $userId = null): void
    {
        DB::transaction(function () use ($plan, $userId) {
            $this->recordChange($plan, $userId, 'delete_plan', ['name' => $plan->name]);
            $plan->delete();
        });
    }

    /** Erzeugt einen benannten Snapshot des aktuellen Stands (keine neue Version). */
    public function createSnapshot(ForecastPlan $plan, string $name, ?int $userId = null, ?string $note = null): ForecastSnapshot
    {
        $payload = $plan->entries()->get(['row_key', 'bucket_key', 'level', 'value', 'mode'])
            ->map(fn ($e) => [
                'row_key' => $e->row_key,
                'bucket_key' => $e->bucket_key,
                'level' => $this->levelValue($e->level),
                'value' => (float) $e->value,
                'mode' => $this->modeValue($e->mode),
            ])->all();

        return ForecastSnapshot::create([
            'team_id' => $plan->team_id,
            'plan_id' => $plan->id,
            'user_id' => $userId,
            'name' => $name,
            'version' => $plan->current_version,
            'payload' => $payload,
            'note' => $note,
        ]);
    }

    /** Stellt den Stand eines Snapshots wieder her → neue Version. */
    public function restoreSnapshot(ForecastPlan $plan, ForecastSnapshot $snapshot, ?int $userId = null): void
    {
        DB::transaction(function () use ($plan, $snapshot, $userId) {
            $plan->entries()->delete();

            foreach ((array) $snapshot->payload as $cell) {
                ForecastEntry::create([
                    'team_id' => $plan->team_id,
                    'plan_id' => $plan->id,
                    'row_key' => $cell['row_key'],
                    'bucket_key' => $cell['bucket_key'],
                    'level' => $cell['level'],
                    'value' => $cell['value'],
                    'mode' => $cell['mode'],
                ]);
            }

            $this->recordChange($plan, $userId, 'restore', [
                'payload' => ['snapshot_uuid' => $snapshot->uuid, 'snapshot_version' => $snapshot->version],
            ]);
        });
    }

    /**
     * Baut die Attribute für eine Zeile — inkl. Einheit (per Code aufgelöst),
     * Richtung, Art und Formel-Config.
     */
    private function rowAttributes(array $r, int $teamId, array $parent, int $i): array
    {
        $unitId = null;
        if (! empty($r['unit'])) {
            $unitId = ForecastUnit::resolve((string) $r['unit'], $teamId)?->id;
        }

        $kind = $r['kind'] ?? 'input';
        $agg = $kind === 'formula' ? ($r['config']['agg'] ?? $r['agg'] ?? 'sum') : null;

        // Optionale Sektion (Zeilen-Gruppe) wandert in die config — keine Migration nötig.
        $config = $r['config'] ?? null;
        if (! empty($r['section'])) {
            $config = array_merge((array) $config, ['section' => (string) $r['section']]);
        }

        return array_merge($parent, [
            'team_id' => $teamId,
            'key' => $r['key'],
            'label' => $r['label'] ?? $r['key'],
            'kind' => $kind,
            'agg' => $agg,
            'unit_id' => $unitId,
            'direction' => $r['direction'] ?? 'neutral',
            'config' => $config,
            'order' => $r['order'] ?? $i,
        ]);
    }

    /**
     * Legt die Quell-Zeilen (forecast_row_sources) einer Formel-Zeile an.
     * Quellen: String (selbe Planung) ODER {row_key, plan_id?, weight?} (Verweis).
     */
    private function syncRowSources(ForecastRow $row, array $r): void
    {
        $sources = $r['config']['sources'] ?? $r['sources'] ?? [];
        $i = 0;
        foreach ($sources as $src) {
            if (is_array($src)) {
                // Quell-Plan per id ODER uuid (MCP-freundlich)
                $planId = $src['plan_id'] ?? null;
                if ($planId === null && ! empty($src['plan_uuid'])) {
                    $planId = ForecastPlan::where('uuid', $src['plan_uuid'])->value('id');
                }
                ForecastRowSource::create([
                    'row_id' => $row->id,
                    'source_plan_id' => $planId,
                    'source_row_key' => $src['row_key'] ?? $src['key'] ?? '',
                    'weight' => $src['weight'] ?? 1,
                    'sort_order' => $i++,
                ]);
            } else {
                ForecastRowSource::create([
                    'row_id' => $row->id,
                    'source_row_key' => (string) $src,
                    'sort_order' => $i++,
                ]);
            }
        }
    }

    private function recordChange(ForecastPlan $plan, ?int $userId, string $op, array $data): ForecastChange
    {
        $version = (int) $plan->current_version + 1;

        $change = ForecastChange::create([
            'team_id' => $plan->team_id,
            'plan_id' => $plan->id,
            'user_id' => $userId,
            'version' => $version,
            'op' => $op,
            'row_key' => $data['row_key'] ?? null,
            'bucket_key' => $data['bucket_key'] ?? null,
            'level' => $data['level'] ?? null,
            'old_value' => $data['old_value'] ?? null,
            'old_mode' => $data['old_mode'] ?? null,
            'new_value' => $data['new_value'] ?? null,
            'new_mode' => $data['new_mode'] ?? null,
            'payload' => $data['payload'] ?? null,
        ]);

        $plan->current_version = $version;
        $plan->save();

        return $change;
    }

    private function modeValue(mixed $mode): ?string
    {
        return $mode instanceof Mode ? $mode->value : ($mode !== null ? (string) $mode : null);
    }

    private function levelValue(mixed $level): ?string
    {
        return $level instanceof TimeLevel ? $level->value : ($level !== null ? (string) $level : null);
    }
}
