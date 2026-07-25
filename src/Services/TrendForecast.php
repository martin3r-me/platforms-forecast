<?php

namespace Platform\Forecast\Services;

/**
 * Auto-Forecast: projiziert eine bekannte Zeitreihe (Ist bevorzugt) in zukünftige Buckets.
 * Rein rechnerisch, zustandslos — testbar ohne DB.
 *
 * Methoden:
 *   run_rate — Ø der letzten N bekannten Werte, konstant fortgeschrieben.
 *   growth   — geometrisches Ø-Wachstum der Historie: v_n = letzter · ratioⁿ.
 *   linear   — lineare Regression über die Historie, extrapoliert.
 */
final class TrendForecast
{
    /**
     * @param  array<string, float>  $known    bucket_key => Wert (nur bekannte Perioden derselben Ebene)
     * @param  list<string>          $targets  zu projizierende (zukünftige) bucket_keys
     * @param  string                $method   run_rate | growth | linear
     * @param  int                   $window   run_rate: letzte N bekannten Werte (0 = alle)
     * @return array<string, float>  target bucket_key => projizierter Wert (gerundet auf 4)
     */
    public function project(array $known, array $targets, string $method = 'run_rate', int $window = 3): array
    {
        // Bekannte Reihe chronologisch (gleiche Ebene → String-Sortierung passt) + Ziele deduplizieren/sortieren.
        ksort($known);
        $values = array_values($known);
        $targets = array_values(array_unique($targets));
        sort($targets);

        $out = [];
        if ($values === [] || $targets === []) {
            return $out;
        }

        $n = count($values);
        $last = $values[$n - 1];

        if ($method === 'growth') {
            $ratio = $this->geometricGrowth($values);
            foreach ($targets as $i => $b) {
                $out[$b] = round($last * ($ratio ** ($i + 1)), 4);
            }

            return $out;
        }

        if ($method === 'linear') {
            [$slope, $intercept] = $this->linearFit($values);
            foreach ($targets as $i => $b) {
                // Position nach der letzten bekannten (0-basiert): n, n+1, …
                $out[$b] = round($intercept + $slope * ($n + $i), 4);
            }

            return $out;
        }

        // run_rate (Default): Ø der letzten $window bekannten Werte, konstant.
        $tail = $window > 0 ? array_slice($values, -$window) : $values;
        $avg = array_sum($tail) / max(1, count($tail));
        foreach ($targets as $b) {
            $out[$b] = round($avg, 4);
        }

        return $out;
    }

    /** Geometrisches Ø der Perioden-Verhältnisse v[i]/v[i-1] (nur positive Paare); 1.0 wenn nicht bestimmbar. */
    private function geometricGrowth(array $values): float
    {
        $logs = [];
        for ($i = 1, $c = count($values); $i < $c; $i++) {
            $a = $values[$i - 1];
            $b = $values[$i];
            if ($a > 0 && $b > 0) {
                $logs[] = log($b / $a);
            }
        }
        if ($logs === []) {
            return 1.0;
        }

        return exp(array_sum($logs) / count($logs));
    }

    /** Kleinste-Quadrate-Gerade über (Index, Wert). @return array{0: float, 1: float} [slope, intercept] */
    private function linearFit(array $values): array
    {
        $n = count($values);
        if ($n === 1) {
            return [0.0, $values[0]];
        }
        $sx = $sy = $sxx = $sxy = 0.0;
        foreach ($values as $i => $v) {
            $sx += $i;
            $sy += $v;
            $sxx += $i * $i;
            $sxy += $i * $v;
        }
        $den = $n * $sxx - $sx * $sx;
        if ($den == 0.0) {
            return [0.0, $sy / $n];
        }
        $slope = ($n * $sxy - $sx * $sy) / $den;
        $intercept = ($sy - $slope * $sx) / $n;

        return [$slope, $intercept];
    }
}
