<?php

namespace Platform\Forecast\Enums;

/**
 * Art einer Planungs-Zeile.
 *
 * - Input:   freie Eingabe (Euro-/Mengen-Zahl je Zeit-Bucket)
 * - Formula: read-only, aggregiert ANDERE Zeilen je Bucket. Generisch über
 *            config: ['agg' => 'sum|net|avg|median|min|max|count|product',
 *                     'sources' => [rowKey, ...]]
 *
 * (Sum/Percent/Reference bleiben als Spezialfälle erhalten; neue generische
 *  Aggregationen laufen über Formula + config.agg.)
 */
enum RowKind: string
{
    case Input = 'input';
    case Formula = 'formula';
    case Sum = 'sum';
    case Percent = 'percent';
    case Reference = 'reference';
}
