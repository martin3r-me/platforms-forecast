<?php

namespace Platform\Forecast\Services;

/**
 * Generische Aggregation über andere Zellen — der EINE systemseitige Rechenweg,
 * genutzt von PlanReconciler (Systemsicht/MCP) und PlanView (Anzeige).
 */
final class Aggregation
{
    /**
     * @param  list<float>   $vals
     * @param  list<string>  $dirs  Richtungen der Quellen (für "net")
     */
    public static function aggregate(string $fn, array $vals, array $dirs = []): float
    {
        if (empty($vals)) {
            return 0.0;
        }

        return match ($fn) {
            'net' => array_sum(array_map(
                fn ($v, $d) => $d === 'expense' ? -$v : ($d === 'neutral' ? 0 : $v),
                $vals,
                $dirs + array_fill(0, count($vals), 'income'),
            )),
            'ratio' => (($vals[1] ?? 0) != 0) ? ($vals[0] ?? 0) / $vals[1] * 100 : 0.0,
            'avg' => array_sum($vals) / count($vals),
            'median' => self::median($vals),
            'min' => min($vals),
            'max' => max($vals),
            'count' => (float) count(array_filter($vals, fn ($v) => $v != 0)),
            'product' => array_product($vals),
            default => array_sum($vals), // sum
        };
    }

    /** @param list<float> $vals */
    public static function median(array $vals): float
    {
        sort($vals);
        $n = count($vals);
        $mid = intdiv($n, 2);

        return $n % 2 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
    }

    public static function label(string $agg): string
    {
        return match ($agg) {
            'net' => '± Netto',
            'ratio' => '% Marge',
            'avg' => 'Ø Mittel',
            'median' => 'Median',
            'min' => 'Min',
            'max' => 'Max',
            'count' => 'Anzahl',
            'product' => '∏ Produkt',
            default => 'Σ Summe',
        };
    }

    /** Vorzeichen-Modus einer Zeile: 'net' (Wert-Vorzeichen) für net/ratio, sonst 'direction'. */
    public static function signMode(bool $isFormula, string $agg): string
    {
        return ($isFormula && in_array($agg, ['net', 'ratio'], true)) ? 'net' : 'direction';
    }
}
