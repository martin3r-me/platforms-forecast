<?php

namespace Platform\Forecast\Services;

use Illuminate\Support\Carbon;
use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Reconciliation\TimeAxis;

/**
 * Zeitfenster-Sperre — systemseitig, wiederverwendbar (UI, MCP, Exporte).
 * Vergangenheit zu · Vorlauf öffnet vor Start · Nachlauf hält nach Ende offen.
 * Entscheidung auf period_level, feinere Buckets erben (Kaskade), gröbere = mixed.
 */
final class LockService
{
    /**
     * @param  array{period_level:string, lead_days:int, grace_days:int}  $rule
     * @return array{state:string, days:?int}
     */
    public static function status(string $bucket, array $rule, Carbon $now): array
    {
        $periodRank = self::levelRank((string) $rule['period_level']);
        $colRank = self::levelRank(TimeLevel::fromKey($bucket)->value);

        if ($colRank < $periodRank) {
            return ['state' => 'mixed', 'days' => null];
        }

        $governing = self::governingBucket($bucket, $periodRank);
        [$start, $end] = self::bucketRange($governing);
        $opensAt = $start->copy()->subDays((int) $rule['lead_days']);
        $closesAt = $end->copy()->addDays((int) $rule['grace_days']);

        if ($now->gt($closesAt)) {
            return ['state' => 'closed', 'days' => null];
        }
        if ($now->lt($opensAt)) {
            return ['state' => 'pending', 'days' => (int) ceil(($opensAt->timestamp - $now->timestamp) / 86400)];
        }

        return ['state' => 'open', 'days' => (int) ceil(($closesAt->timestamp - $now->timestamp) / 86400)];
    }

    public static function levelRank(string $level): int
    {
        return ['year' => 0, 'half' => 1, 'quarter' => 2, 'month' => 3, 'day' => 4, 'hour' => 5][$level] ?? 3;
    }

    /** Nächst-höherer Bucket auf der Perioden-Ebene (Kaskade nach unten). */
    public static function governingBucket(string $bucket, int $periodRank): string
    {
        $k = $bucket;
        while ($k !== null && self::levelRank(TimeLevel::fromKey($k)->value) > $periodRank) {
            $k = TimeAxis::parentKey($k);
        }

        return $k ?? $bucket;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function bucketRange(string $bucket): array
    {
        return match (TimeLevel::fromKey($bucket)) {
            TimeLevel::Year => [Carbon::create((int) $bucket, 1, 1)->startOfYear(), Carbon::create((int) $bucket, 1, 1)->endOfYear()],
            TimeLevel::HalfYear => (function () use ($bucket) {
                [$y, $h] = explode('-H', $bucket);
                $s = Carbon::create((int) $y, ((int) $h - 1) * 6 + 1, 1)->startOfMonth();
                return [$s, $s->copy()->addMonths(5)->endOfMonth()];
            })(),
            TimeLevel::Quarter => (function () use ($bucket) {
                [$y, $q] = explode('-Q', $bucket);
                $s = Carbon::create((int) $y, ((int) $q - 1) * 3 + 1, 1)->startOfMonth();
                return [$s, $s->copy()->addMonths(2)->endOfMonth()];
            })(),
            TimeLevel::Month => [Carbon::createFromFormat('Y-m', $bucket)->startOfMonth(), Carbon::createFromFormat('Y-m', $bucket)->endOfMonth()],
            TimeLevel::Day => [Carbon::createFromFormat('Y-m-d', $bucket)->startOfDay(), Carbon::createFromFormat('Y-m-d', $bucket)->endOfDay()],
            TimeLevel::Hour => (function () use ($bucket) {
                $day = substr($bucket, 0, (int) strpos($bucket, 'T'));
                $h = (int) substr($bucket, (int) strpos($bucket, 'T') + 1);
                $s = Carbon::createFromFormat('Y-m-d', $day)->startOfDay()->addHours($h);
                return [$s, $s->copy()->endOfHour()];
            })(),
        };
    }
}
