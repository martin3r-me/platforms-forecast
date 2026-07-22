<?php

namespace Platform\Forecast\Services;

use Platform\Forecast\Enums\RowKind;
use Platform\Forecast\Models\ForecastPlan;

/**
 * Das Editier-Tor — die EINE Wahrheit, ob eine Zelle (plan, row, bucket) JETZT geschrieben
 * werden darf. Dieselbe Klassifizierung, die die UI als Feld-Zustand anzeigt:
 *
 *   open      → eingebbar (Eingabe-Zeile · Blatt · Periode offen)
 *   computed  → Formel, ergibt sich → nie eingebbar
 *   derived   → Ordner: Wert kommt aus den Kindern → hier nicht eingebbar
 *   locked    → Periode geschlossen/ausstehend bzw. gröber als die Sperr-Ebene
 *
 * Genutzt vom Schreibpfad (Durchsetzung) UND von der UI (Anzeige) — kein Auseinanderdriften
 * von „sieht eingebbar aus" und „darf eingegeben werden".
 */
final class CellEditability
{
    /**
     * @return array{editable: bool, state: string, reason: ?string}
     */
    public function check(ForecastPlan $plan, string $rowKey, string $bucketKey): array
    {
        $row = null;
        foreach ($plan->resolvedRows() as $r) {
            if ($r->key === $rowKey) {
                $row = $r;
                break;
            }
        }
        if ($row === null) {
            return ['editable' => false, 'state' => 'unknown', 'reason' => 'Zeile nicht gefunden.'];
        }

        // Formel/Verweis → berechnet
        if ($row->kind === RowKind::Formula) {
            return ['editable' => false, 'state' => 'computed', 'reason' => 'Berechnete Zeile (Formel) — ergibt sich aus anderen Zeilen.'];
        }

        // Ordner → abgeleitet (Werte kommen aus den untergeordneten Planungen hoch)
        if (ForecastPlan::where('parent_plan_id', $plan->id)->exists()) {
            return ['editable' => false, 'state' => 'derived', 'reason' => 'Ordner — Werte kommen aus den untergeordneten Planungen.'];
        }

        // Sperre: nur offene Perioden (auf/unter der Sperr-Ebene) sind eingebbar.
        $lock = (new PlanAnalyzer())->lockRule($plan);
        $status = LockService::status($bucketKey, $lock, now());
        if ($status['state'] !== 'open') {
            return [
                'editable' => false,
                'state' => 'locked',
                'reason' => match ($status['state']) {
                    'closed' => 'Periode ist geschlossen.',
                    'pending' => 'Periode ist noch nicht offen'.($status['days'] !== null ? " (öffnet in {$status['days']} T)" : '').'.',
                    'mixed' => 'Gröber als die Sperr-Ebene — bitte auf der Erfassungs-Ebene eingeben.',
                    default => 'Periode nicht offen.',
                },
            ];
        }

        return ['editable' => true, 'state' => 'open', 'reason' => null];
    }
}
