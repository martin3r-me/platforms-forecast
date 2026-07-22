<?php

namespace Platform\Forecast\Reconciliation;

/**
 * Baut aus dünn besetzten ("sparse") Eingaben den Zeit-Baum und reconciled ihn.
 *
 * Ebenen (Wochen bewusst NICHT — sie nisten nicht sauber):
 *   Jahr   "2026"
 *   Monat  "2026-07"
 *   Tag    "2026-07-12"
 *   Stunde "2026-07-12T14"
 *
 * Der Baum ist sparse: es existieren nur Knoten, für die tatsächlich etwas
 * eingetragen wurde (+ ihre Eltern). Alles andere ist impliziter Rest.
 * Zwischenknoten ohne eigene Eingabe rollen als Detail nach oben.
 */
final class TimeAxis
{
    /**
     * @param  list<array{key:string, value:float, mode:Mode}>  $entries
     * @param  string  $timeAgg  Zeit-Aggregation der Zeile (flow|stock|stock_open|avg)
     * @return array<string, Node>  Knoten-Map (bucketKey => Node), inkl. Wurzel(n)
     */
    public static function build(array $entries, string $timeAgg = 'flow'): array
    {
        /** @var array<string, Node> $nodes */
        $nodes = [];
        /** @var array<string, Mode> $modeToParent */
        $modeToParent = [];

        // 1) Explizit eingegebene Modi vormerken (vor dem Verdrahten).
        foreach ($entries as $e) {
            $modeToParent[$e['key']] = $e['mode'];
        }

        // 2) Knoten (inkl. Elternkette) rekursiv sicherstellen und verdrahten.
        $ensure = function (string $key) use (&$nodes, &$modeToParent, &$ensure, $timeAgg): Node {
            if (isset($nodes[$key])) {
                return $nodes[$key];
            }
            $node = new Node($key);
            $node->timeAgg = $timeAgg;
            $nodes[$key] = $node;

            // Default für nicht explizit eingegebene (Zwischen-)Knoten: Detail.
            $modeToParent[$key] ??= Mode::Detail;

            $parentKey = self::parentKey($key);
            if ($parentKey !== null) {
                $ensure($parentKey)->addChild($node, $modeToParent[$key]);
            }
            return $node;
        };

        // 3) Schätzungen setzen (mehrere Eingaben in derselben Zelle: summieren).
        foreach ($entries as $e) {
            $node = $ensure($e['key']);
            $node->estimate = ($node->estimate ?? 0.0) + $e['value'];
        }

        return $nodes;
    }

    /** Elternschlüssel einer Zeit-Bucket-Adresse ableiten (null = Wurzel/Jahr). */
    public static function parentKey(string $key): ?string
    {
        // Stunde "Y-M-DTH" → Tag "Y-M-D"
        if (str_contains($key, 'T')) {
            return substr($key, 0, (int) strpos($key, 'T'));
        }
        // Quartal "Y-Qn" → Jahr "Y"
        if (str_contains($key, 'Q')) {
            return explode('-', $key)[0];
        }
        $parts = explode('-', $key);
        return match (count($parts)) {
            3 => $parts[0].'-'.$parts[1],                       // Tag → Monat
            2 => $parts[0].'-Q'.(int) ceil(((int) $parts[1]) / 3), // Monat → Quartal
            default => null,                                    // Jahr → Wurzel
        };
    }
}
