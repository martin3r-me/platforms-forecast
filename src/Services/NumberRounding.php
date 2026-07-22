<?php

namespace Platform\Forecast\Services;

/**
 * Rundung, die aufgeht — Largest-Remainder-Verfahren (Hamilton).
 *
 * Rundet eine Menge von Werten auf N Nachkommastellen so, dass die Summe der gerundeten
 * Teile EXAKT der gerundeten Summe entspricht (kein „99,99 statt 100"-Effekt). Der durch
 * das Abrunden entstehende Rest wird den Werten mit den größten Nachkommaresten zugeteilt.
 * Funktioniert auch mit negativen Werten.
 */
final class NumberRounding
{
    /**
     * @param  list<float>  $values
     * @return list<float>  gerundet, Σ = round(Σ values, $decimals); Reihenfolge/Keys wie Eingabe
     */
    public static function largestRemainder(array $values, int $decimals = 0): array
    {
        if ($values === []) {
            return [];
        }

        $f = 10 ** max(0, $decimals);
        $scaled = array_map(static fn ($v) => (float) $v * $f, $values);
        $floors = array_map('floor', $scaled);
        $target = (int) round(array_sum($scaled));
        $diff = $target - (int) array_sum($floors);

        $rem = [];
        foreach ($scaled as $k => $s) {
            $rem[$k] = $s - $floors[$k]; // ∈ [0, 1)
        }
        $keys = array_keys($rem);
        // diff>0: die größten Reste zuerst aufrunden; diff<0: die kleinsten abrunden.
        usort($keys, static fn ($a, $b) => $diff >= 0 ? ($rem[$b] <=> $rem[$a]) : ($rem[$a] <=> $rem[$b]));

        $result = $floors;
        $step = $diff >= 0 ? 1 : -1;
        $n = min(abs($diff), count($keys));
        for ($i = 0; $i < $n; $i++) {
            $result[$keys[$i]] += $step;
        }

        return array_map(static fn ($r) => $r / $f, $result);
    }
}
