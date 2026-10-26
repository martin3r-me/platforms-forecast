<?php

namespace Platform\Forecast\Services;

use Illuminate\Support\Facades\DB;
use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Models\ForecastChange;
use Platform\Forecast\Models\ForecastEntry;
use Platform\Forecast\Models\ForecastEntryDetail;
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
            $data = [
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
            ];
            // Neue Spalte nur nennen, wenn gesetzt (sonst bricht Plan-Anlage ohne Migration).
            if (! empty($attrs['distribution_policy_id'])) {
                $data['distribution_policy_id'] = $attrs['distribution_policy_id'];
            }
            $plan = ForecastPlan::create($data);

            foreach (array_values($extraRows) as $i => $r) {
                $rowModel = ForecastRow::create($this->rowAttributes($r, $teamId, ['plan_type_id' => null, 'plan_id' => $plan->id], $i));
                $this->syncRowSources($rowModel, $r);
            }

            $this->recordChange($plan, $userId, 'plan_create', []);

            return $plan;
        });
    }

    /** Setzt eine Zelle (value + mode) im Kanal (plan|actual) → neue Version. */
    public function setCell(ForecastPlan $plan, string $rowKey, string $bucketKey, float $value, Mode $mode, ?int $userId = null, bool $enforceGate = false, string $channel = 'plan'): ForecastEntry
    {
        // Editier-Tor: nur für die UI (enforceGate=true); MCP/Admin schreibt frei.
        if ($enforceGate) {
            $gate = (new CellEditability())->check($plan, $rowKey, $bucketKey, $channel);
            if (! $gate['editable']) {
                throw new \DomainException($gate['reason'] ?? 'Diese Zelle ist nicht eingebbar.');
            }
        }

        // Invariante: eine aufgeschlüsselte Zelle (hat Unterzeilen) ist nicht direkt beschreibbar —
        // ihr Wert IST die Summe der Unterzeilen. Änderungen laufen über setBreakdown().
        if (ForecastEntryDetail::where('plan_id', $plan->id)
            ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)->exists()) {
            throw new \DomainException('Diese Zelle ist aufgeschlüsselt — bitte über das Aufschlüsseln-Fenster ändern.');
        }

        return DB::transaction(function () use ($plan, $rowKey, $bucketKey, $value, $mode, $userId, $channel) {
            // Plan-Zeile sperren: serialisiert konkurrierende Schreiber auf DIESELBE Planung,
            // damit der current_version-Zähler nicht per Read-Modify-Write verloren geht und
            // die Transaktion (inkl. Entry-Write) nicht durch eine Versions-Kollision zurückrollt.
            $plan = ForecastPlan::whereKey($plan->getKey())->lockForUpdate()->first() ?? $plan;

            $level = TimeLevel::fromKey($bucketKey);

            $existing = ForecastEntry::where('plan_id', $plan->id)
                ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)->first();

            $old = $existing
                ? ['value' => (float) $existing->value, 'mode' => $this->modeValue($existing->mode)]
                : ['value' => null, 'mode' => null];

            // mode=plus auf eine bereits bestehende Zelle addiert den Delta-Wert auf den
            // bisherigen Zellwert, statt ihn zu ersetzen (siehe Tool-Doku forecast.cell.PUT:
            // "plus kommt zusätzlich obendrauf"). mode=detail ersetzt weiterhin (verfeinert).
            $storedValue = ($mode === Mode::Plus && $old['value'] !== null)
                ? $old['value'] + $value
                : $value;

            $entry = ForecastEntry::updateOrCreate(
                ['plan_id' => $plan->id, 'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel],
                ['team_id' => $plan->team_id, 'level' => $level->value, 'value' => $storedValue, 'mode' => $mode->value],
            );

            $this->recordChange($plan, $userId, 'set', [
                'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel, 'level' => $level->value,
                'old_value' => $old['value'], 'old_mode' => $old['mode'],
                'new_value' => $storedValue, 'new_mode' => $mode->value,
            ]);

            return $entry;
        });
    }

    /**
     * Setzt VIELE Zellen in EINER Transaktion unter EINER Plan-Sperre — atomar und race-frei.
     * Für Importe/Paste gedacht: ersetzt viele parallele Einzel-Calls (die den Versionszähler
     * racen und Writes verlieren konnten). Jede Zelle erzeugt weiterhin einen Change-Log-Eintrag
     * (= eine Version) in Eingabereihenfolge. Fehlerhafte/gesperrte Zellen werden übersprungen
     * und gemeldet, der Rest wird geschrieben.
     *
     * @param  list<array{row_key:string, bucket_key:string, value:float|int, mode?:string}>  $cells
     * @return array{written:int, skipped:int, version:int, errors:list<array{row_key:string, bucket_key:string, reason:string}>}
     */
    public function setCells(ForecastPlan $plan, array $cells, ?int $userId = null, bool $enforceGate = false, string $channel = 'plan'): array
    {
        return DB::transaction(function () use ($plan, $cells, $userId, $enforceGate, $channel) {
            $plan = ForecastPlan::whereKey($plan->getKey())->lockForUpdate()->first() ?? $plan;
            $gate = $enforceGate ? new CellEditability() : null;

            $written = 0;
            $skipped = 0;
            $errors = [];

            foreach ($cells as $c) {
                $rowKey = (string) ($c['row_key'] ?? '');
                $bucketKey = (string) ($c['bucket_key'] ?? '');
                if ($rowKey === '' || $bucketKey === '' || ! array_key_exists('value', $c) || ! is_numeric($c['value'])) {
                    $skipped++;
                    $errors[] = ['row_key' => $rowKey, 'bucket_key' => $bucketKey, 'reason' => 'row_key, bucket_key und numerischer value sind erforderlich.'];

                    continue;
                }

                $mode = Mode::tryFrom((string) ($c['mode'] ?? 'detail')) ?? Mode::Detail;

                if ($gate) {
                    $g = $gate->check($plan, $rowKey, $bucketKey, $channel);
                    if (! $g['editable']) {
                        $skipped++;
                        $errors[] = ['row_key' => $rowKey, 'bucket_key' => $bucketKey, 'reason' => $g['reason'] ?? 'Zelle nicht eingebbar.'];

                        continue;
                    }
                }

                $level = TimeLevel::fromKey($bucketKey);
                $existing = ForecastEntry::where('plan_id', $plan->id)
                    ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)->first();
                $old = $existing
                    ? ['value' => (float) $existing->value, 'mode' => $this->modeValue($existing->mode)]
                    : ['value' => null, 'mode' => null];

                $storedValue = ($mode === Mode::Plus && $old['value'] !== null)
                    ? $old['value'] + (float) $c['value']
                    : (float) $c['value'];

                ForecastEntry::updateOrCreate(
                    ['plan_id' => $plan->id, 'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel],
                    ['team_id' => $plan->team_id, 'level' => $level->value, 'value' => $storedValue, 'mode' => $mode->value],
                );

                $this->recordChange($plan, $userId, 'set', [
                    'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel, 'level' => $level->value,
                    'old_value' => $old['value'], 'old_mode' => $old['mode'],
                    'new_value' => $storedValue, 'new_mode' => $mode->value,
                ]);

                $written++;
            }

            return ['written' => $written, 'skipped' => $skipped, 'version' => (int) $plan->current_version, 'errors' => $errors];
        });
    }

    /**
     * Settle: die jüngste Änderung einer Zelle rückgängig machen — aber NUR innerhalb des
     * Fensters (Vertipper vor dem Festschreiben). Setzt den Snapshot auf den Vorzustand zurück
     * und entfernt das Event aus dem Ledger (im Fenster gilt: keine Historie). Danach: gesperrt.
     */
    public function undoRecent(ForecastPlan $plan, string $rowKey, string $bucketKey, ?int $userId = null, int $windowSeconds = 35, string $channel = 'plan'): bool
    {
        return DB::transaction(function () use ($plan, $rowKey, $bucketKey, $windowSeconds, $channel) {
            $plan = ForecastPlan::whereKey($plan->getKey())->lockForUpdate()->first() ?? $plan;

            $change = ForecastChange::where('plan_id', $plan->id)
                ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)
                ->whereIn('op', ['set', 'clear'])
                ->orderByDesc('version')->orderByDesc('id')->first();

            if (! $change) {
                throw new \DomainException('Nichts zum Rückgängigmachen.');
            }
            if (! $change->created_at || $change->created_at->lt(now()->subSeconds($windowSeconds))) {
                throw new \DomainException('Bereits festgeschrieben — Rückgängig nicht mehr möglich.');
            }

            // Snapshot auf den Zustand VOR der Änderung.
            if ($change->old_value === null) {
                ForecastEntry::where('plan_id', $plan->id)->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)->delete();
            } else {
                $level = TimeLevel::fromKey($bucketKey);
                ForecastEntry::updateOrCreate(
                    ['plan_id' => $plan->id, 'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel],
                    ['team_id' => $plan->team_id, 'level' => $level->value, 'value' => (float) $change->old_value, 'mode' => $change->old_mode ?? Mode::Detail->value],
                );
            }

            // Event aus dem Ledger entfernen (Vertipper im Fenster → nie passiert).
            $change->delete();

            return true;
        });
    }

    /** Leert eine Zelle im Kanal → neue Version. */
    public function clearCell(ForecastPlan $plan, string $rowKey, string $bucketKey, ?int $userId = null, string $channel = 'plan'): bool
    {
        return DB::transaction(function () use ($plan, $rowKey, $bucketKey, $userId, $channel) {
            $plan = ForecastPlan::whereKey($plan->getKey())->lockForUpdate()->first() ?? $plan;

            $existing = ForecastEntry::where('plan_id', $plan->id)
                ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)->first();

            if (! $existing) {
                return false;
            }

            $this->recordChange($plan, $userId, 'clear', [
                'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel, 'level' => $this->levelValue($existing->level),
                'old_value' => (float) $existing->value, 'old_mode' => $this->modeValue($existing->mode),
                'new_value' => null, 'new_mode' => null,
            ]);

            // Unterzeilen der Zelle mit entfernen (sonst bliebe die Aufschlüsselung verwaist).
            ForecastEntryDetail::where('plan_id', $plan->id)
                ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)->delete();

            $existing->delete();

            return true;
        });
    }

    /**
     * Lädt die Unterzeilen einer Zelle (geordnet nach ordinal).
     *
     * @return list<array{id:int, label:string, value:float, ordinal:int}>
     */
    public function getBreakdown(ForecastPlan $plan, string $rowKey, string $bucketKey, string $channel = 'plan'): array
    {
        return ForecastEntryDetail::where('plan_id', $plan->id)
            ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)
            ->orderBy('ordinal')->orderBy('id')
            ->get(['id', 'label', 'value', 'ordinal'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'label' => (string) $d->label, 'value' => (float) $d->value, 'ordinal' => (int) $d->ordinal])
            ->all();
    }

    /**
     * Ersetzt die Unterzeilen einer Zelle durch die übergebene Liste und hält den Zellwert in
     * forecast_entries synchron = Σ der Unterzeilen (gespeichert als Mode::Detail). Eine leere
     * Liste hebt die Aufschlüsselung auf: Unterzeilen werden entfernt, der zuletzt errechnete
     * Summenwert bleibt als Direktwert stehen und die Zelle ist wieder frei tippbar.
     *
     * Reconciliation/Zeitverteilung/Rollup/Export bleiben unberührt — sie sehen weiter nur den
     * fertigen Zellwert.
     *
     * @param  list<array{label?:string, value?:float|int}>  $items
     * @return array{items:list<array{label:string, value:float, ordinal:int}>, sum:float, count:int}
     */
    public function setBreakdown(ForecastPlan $plan, string $rowKey, string $bucketKey, array $items, ?int $userId = null, bool $enforceGate = false, string $channel = 'plan'): array
    {
        if ($enforceGate) {
            $gate = (new CellEditability())->check($plan, $rowKey, $bucketKey, $channel);
            // 'breakdown' = bereits aufgeschlüsselt → Bearbeiten erlaubt; sonst muss die Zelle offen sein.
            if (! $gate['editable'] && $gate['state'] !== 'breakdown') {
                throw new \DomainException($gate['reason'] ?? 'Diese Zelle ist nicht eingebbar.');
            }
        }

        return DB::transaction(function () use ($plan, $rowKey, $bucketKey, $items, $userId, $channel) {
            $plan = ForecastPlan::whereKey($plan->getKey())->lockForUpdate()->first() ?? $plan;
            $level = TimeLevel::fromKey($bucketKey);

            // Sanitisieren: leere Labels raus, auf 6 Nachkommastellen runden, Reihenfolge = ordinal.
            $clean = [];
            foreach (array_values($items) as $i => $it) {
                $label = trim((string) ($it['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $clean[] = ['label' => $label, 'value' => round((float) ($it['value'] ?? 0), 6), 'ordinal' => $i];
            }

            $oldEntry = ForecastEntry::where('plan_id', $plan->id)
                ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)->first();
            $oldValue = $oldEntry ? (float) $oldEntry->value : null;

            // Unterzeilen vollständig ersetzen.
            ForecastEntryDetail::where('plan_id', $plan->id)
                ->where('row_key', $rowKey)->where('bucket_key', $bucketKey)->where('channel', $channel)->delete();

            $sum = 0.0;
            foreach ($clean as $c) {
                ForecastEntryDetail::create([
                    'team_id' => $plan->team_id, 'plan_id' => $plan->id,
                    'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel,
                    'level' => $level->value, 'label' => $c['label'], 'value' => $c['value'], 'ordinal' => $c['ordinal'],
                ]);
                $sum += $c['value'];
            }
            $sum = round($sum, 6);

            // Zellwert synchron pflegen. Bei leerer Liste bleibt der bisherige Direktwert stehen.
            if ($clean !== []) {
                ForecastEntry::updateOrCreate(
                    ['plan_id' => $plan->id, 'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel],
                    ['team_id' => $plan->team_id, 'level' => $level->value, 'value' => $sum, 'mode' => Mode::Detail->value],
                );
            }

            $this->recordChange($plan, $userId, 'breakdown', [
                'row_key' => $rowKey, 'bucket_key' => $bucketKey, 'channel' => $channel, 'level' => $level->value,
                'old_value' => $oldValue, 'old_mode' => Mode::Detail->value,
                'new_value' => $clean !== [] ? $sum : $oldValue, 'new_mode' => Mode::Detail->value,
                'payload' => ['breakdown' => $clean],
            ]);

            return ['items' => $clean, 'sum' => $sum, 'count' => count($clean)];
        });
    }

    /**
     * Die Zellen (als "row_key|bucket_key"), die im Plan/Kanal aufgeschlüsselt sind —
     * für die UI (Marker + Read-only), in EINER Abfrage.
     *
     * @return list<string>
     */
    public function breakdownCells(ForecastPlan $plan, string $channel = 'plan'): array
    {
        return ForecastEntryDetail::where('plan_id', $plan->id)->where('channel', $channel)
            ->select('row_key', 'bucket_key')->distinct()
            ->get()
            ->map(fn ($d) => $d->row_key.'|'.$d->bucket_key)
            ->all();
    }

    /** Soft-Delete einer Planung (reversibel; Kind-Instanzen werden NICHT mitgelöscht). */
    public function deletePlan(ForecastPlan $plan, ?int $userId = null): void
    {
        DB::transaction(function () use ($plan, $userId) {
            $this->recordChange($plan, $userId, 'delete_plan', ['name' => $plan->name]);
            $plan->delete();
        });
    }

    /** Soft-Delete eines Planungs-Typs samt seiner Zeilen (reversibel). Pläne bleiben unberührt. */
    public function deletePlanType(ForecastPlanType $type): void
    {
        DB::transaction(function () use ($type) {
            $type->rows()->delete();
            $type->delete();
        });
    }

    /**
     * Aktualisiert Stammdaten einer Typ-Zeile (label/unit/direction/order) — KEIN Update von
     * kind/agg/sources, das bliebe strukturell inkonsistent zu bereits erzeugten Plan-Instanzen.
     * Betrifft die Vorlagen-Zeile selbst; bestehende Plan-Instanzen referenzieren row_key, nicht
     * die Zeile direkt, daher wirkt die Änderung sofort auf alle Instanzen dieses Typs.
     */
    public function updateTypeRow(ForecastRow $row, array $attrs): ForecastRow
    {
        if (array_key_exists('label', $attrs) && $attrs['label'] !== null) {
            $row->label = (string) $attrs['label'];
        }
        if (array_key_exists('direction', $attrs) && $attrs['direction'] !== null) {
            $row->direction = $attrs['direction'];
        }
        if (array_key_exists('unit_id', $attrs) && $attrs['unit_id'] !== null) {
            $row->unit_id = $attrs['unit_id'];
        }
        if (array_key_exists('order', $attrs) && $attrs['order'] !== null) {
            $row->order = (int) $attrs['order'];
        }
        $row->save();

        return $row;
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
            $plan = ForecastPlan::whereKey($plan->getKey())->lockForUpdate()->first() ?? $plan;

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

        // Optionale Sektion + Bezugsgrößen-Quote wandern in die config — keine Migration nötig.
        $config = $r['config'] ?? null;
        if (! empty($r['section'])) {
            $config = array_merge((array) $config, ['section' => (string) $r['section']]);
        }
        if (! empty($r['quote_basis'])) {
            $config = array_merge((array) $config, ['quote_basis' => (string) $r['quote_basis']]);
        }
        if (! empty($r['time_agg']) && in_array($r['time_agg'], ['flow', 'stock', 'stock_open', 'avg', 'recompute', 'wavg'], true)) {
            $config = array_merge((array) $config, ['time_agg' => (string) $r['time_agg']]);
        }
        if (! empty($r['weight_by'])) {
            $config = array_merge((array) $config, ['weight_by' => (string) $r['weight_by']]);
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
            'channel' => $data['channel'] ?? 'plan',
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
