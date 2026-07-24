<?php

namespace Platform\Forecast\Enums;

/**
 * Ebene im Zeit-Baum. Wochen bewusst NICHT (nisten nicht sauber).
 *
 * Bucket-Adressen (kanonisch):
 *   Year     "2026"
 *   HalfYear "2026-H1"
 *   Quarter  "2026-Q3"
 *   Month    "2026-07"
 *   Day      "2026-07-12"
 *   Hour     "2026-07-12T14"
 */
enum TimeLevel: string
{
    case Year = 'year';
    case HalfYear = 'half';
    case Quarter = 'quarter';
    case Month = 'month';
    case Day = 'day';
    case Hour = 'hour';

    /** Leitet die Ebene aus einer Bucket-Adresse ab. */
    public static function fromKey(string $key): self
    {
        if (str_contains($key, 'T')) {
            return self::Hour;
        }
        if (str_contains($key, 'H')) {
            return self::HalfYear;
        }
        if (str_contains($key, 'Q')) {
            return self::Quarter;
        }
        return match (substr_count($key, '-')) {
            0 => self::Year,
            1 => self::Month,
            default => self::Day,
        };
    }
}
