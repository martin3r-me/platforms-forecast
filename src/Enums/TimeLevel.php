<?php

namespace Platform\Forecast\Enums;

/**
 * Ebene im Zeit-Baum. Wochen bewusst NICHT (nisten nicht sauber).
 *
 * Bucket-Adressen (kanonisch):
 *   Year   "2026"
 *   Month  "2026-07"
 *   Day    "2026-07-12"
 *   Hour   "2026-07-12T14"
 */
enum TimeLevel: string
{
    case Year = 'year';
    case Month = 'month';
    case Day = 'day';
    case Hour = 'hour';

    /** Leitet die Ebene aus einer Bucket-Adresse ab. */
    public static function fromKey(string $key): self
    {
        if (str_contains($key, 'T')) {
            return self::Hour;
        }
        return match (substr_count($key, '-')) {
            0 => self::Year,
            1 => self::Month,
            default => self::Day,
        };
    }
}
