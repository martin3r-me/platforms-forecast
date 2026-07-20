<?php

namespace Platform\Forecast\Enums;

/**
 * Richtung einer Zeile — drei-wertig. Betrag bleibt positiv im Modell,
 * das Vorzeichen ist Anzeige- und Aggregations-Sache.
 *
 * - Income  (+): Ertrag
 * - Expense (−): Aufwand
 * - Neutral    : Messgröße ohne Vorzeichen (Stück, FTE, %, Köpfe) — fließt nicht
 *                ins €-Ergebnis, wird nur in eigener Einheit betrachtet.
 */
enum Direction: string
{
    case Income = 'income';
    case Expense = 'expense';
    case Neutral = 'neutral';

    public function sign(): int
    {
        return match ($this) {
            self::Income => 1,
            self::Expense => -1,
            self::Neutral => 0,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Income => '+',
            self::Expense => '−',
            self::Neutral => '',
        };
    }
}
